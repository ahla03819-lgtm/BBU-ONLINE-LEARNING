<?php

namespace App\Http\Requests\Messaging;

use Illuminate\Foundation\Http\FormRequest;

class HideMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('hide', $this->route('message'));
    }

    public function rules(): array
    {
        return [];
    }
}
