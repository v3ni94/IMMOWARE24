<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Services;

use App\Modules\MailUi\Models\OrgSetting;
use App\Modules\Security\Models\User;

/**
 * Organisationseinstellungen der Mail-Bearbeitung (mail_org_settings): Eskalationsempfänger, Bereitschaft,
 * Kostenlimit KI, Aufbewahrung, Stand des Einrichtungsassistenten. Werte als JSON, Schlüssel aus KEYS.
 */
final class OrgSettings
{
    public const string ESCALATION_RECIPIENTS = 'escalation_recipients';

    public const string ON_CALL = 'on_call';

    public const string AI_BUDGET = 'ai_budget';

    public const string RETENTION = 'retention';

    public const string SETUP = 'setup';

    public const string APPROVERS = 'approvers';

    /** @var array<string, string> */
    public const array LABELS = [
        self::ESCALATION_RECIPIENTS => 'Eskalationsempfänger',
        self::ON_CALL => 'Bereitschaft',
        self::AI_BUDGET => 'Kostenlimit KI',
        self::RETENTION => 'Aufbewahrung',
        self::SETUP => 'Einrichtungsassistent',
        self::APPROVERS => 'Freigabeberechtigte',
    ];

    /**
     * @return array<string, mixed>
     */
    public function get(int $organizationId, string $key, array $default = []): array
    {
        $row = OrgSetting::query()->allOrganizations()->where('organization_id', $organizationId)->where('key', $key)->first();
        $value = $row?->getAttribute('value_json');

        return is_array($value) ? $value : $default;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public function put(int $organizationId, string $key, array $value, ?User $actor = null): OrgSetting
    {
        return OrgSetting::query()->allOrganizations()->updateOrCreate(
            ['organization_id' => $organizationId, 'key' => $key],
            ['value_json' => $value, 'updated_by' => $actor?->getKey()],
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(int $organizationId): array
    {
        $result = [];

        foreach (array_keys(self::LABELS) as $key) {
            $result[$key] = $this->get($organizationId, $key);
        }

        return $result;
    }
}
