<?php

namespace App\Http\Requests\Messaging;

use App\Enums\ReactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetMessageReactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $message = $this->route('message');
        $ability = $message->reactions()->where('user_id', $this->user()->id)->exists() ? 'updateOwn' : 'setReaction';

        return $this->user()->can($ability, $message);
    }

    public function rules(): array
    {
        return ['reaction' => ['required', Rule::enum(ReactionType::class)]];
    }
}
