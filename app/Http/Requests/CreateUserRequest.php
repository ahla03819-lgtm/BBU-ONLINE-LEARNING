<?php

namespace App\Http\Requests;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CreateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->email))]);
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'], 'password' => ['required', 'confirmed', Password::defaults()], 'status' => ['required', Rule::enum(AccountStatus::class)], 'role' => ['required', Rule::in(['Super Admin', 'Admin', 'Teacher', 'Student'])]];
    }

    public function after(): array
    {
        return [function ($validator) {
            if ($this->role === 'Super Admin' && ! $this->user()->hasRole('Super Admin')) {
                $validator->errors()->add('role', 'Only a Super Admin may create another Super Admin.');
            }
        }];
    }
}
