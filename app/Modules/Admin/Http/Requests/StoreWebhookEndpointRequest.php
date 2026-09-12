<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Webhooks\Rules\SafeWebhookUrl;
use App\Modules\Webhooks\Services\WebhookUrlGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWebhookEndpointRequest extends FormRequest
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
            'url' => ['required', 'string', 'max:1024', 'url:https', new SafeWebhookUrl($this->container->make(WebhookUrlGuard::class))],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in($events)],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'url.url' => 'Die URL muss mit https:// beginnen.',
            'events.required' => 'Bitte mindestens ein Ereignis wählen.',
        ];
    }
}
