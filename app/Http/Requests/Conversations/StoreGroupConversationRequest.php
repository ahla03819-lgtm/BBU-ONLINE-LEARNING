<?php

namespace App\Http\Requests\Conversations;

use Illuminate\Foundation\Http\FormRequest;

class StoreGroupConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:120'], 'member_ids' => ['required', 'array', 'min:1', 'max:50'], 'member_ids.*' => ['integer', 'distinct']];
    }
}
