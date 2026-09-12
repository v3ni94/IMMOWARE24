<?php

declare(strict_types=1);

namespace App\Modules\Cases\Services;

use App\Modules\Cases\DTO\AssignmentCandidate;
use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Models\AssignmentDecision;
use App\Modules\Cases\Models\AssignmentRule;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\ContactIdentifier;
use App\Modules\Estate\Models\Contract;
use App\Modules\Estate\Models\ContractParty;
use App\Modules\Estate\Models\Property;
use App\Modules\Estate\Models\Unit;
use App\Modules\Gmail\Models\MailMessage;
use App\Modules\Security\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Zuordnung eines Vorgangs zu Kontakt, Objekt, Einheit und Vertrag innerhalb der Organisation. Nur lesender Zugriff
 * auf die Spiegeldaten des Hubs. Kriterien: E-Mail exakt (Kontaktkennung), bestätigte Absenderregel, Kundennummer
 * oder externe ID im Text, Objektadresse im Text, Vertragspartei. Nie über Namen. Mehrdeutigkeit ergibt
 * assignment_open; manuelle Bestätigungen werden als Entscheidung gespeichert und zu Regeln (kein Modelltraining).
 */
final class AssignmentService
{
    public function __construct(
        private readonly CaseStatusLogger $log,
        private readonly Repository $config,
    ) {}

    /**
     * @return array<int, AssignmentCandidate>
     */
    public function candidates(MailMessage $message): array
    {
        $organizationId = (int) $message->organization_id;
        $email = mb_strtolower(trim((string) $message->from_address));
        $text = mb_strtolower((string) $message->subject.' '.(string) ($message->body_text ?? $message->snippet ?? ''));
        $candidates = [];

        foreach ($this->rules($organizationId, $email) as $rule) {
            $candidates[] = new AssignmentCandidate((string) $rule->target_type, (int) $rule->target_local_id, 'assignment_rule', sprintf('Bestätigter Absender (%d Bestätigungen).', (int) $rule->confirmed_count), (int) $rule->confidence);
        }

        foreach ($this->contactsByEmail($organizationId, $email) as $contact) {
            $candidates[] = new AssignmentCandidate('contact', (int) $contact->getKey(), 'identifier_email', 'E-Mail-Adresse exakt in den Kontaktkennungen.', 95, $this->contactLabel($contact));
        }

        $tokens = $this->tokens($text);

        if ($tokens !== []) {
            foreach (Property::query()->allOrganizations()->where('organization_id', $organizationId)->whereNull('deleted_at')->whereIn('immoware_object_number', $tokens)->get() as $property) {
                $candidates[] = new AssignmentCandidate('property', (int) $property->getKey(), 'external_id_in_text', sprintf('Objektnummer %s im Text.', (string) $property->immoware_object_number), 85, (string) $property->name);
            }

            foreach (Contract::query()->allOrganizations()->where('organization_id', $organizationId)->whereNull('deleted_at')->whereIn('contract_number', $tokens)->get() as $contract) {
                $candidates[] = new AssignmentCandidate('contract', (int) $contract->getKey(), 'external_id_in_text', sprintf('Vertragsnummer %s im Text.', (string) $contract->contract_number), 85);
                $candidates[] = new AssignmentCandidate('unit', (int) $contract->unit_id, 'external_id_in_text', sprintf('Einheit des Vertrags %s.', (string) $contract->contract_number), 80);
            }

            $identifiers = ContactIdentifier::query()
                ->where('kind', '!=', 'email')
                ->where('kind', '!=', 'phone')
                ->whereIn('value_normalized', $tokens)
                ->get();

            foreach ($identifiers as $identifier) {
                $contact = Contact::query()->allOrganizations()->where('organization_id', $organizationId)->whereNull('deleted_at')->whereNull('merged_into_id')->find($identifier->contact_id);

                if ($contact instanceof Contact) {
                    $candidates[] = new AssignmentCandidate('contact', (int) $contact->getKey(), 'external_id_in_text', sprintf('Kennung %s (%s) im Text.', (string) $identifier->value_normalized, (string) $identifier->kind), 85, $this->contactLabel($contact));
                }
            }
        }

        foreach ($this->propertiesByAddress($organizationId, $text) as [$property, $reason]) {
            $candidates[] = new AssignmentCandidate('property', (int) $property->getKey(), 'property_address', $reason, 80, (string) $property->name);
        }

        $candidates = $this->enrichWithContracts($organizationId, $candidates);

        return $this->dedupe($candidates);
    }

