<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Core\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class StoreUserRequest extends FormRequest
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
        $roles = array_map(static fn (Role $r): string => $r->value, array_filter(Role::cases(), static fn (Role $r): bool => $r->canLogin()));

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'role' => ['required', 'string', Rule::in($roles)],
            'password' => ['required', 'string', 'confirmed', Password::min((int) config('hub.security.login.password_min_length', 12))->letters()->numbers()],
        ];
    }
}
