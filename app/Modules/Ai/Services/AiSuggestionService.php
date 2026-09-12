<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Core\Contracts\Mail\AiProviderInterface;
use App\Modules\Ai\Contracts\AiContextSourceInterface;
use App\Modules\Ai\Enums\AiRunStatus;
use App\Modules\Ai\Enums\AiTask;
use App\Modules\Ai\Enums\SuggestionStatus;
use App\Modules\Ai\Exceptions\AiBudgetExceededException;
use App\Modules\Ai\Exceptions\AiSchemaException;
use App\Modules\Ai\Models\AiRun;
use App\Modules\Ai\Models\AiSuggestion;
use App\Modules\Ai\Schemas\AiSchemas;
use App\Modules\Ai\Support\AiCostEstimator;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Gmail\Models\MailMessage;
use App\Modules\Mail\Exceptions\MailIntegrationNotConfiguredException;
use App\Modules\Mail\Exceptions\MailRemoteException;
use App\Modules\Security\Models\User;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestriert einen KI-Lauf: Budget prüfen, Eingaben maskieren, Fremdinhalte als untrusted blocken, Anbieter rufen,
 * Schema und Fachregeln prüfen, Rückabbildung nur serverseitig, Protokoll in mail_ai_runs, Vorschläge als
 * mail_ai_suggestions mit Status proposed. Kein Pfad dieser Klasse ruft Aktionen, Rechte, Versand oder Fachsysteme.
 * Bei Fehler, Budget oder fehlender Einrichtung bleibt der Vorgang manuell bearbeitbar (leere Vorschläge, Status sichtbar).
 */