    /**
     * Wendet die Kandidaten auf den Vorgang an: eindeutige Kennung oder bestätigte Regel ergibt automatische
     * Zuordnung, Mehrdeutigkeit ergibt assignment_open. Liefert assigned, open oder none.
     */
    public function apply(MailCase $case, MailMessage $message, ?int $actorId = null): string
    {
        $candidates = $this->candidates($message);
        $contacts = array_values(array_filter($candidates, static fn (AssignmentCandidate $c): bool => $c->type === 'contact'));
        $auto = (int) $this->config->get('hub.cases.assignment.auto_confidence', 90);
        $outcome = 'none';

        $top = $this->topOf($contacts);

        if ($top !== []) {
            $strong = array_values(array_filter($top, static fn (AssignmentCandidate $c): bool => in_array($c->source, ['identifier_email', 'assignment_rule'], true)));

            if (count($top) === 1 && $top[0]->confidence >= $auto && $strong !== []) {
                $this->record($case, $message, 'contact', $top[0], $top[0]->source === 'assignment_rule' ? 'rule' : 'identifier', null);
                $case->forceFill(['primary_contact_id' => $top[0]->localId])->save();
                $outcome = 'assigned';
            } else {
                $outcome = 'open';
            }
        }

        foreach (['property', 'unit', 'contract'] as $type) {
            $ofType = array_values(array_filter($candidates, static fn (AssignmentCandidate $c): bool => $c->type === $type));
            $topOfType = $this->topOf($ofType);

            if (count($topOfType) === 1 && $topOfType[0]->confidence >= 80 && $case->getAttribute($type.'_id') === null) {
                $case->forceFill([$type.'_id' => $topOfType[0]->localId])->save();
                $this->record($case, $message, $type, $topOfType[0], 'identifier', null);
            } elseif (count($topOfType) > 1 && $outcome !== 'open') {
                $outcome = 'open';
            }
        }

        if ($outcome === 'open' && $this->status($case) !== CaseStatus::AssignmentOpen && $this->status($case)->canTransitionTo(CaseStatus::AssignmentOpen)) {
            $from = $this->status($case);
            $case->forceFill(['status_processing' => CaseStatus::AssignmentOpen->value])->save();
            $this->log->log($case, 'assignment', $from->value, CaseStatus::AssignmentOpen->value, 'Zuordnung mehrdeutig, manuelle Bestätigung erforderlich.', $actorId, null, 'system', ['candidates' => array_map(static fn (AssignmentCandidate $c): array => $c->toArray(), $candidates)]);
        } elseif ($outcome === 'none') {
            $this->log->log($case, 'assignment', null, 'no_candidates', 'Keine Zuordnungskandidaten gefunden.', $actorId, null, 'system');
        }

        return $outcome;
    }

    /**
     * Manuelle Bestätigung: Entscheidung speichern, Vorgang setzen, Absenderregel anlegen oder bestätigen, alte
     * widersprechende Regeln deaktivieren. Korrigierbar durch erneuten Aufruf.
     */
    public function confirm(MailCase $case, string $decisionType, int $localId, User $actor, ?MailMessage $message = null, string $basis = 'manual'): AssignmentDecision
    {
        if (! in_array($decisionType, ['contact', 'property', 'unit', 'contract', 'team', 'assignee'], true)) {
            throw new InvalidArgumentException(sprintf('Unbekannter Entscheidungstyp %s.', $decisionType));
        }

        if (! in_array($basis, ['manual', 'ai_confirmed'], true)) {
            throw new InvalidArgumentException('Manuelle Bestätigung nur mit Basis manual oder ai_confirmed.');
        }

        $this->assertInOrganization($case, $decisionType, $localId);

        $field = match ($decisionType) {
            'contact' => 'primary_contact_id',
            'assignee' => 'assignee_user_id',
            default => $decisionType.'_id',
        };
        $previous = $case->getAttribute($field);

        $decision = AssignmentDecision::query()->create([
            'case_id' => $case->getKey(),
            'message_id' => $message?->getKey(),
            'decision_type' => $decisionType,
            'proposed_value_json' => ['previous' => $previous],
            'chosen_value' => (string) $localId,
            'chosen_local_id' => $localId,
            'decided_by' => $actor->getKey(),
            'decision_basis' => $basis,
            'decided_at' => CarbonImmutable::now(),
        ]);

        $case->forceFill([$field => $localId])->save();

        if ($decisionType === 'contact' && $message !== null) {
            $this->upsertRule($case, mb_strtolower(trim((string) $message->from_address)), $localId, $actor);
        }

        $this->log->log($case, 'assignment', $previous !== null ? (string) $previous : null, (string) $localId, sprintf('%s manuell bestätigt.', ucfirst($decisionType)), $actor->getKey(), null, 'user', ['decision_type' => $decisionType, 'decision_id' => $decision->getKey()]);

        if ($this->status($case) === CaseStatus::AssignmentOpen && $decisionType === 'contact') {
            $case->forceFill(['status_processing' => CaseStatus::Open->value])->save();
            $this->log->log($case, 'processing', CaseStatus::AssignmentOpen->value, CaseStatus::Open->value, 'Zuordnung bestätigt.', $actor->getKey());
        }

        return $decision;
    }

