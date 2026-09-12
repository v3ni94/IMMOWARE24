<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

final class UpdateWebhookEndpointRequest extends StoreWebhookEndpointRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['active']);

        return $rules;
    }
}
