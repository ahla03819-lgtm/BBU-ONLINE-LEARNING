<?php

namespace App\Http\Requests\Coursework;

use Illuminate\Foundation\Http\FormRequest;

class TransitionAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can((string) $this->route('courseworkAbility'), $this->route('assignment'));
    }

    public function rules(): array
    {
        return ['lifecycle_version' => ['required', 'integer', 'min:0']];
    }
}
