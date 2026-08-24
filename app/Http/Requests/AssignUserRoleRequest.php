<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('assignRole', $this->route('user'))
            && ($this->input('role') !== 'Super Admin' || $this->user()->hasRole('Super Admin'));
    }

    public function rules(): array
    {
        return ['role' => ['required', Rule::in(['Super Admin', 'Admin', 'Teacher', 'Student'])]];
    }
}
