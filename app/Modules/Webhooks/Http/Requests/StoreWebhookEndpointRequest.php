<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreWebhookEndpointRequest extends FormRequest
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
        $events = array_keys((array) config('hub.webhooks.events', []));

        return [
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'string', 'max:1024', 'url:https'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in($events)],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
