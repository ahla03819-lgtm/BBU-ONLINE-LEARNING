<?php

namespace App\Http\Requests\Messaging;

use App\Models\Message;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [Message::class, $this->route('channel')])
            && (! $this->hasFile('attachments') || $this->user()->can('attachments.upload'));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->body)) {
            $this->merge(['body' => str_replace(["\r\n", "\r"], "\n", $this->body)]);
        }
    }

    public function rules(): array
    {
        return [
            'client_uuid' => ['required', 'uuid'],
            'body' => ['nullable', 'string', 'max:4000', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u'],
            'reply_to_id' => ['nullable', 'integer'],
            'attachments' => ['nullable', 'array', 'max:'.config('message-attachments.max_files')],
            'attachments.*' => ['required', 'file', 'max:'.config('message-attachments.max_file_kilobytes')],
            'attachment_client_uuids' => ['required_with:attachments', 'array', 'size:'.count($this->file('attachments', []))],
            'attachment_client_uuids.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $files = $this->file('attachments', []);
            if (trim((string) $this->input('body')) === '' && count($files) === 0) {
                $validator->errors()->add('body', 'A message requires text or at least one attachment.');
            }
            if (array_sum(array_map(fn ($file) => $file?->getSize() ?? 0, $files)) > config('message-attachments.max_combined_bytes')) {
                $validator->errors()->add('attachments', 'Combined attachments may not exceed 25 MB.');
            }
        });
    }
}
