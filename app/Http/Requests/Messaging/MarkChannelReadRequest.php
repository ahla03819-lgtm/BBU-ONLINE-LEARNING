<?php

namespace App\Http\Requests\Messaging;

use App\Models\Message;
use Illuminate\Foundation\Http\FormRequest;

class MarkChannelReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('messages.read-state') && $this->user()->can('viewAny', [Message::class, $this->route('channel')]);
    }

    public function rules(): array
    {
        return ['message_id' => ['required', 'integer']];
    }
}
