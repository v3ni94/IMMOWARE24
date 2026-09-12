<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Requests\Admin;

use App\Modules\MailUi\Services\OrgSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validierung je Einstellungsschlüssel (Route-Parameter key).
 */
final class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return self::rulesFor((string) $this->route('key'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(string $key): array
    {
        return match ($key) {
            OrgSettings::ESCALATION_RECIPIENTS => [
                'user_ids' => ['nullable', 'array', 'max:20'],
                'user_ids.*' => ['integer', 'min:1'],
                'emails' => ['nullable', 'string', 'max:2000'],
                'phone_note' => ['nullable', 'string', 'max:200'],
            ],
            OrgSettings::ON_CALL => [
                'enabled' => ['nullable', 'boolean'],
                'user_ids' => ['nullable', 'array', 'max:20'],
                'user_ids.*' => ['integer', 'min:1'],
                'phone_note' => ['nullable', 'string', 'max:200'],
                'hours_note' => ['nullable', 'string', 'max:200'],
            ],
            OrgSettings::AI_BUDGET => [
                'monthly_limit_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
                'per_case_limit_cents' => ['nullable', 'integer', 'min:0', 'max:1000000'],
                'warn_percent' => ['nullable', 'integer', 'min:1', 'max:99'],
            ],
            OrgSettings::RETENTION => [
                'messages_days' => ['required', 'integer', 'min:30', 'max:3650'],
                'attachments_days' => ['required', 'integer', 'min:30', 'max:3650'],
                'closed_cases_days' => ['required', 'integer', 'min:30', 'max:3650'],
                'ai_runs_days' => ['required', 'integer', 'min:7', 'max:3650'],
            ],
            OrgSettings::APPROVERS => [
                'standard_user_ids' => ['nullable', 'array', 'max:50'],
                'standard_user_ids.*' => ['integer', 'min:1'],
                'bank_user_ids' => ['nullable', 'array', 'max:50'],
                'bank_user_ids.*' => ['integer', 'min:1'],
            ],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function valueFor(string $key): array
    {
        $data = $this->validated();

        return self::normalize($key, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(string $key, array $data): array
    {
        $ints = static fn (mixed $list): array => array_values(array_unique(array_map('intval', is_array($list) ? $list : [])));

        return match ($key) {
            OrgSettings::ESCALATION_RECIPIENTS => [
                'user_ids' => $ints($data['user_ids'] ?? []),
                'emails' => array_values(array_filter(array_map('trim', preg_split('/[;,\s]+/', (string) ($data['emails'] ?? '')) ?: []), static fn (string $e): bool => filter_var($e, FILTER_VALIDATE_EMAIL) !== false)),
                'phone_note' => (string) ($data['phone_note'] ?? ''),
            ],
            OrgSettings::ON_CALL => [
                'enabled' => (bool) ($data['enabled'] ?? false),
                'user_ids' => $ints($data['user_ids'] ?? []),
                'phone_note' => (string) ($data['phone_note'] ?? ''),
                'hours_note' => (string) ($data['hours_note'] ?? ''),
            ],
            OrgSettings::AI_BUDGET => [
                'monthly_limit_cents' => (int) ($data['monthly_limit_cents'] ?? 0),
                'per_case_limit_cents' => (int) ($data['per_case_limit_cents'] ?? 0),
                'warn_percent' => (int) ($data['warn_percent'] ?? 80),
            ],
            OrgSettings::RETENTION => [
                'messages_days' => (int) ($data['messages_days'] ?? 0),
                'attachments_days' => (int) ($data['attachments_days'] ?? 0),
                'closed_cases_days' => (int) ($data['closed_cases_days'] ?? 0),
                'ai_runs_days' => (int) ($data['ai_runs_days'] ?? 0),
            ],
            OrgSettings::APPROVERS => [
                'standard_user_ids' => $ints($data['standard_user_ids'] ?? []),
                'bank_user_ids' => $ints($data['bank_user_ids'] ?? []),
            ],
            default => $data,
        };
    }
}
