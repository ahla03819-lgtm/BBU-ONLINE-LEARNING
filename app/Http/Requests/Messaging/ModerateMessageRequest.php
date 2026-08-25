<?php

namespace App\Http\Requests\Messaging;

use Illuminate\Foundation\Http\FormRequest;

class ModerateMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('moderate', $this->route('message'));
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }
}