    /**
     * @return array<int, AssignmentRule>
     */
    private function rules(int $organizationId, string $email): array
    {
        if ($email === '') {
            return [];
        }

        return AssignmentRule::query()->allOrganizations()
            ->where('organization_id', $organizationId)
            ->where('rule_type', 'sender_email')
            ->where('match_value', $email)
            ->where('active', true)
            ->get()
            ->all();
    }

    /**
     * @return array<int, Contact>
     */
    private function contactsByEmail(int $organizationId, string $email): array
    {
        if ($email === '') {
            return [];
        }

        $ids = ContactIdentifier::query()->where('kind', 'email')->where('value_normalized', $email)->pluck('contact_id')->all();

        $query = Contact::query()->allOrganizations()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->whereNull('merged_into_id')
            ->where(static function ($q) use ($ids, $email): void {
                $q->whereRaw('LOWER(emails) LIKE ?', ['%"'.str_replace(['%', '_'], ['\%', '\_'], $email).'"%']);

                if ($ids !== []) {
                    $q->orWhereIn('id', $ids);
                }
            });

        return $query->get()->all();
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $text): array
    {
        $tokens = [];

        foreach ((array) $this->config->get('hub.cases.assignment.external_id_patterns', []) as $pattern) {
            if (preg_match_all((string) $pattern, $text, $m) > 0) {
                foreach ($m[0] as $token) {
                    $tokens[] = strtoupper((string) $token);
                    $tokens[] = (string) $token;
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @return array<int, array{0: Property, 1: string}>
     */
    private function propertiesByAddress(int $organizationId, string $text): array
    {
        $normalized = str_replace(['straße', 'strasse', 'str.'], 'str', $text);

        if (preg_match_all('/([a-zäöüß][a-zäöüß\-]+(?:\s[a-zäöüß][a-zäöüß\-]+)?\s?(?:str|weg|allee|platz|gasse|ring|damm|ufer|chaussee))\s*(\d{1,4})\s?([a-z])?\b/u', $normalized, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $found = [];

        foreach ($matches as $match) {
            $street = trim($match[1]);
            $number = $match[2].($match[3] ?? '');
            $stem = preg_replace('/\s?(str|weg|allee|platz|gasse|ring|damm|ufer|chaussee)$/u', '', $street) ?? $street;

            if (mb_strlen($stem) < 3) {
                continue;
            }

            $properties = Property::query()->allOrganizations()
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->whereRaw('LOWER(street) LIKE ?', ['%'.str_replace(['%', '_'], ['\%', '\_'], $stem).'%'])
                ->get()
                ->filter(static fn (Property $p): bool => mb_strtolower(str_replace(' ', '', (string) $p->house_number)) === mb_strtolower(str_replace(' ', '', $number)));

            foreach ($properties as $property) {
                $found[] = [$property, sprintf('Adresse "%s %s" im Text.', $street, $number)];
            }
        }

        return $found;
    }

    /**
     * Kontakt und Objekt stimmen über eine Vertragspartei überein: beide Kandidaten werden gestärkt.
     *
     * @param  array<int, AssignmentCandidate>  $candidates
     * @return array<int, AssignmentCandidate>
     */
    private function enrichWithContracts(int $organizationId, array $candidates): array
    {
        $contactIds = array_values(array_unique(array_map(static fn (AssignmentCandidate $c): int => $c->localId, array_filter($candidates, static fn (AssignmentCandidate $c): bool => $c->type === 'contact'))));

        if ($contactIds === []) {
            return $candidates;
        }

        $parties = ContractParty::query()->whereIn('contact_id', $contactIds)->get();

        foreach ($parties as $party) {
            $contract = Contract::query()->allOrganizations()->where('organization_id', $organizationId)->whereNull('deleted_at')->find($party->contract_id);

            if (! $contract instanceof Contract) {
                continue;
            }

            $unit = Unit::query()->allOrganizations()->find($contract->unit_id);
            $propertyId = $unit instanceof Unit ? (int) $unit->property_id : null;
            $propertyMatch = $propertyId !== null && array_filter($candidates, static fn (AssignmentCandidate $c): bool => $c->type === 'property' && $c->localId === $propertyId) !== [];
            $confidence = $propertyMatch ? 95 : 70;
            $reason = $propertyMatch ? 'Kontakt ist Vertragspartei im genannten Objekt.' : 'Vertragspartei des Kontakts.';

            $candidates[] = new AssignmentCandidate('contract', (int) $contract->getKey(), 'contract_party', $reason, $confidence);

            if ($unit instanceof Unit) {
                $candidates[] = new AssignmentCandidate('unit', (int) $unit->getKey(), 'contract_party', $reason, $confidence);
            }

            if ($propertyId !== null) {
                $candidates[] = new AssignmentCandidate('property', $propertyId, 'contract_party', $reason, $confidence);
            }

            if ($propertyMatch) {
                $candidates[] = new AssignmentCandidate('contact', (int) $party->contact_id, 'contract_party', $reason, 96);
            }
        }

        return $candidates;
    }

    /**
     * Je Typ und ID nur der beste Kandidat.
     *
     * @param  array<int, AssignmentCandidate>  $candidates
     * @return array<int, AssignmentCandidate>
     */
    private function dedupe(array $candidates): array
    {
        $best = [];

        foreach ($candidates as $candidate) {
            $key = $candidate->type.':'.$candidate->localId;

            if (! isset($best[$key]) || $candidate->confidence > $best[$key]->confidence) {
                $best[$key] = $candidate;
            }
        }

        usort($best, static fn (AssignmentCandidate $a, AssignmentCandidate $b): int => $b->confidence <=> $a->confidence);

        return $best;
    }

    /**
     * @param  array<int, AssignmentCandidate>  $candidates
     * @return array<int, AssignmentCandidate>
     */
    private function topOf(array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $max = max(array_map(static fn (AssignmentCandidate $c): int => $c->confidence, $candidates));

        return array_values(array_filter($candidates, static fn (AssignmentCandidate $c): bool => $c->confidence === $max));
    }

    private function record(MailCase $case, MailMessage $message, string $type, AssignmentCandidate $candidate, string $basis, ?int $actorId): void
    {
        AssignmentDecision::query()->create([
            'case_id' => $case->getKey(),
            'message_id' => $message->getKey(),
            'decision_type' => $type,
            'proposed_value_json' => $candidate->toArray(),
            'chosen_value' => (string) $candidate->localId,
            'chosen_local_id' => $candidate->localId,
            'decided_by' => $actorId,
            'decision_basis' => $basis,
            'decided_at' => CarbonImmutable::now(),
        ]);

        $this->log->log($case, 'assignment', null, $type.':'.$candidate->localId, $candidate->reason, $actorId, null, 'system', $candidate->toArray());
    }

    private function upsertRule(MailCase $case, string $email, int $contactId, User $actor): void
    {
        if ($email === '') {
            return;
        }

        AssignmentRule::query()->allOrganizations()
            ->where('organization_id', $case->organization_id)
            ->where('rule_type', 'sender_email')
            ->where('match_value', $email)
            ->where('target_type', 'contact')
            ->where('target_local_id', '!=', $contactId)
            ->update(['active' => false, 'updated_at' => CarbonImmutable::now()]);

        $rule = AssignmentRule::query()->allOrganizations()->firstOrNew([
            'organization_id' => $case->organization_id,
            'rule_type' => 'sender_email',
            'match_value' => $email,
            'target_type' => 'contact',
            'target_local_id' => $contactId,
        ]);

        $rule->forceFill([
            'confidence' => 97, // Bestätigte Regel schlägt die reine Kennungssuche (95).
            'confirmed_count' => $rule->exists ? (int) $rule->confirmed_count + 1 : 1,
            'last_confirmed_at' => CarbonImmutable::now(),
            'created_by' => $rule->created_by ?? $actor->getKey(),
            'active' => true,
        ])->save();
    }

    private function assertInOrganization(MailCase $case, string $type, int $localId): void
    {
        $organizationId = (int) $case->organization_id;

        $query = match ($type) {
            'contact' => Contact::query()->allOrganizations()->where('organization_id', $organizationId),
            'property' => Property::query()->allOrganizations()->where('organization_id', $organizationId),
            'unit' => Unit::query()->allOrganizations()->where('organization_id', $organizationId),
            'contract' => Contract::query()->allOrganizations()->where('organization_id', $organizationId),
            'assignee' => User::query()->where('organization_id', $organizationId),
            default => null,
        };

        if ($query !== null && ! $query->whereKey($localId)->exists()) {
            throw new InvalidArgumentException(sprintf('%s %d gehört nicht zur Organisation des Vorgangs.', ucfirst($type), $localId));
        }
    }

    private function status(MailCase $case): CaseStatus
    {
        return $case->status_processing instanceof CaseStatus ? $case->status_processing : CaseStatus::from((string) $case->status_processing);
    }

    private function contactLabel(Contact $contact): string
    {
        return trim((string) $contact->first_name.' '.(string) $contact->last_name) ?: 'Kontakt '.$contact->getKey();
    }
}
