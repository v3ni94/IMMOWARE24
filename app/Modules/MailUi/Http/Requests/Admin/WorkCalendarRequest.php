<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Arbeitskalender: je Wochentag Start und Ende (HH:MM) oder leer (arbeitsfrei).
 */
final class WorkCalendarRequest extends FormRequest
{
    public const array DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'is_default' => ['nullable', 'boolean'],
        ];

        foreach (self::DAYS as $day) {
            $rules['hours.'.$day.'.start'] = ['nullable', 'date_format:H:i'];
            $rules['hours.'.$day.'.end'] = ['nullable', 'date_format:H:i', 'after:hours.'.$day.'.start'];
        }

        return $rules;
    }

    /**
     * @return array<string, array{start: string, end: string}>
     */
    public function weeklyHours(): array
    {
        $result = [];
        $hours = (array) $this->validated('hours', []);

        foreach (self::DAYS as $day) {
            $start = $hours[$day]['start'] ?? null;
            $end = $hours[$day]['end'] ?? null;

            if (is_string($start) && is_string($end) && $start !== '' && $end !== '') {
                $result[$day] = ['start' => $start, 'end' => $end];
            }
        }

        return $result;
    }
}
