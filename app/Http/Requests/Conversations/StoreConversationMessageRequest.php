<?php

namespace App\Http\Requests\Conversations;

use Illuminate\Foundation\Http\FormRequest;

class StoreConversationMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:4000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:512000'],
            'duration_seconds' => ['nullable', 'array', 'max:5'],
            'duration_seconds.*' => ['nullable', 'integer', 'min:0', 'max:7200'],
            'reply_to_message_id' => ['nullable', 'integer'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! is_string($this->input('body'))) {
            return;
        }

        $body = trim($this->input('body'));
        $this->merge(['body' => $body === '' ? null : $body]);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->input('body') === null && count($this->file('attachments', [])) === 0) {
                $validator->errors()->add('body', 'A message must contain text or at least one attachment.');
            }
        });
    }
}
