<?php

namespace App\Http\Requests\Messaging;

use Illuminate\Foundation\Http\FormRequest;

class RemoveMessageReactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('deleteOwn', $this->route('message'));
    }

    public function rules(): array
    {
        return [];
    }
}
