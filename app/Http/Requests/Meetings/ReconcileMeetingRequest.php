<?php

namespace App\Http\Requests\Meetings;

use Illuminate\Foundation\Http\FormRequest;

class ReconcileMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['lifecycle_version' => ['required', 'integer', 'min:0']];
    }
}