final class AiSuggestionService
{
    public function __construct(
        private readonly AiProviderInterface $provider,
        private readonly AiSchemas $schemas,
        private readonly SchemaValidator $validator,
        private readonly BusinessRuleValidator $rules,
        private readonly AiBudget $budget,
        private readonly AiCostEstimator $costs,
        private readonly AiContextSourceInterface $context,
        private readonly Repository $config,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) $this->config->get('hub.mail.flags.ai', false);
    }

    /**
     * @param  array<string, mixed>  $trusted  verifizierte Angaben der Anwendung (Fakten, Kandidatenliste, Bearbeitungsstand)
     * @param  array<int, array{type: string, label?: string, content: string}>  $untrusted  Fremdinhalte (E-Mail, Anhänge)
     * @param  array<string, mixed>  $ruleContext  rule_priority, candidate_ids, verified_facts, allowed_action_types
     */
    public function run(
        AiTask $task,
        ?MailCase $case,
        ?MailMessage $message,
        ?User $user,
        array $trusted = [],
        array $untrusted = [],
        array $ruleContext = [],
    ): AiResult {
        $organizationId = (int) ($case?->getAttribute('organization_id') ?? $message?->getAttribute('organization_id') ?? $user?->getAttribute('organization_id') ?? 0);
        $schema = $this->schemas->for($task);

        if (! $this->isEnabled()) {
            return new AiResult(AiRunStatus::NotConfigured, null, [], ['KI-Vorschläge sind deaktiviert (MAIL_AI_ENABLED).']);
        }

        if ($case !== null && $user !== null) {
            $untrusted = array_merge($untrusted, $this->documentBlocks($case, $user));
        }

        $masker = (new PromptMasker($this->config))->withKnownNames($this->knownNames($message));
        $input = $masker->maskArray([
            'trusted' => $this->normalizeTrusted($task, $trusted, $ruleContext),
            'untrusted' => $this->limitUntrusted($untrusted),
        ]);

        $run = new AiRun([
            'organization_id' => $organizationId,
            'case_id' => $case?->getKey(),
            'message_id' => $message?->getKey(),
            'task' => $task->value,
            'provider' => 'pending',
            'input_hash' => hash('sha256', json_encode($input, JSON_THROW_ON_ERROR)),
            'schema_hash' => hash('sha256', json_encode($schema, JSON_THROW_ON_ERROR)),
            'status' => AiRunStatus::Pending->value,
            'started_at' => now()->toImmutable(),
        ]);

        try {
            $this->budget->assertAvailable($organizationId);
        } catch (AiBudgetExceededException $e) {
            return $this->finish($run, AiRunStatus::BudgetExceeded, $e, ['Budget erreicht, kein Aufruf. Vorgang manuell bearbeiten.']);
        }

        try {
            $raw = $this->provider->structured($task->value, $input, $schema);
        } catch (MailIntegrationNotConfiguredException $e) {
            return $this->finish($run, AiRunStatus::NotConfigured, $e, ['KI nicht eingerichtet.']);
        } catch (MailRemoteException $e) {
            Log::warning('KI-Aufruf fehlgeschlagen', ['task' => $task->value, 'status' => $e->httpStatus, 'case_id' => $case?->getKey()]);

            return $this->finish($run, AiRunStatus::Failed, $e, ['KI nicht erreichbar. Vorgang manuell bearbeiten.']);
        } catch (Throwable $e) {
            Log::error('KI-Aufruf mit unerwartetem Fehler', ['task' => $task->value, 'class' => $e::class]);

            return $this->finish($run, AiRunStatus::Failed, $e, ['Technischer Fehler. Vorgang manuell bearbeiten.']);
        }

        $meta = is_array($raw['meta'] ?? null) ? $raw['meta'] : [];
        unset($raw['meta']);
        $this->applyMeta($run, $meta);

        $errors = $this->validator->validate($raw, $schema);

        if ($errors !== []) {
            return $this->finish($run, AiRunStatus::SchemaInvalid, new AiSchemaException($errors), ['Antwort verworfen (Schema).']);
        }

        $checked = $this->rules->apply($task, $raw, $ruleContext + ['allowed_action_types' => $this->schemas->allowedActionTypes()]);

        if ($checked['errors'] !== []) {
            return $this->finish($run, AiRunStatus::RuleRejected, new AiSchemaException($checked['errors'], true), $checked['errors']);
        }

        // Rückabbildung nur serverseitig, das Mapping wird nicht gespeichert.
        $payload = $masker->unmaskArray($checked['response']);
        $payload['_adjustments'] = $checked['adjustments'];
        $payload['_masked_placeholders'] = $masker->placeholderCount();

        $run->setAttribute('status', AiRunStatus::Succeeded->value);
        $run->setAttribute('finished_at', now()->toImmutable());
        $run->save();

        $suggestion = AiSuggestion::query()->create([
            'ai_run_id' => $run->getKey(),
            'case_id' => $case?->getKey(),
            'suggestion_type' => $task->suggestionType(),
            'payload_json' => $payload,
            'confidence_percent' => isset($payload['confidence_percent']) ? (int) $payload['confidence_percent'] : null,
            'status' => SuggestionStatus::Proposed->value,
        ]);

        if ($case !== null) {
            AiSuggestion::query()
                ->where('case_id', $case->getKey())
                ->where('suggestion_type', $task->suggestionType())
                ->where('status', SuggestionStatus::Proposed->value)
                ->where('id', '!=', $suggestion->getKey())
                ->update(['status' => SuggestionStatus::Superseded->value]);
        }

        return new AiResult(AiRunStatus::Succeeded, $run, [$suggestion], $checked['adjustments']);
    }

    /**
     * Entscheidung einer Person. Nur proposed kann entschieden werden; die Übernahme in Fachdaten erfolgt außerhalb
     * dieser Klasse durch die jeweilige Fachlogik nach Prüfung des Status accepted.
     */
    public function decide(AiSuggestion $suggestion, User $user, SuggestionStatus $decision): AiSuggestion
    {
        if (! $decision->isDecided()) {
            throw new \InvalidArgumentException('Entscheidung muss accepted oder rejected sein.');
        }

        if ((string) $suggestion->getAttribute('status') !== SuggestionStatus::Proposed->value) {
            throw new \LogicException('Nur vorgeschlagene Vorschläge können entschieden werden.');
        }

        $suggestion->forceFill([
            'status' => $decision->value,
            'decided_by' => $user->getKey(),
            'decided_at' => now()->toImmutable(),
        ])->save();

        return $suggestion;
    }

    /**
     * @return array<int, array{type: string, label: string, content: string}>
     */
    private function documentBlocks(MailCase $case, User $user): array
    {
        $blocks = [];

        try {
            $excerpts = $this->context->excerptsFor(
                $case,
                $user,
                (int) $this->config->get('hub.ai.max_context_excerpts', 5),
                (int) $this->config->get('hub.ai.max_context_excerpt_chars', 1500),
            );
        } catch (Throwable $e) {
            Log::warning('Dokumentkontext für KI nicht verfügbar', ['class' => $e::class]);

            return [];
        }

        foreach ($excerpts as $excerpt) {
            $blocks[] = ['type' => 'document', 'label' => $excerpt['label'], 'content' => $excerpt['content']];
        }

        return $blocks;
    }

    /**
     * @param  array<int, array{type: string, label?: string, content: string}>  $untrusted
     * @return array<int, array{type: string, label: string, content: string}>
     */
    private function limitUntrusted(array $untrusted): array
    {
        $limit = max(1000, (int) $this->config->get('hub.ai.max_input_chars', 20000));
        $used = 0;
        $result = [];

        foreach ($untrusted as $block) {
            // Markierungen der untrusted-Blöcke werden bereits hier neutralisiert, unabhängig vom Anbieter-Adapter.
            $content = PromptBuilder::neutralizeMarkers((string) ($block['content'] ?? ''));
            $remaining = $limit - $used;

            if ($remaining <= 0) {
                break;
            }

            if (mb_strlen($content) > $remaining) {
                $content = mb_substr($content, 0, $remaining).' [gekürzt]';
            }

            $used += mb_strlen($content);
            $result[] = ['type' => (string) ($block['type'] ?? 'text'), 'label' => PromptBuilder::neutralizeMarkers((string) ($block['label'] ?? '')), 'content' => $content];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $trusted
     * @param  array<string, mixed>  $ruleContext
     * @return array<string, mixed>
     */
    private function normalizeTrusted(AiTask $task, array $trusted, array $ruleContext): array
    {
        if ($task === AiTask::MatchCandidates && isset($ruleContext['candidate_ids']) && ! isset($trusted['candidates'])) {
            $trusted['candidates'] = array_map(static fn (mixed $id): array => ['candidate_id' => (string) $id], (array) $ruleContext['candidate_ids']);
        }

        if ($task === AiTask::NextSteps) {
            $trusted['allowed_action_types'] = $this->schemas->allowedActionTypes();
        }

        if ($task === AiTask::Classify && isset($ruleContext['rule_priority'])) {
            $trusted['rule_priority_minimum'] = (string) $ruleContext['rule_priority'];
        }

        if ($task === AiTask::DraftReply) {
            $trusted['verified_facts'] = array_values(array_map(static fn (mixed $fact): array => [
                'text' => is_array($fact) ? (string) ($fact['text'] ?? '') : (string) $fact,
                'verified' => is_array($fact) && (bool) ($fact['verified'] ?? false),
            ], (array) ($ruleContext['verified_facts'] ?? [])));
        }

        return $trusted;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function applyMeta(AiRun $run, array $meta): void
    {
        $in = (int) ($meta['input_tokens'] ?? 0);
        $out = (int) ($meta['output_tokens'] ?? 0);
        $run->setAttribute('provider', mb_substr((string) ($meta['provider'] ?? 'unknown'), 0, 16));
        $run->setAttribute('model', isset($meta['model']) ? mb_substr((string) $meta['model'], 0, 80) : null);
        $run->setAttribute('input_tokens', $in);
        $run->setAttribute('output_tokens', $out);
        $run->setAttribute('latency_ms', (int) ($meta['latency_ms'] ?? 0));
        $run->setAttribute('cost_cents', $this->costs->estimateCents($in, $out));
    }

    /**
     * @param  array<int, string>  $notes
     */
    private function finish(AiRun $run, AiRunStatus $status, Throwable $error, array $notes): AiResult
    {
        $run->setAttribute('status', $status->value);
        $run->setAttribute('error_class', mb_substr($error::class, 0, 200));
        $run->setAttribute('error_message', mb_substr($error->getMessage(), 0, 2000));
        $run->setAttribute('finished_at', now()->toImmutable());

        if ($run->getAttribute('provider') === 'pending') {
            $run->setAttribute('provider', 'none');
        }

        $run->save();

        return new AiResult($status, $run, [], $notes);
    }

    /**
     * Personennamen aus den Kopfzeilen der Nachricht (Absender, Empfänger, Kopie) für die Namensmaskierung.
     *
     * @return array<int, string>
     */
    private function knownNames(?MailMessage $message): array
    {
        if ($message === null) {
            return [];
        }

        $names = [(string) $message->getAttribute('from_name')];

        foreach (['to_json', 'cc_json'] as $column) {
            foreach ((array) $message->getAttribute($column) as $recipient) {
                if (is_array($recipient) && isset($recipient['name']) && is_string($recipient['name'])) {
                    $names[] = $recipient['name'];
                } elseif (is_string($recipient) && preg_match('/^\s*"?([^"<]+?)"?\s*<[^>]+>/', $recipient, $m) === 1) {
                    $names[] = $m[1];
                }
            }
        }

        return array_values(array_filter($names, static fn (string $n): bool => trim($n) !== ''));
    }
}
