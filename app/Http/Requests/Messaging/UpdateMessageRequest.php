<?php

namespace App\Http\Requests\Messaging;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('message'));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->body)) {
            $this->merge(['body' => str_replace(["\r\n", "\r"], "\n", $this->body)]);
        }
    }

    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:4000', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', function (string $attribute, mixed $value, \Closure $fail) {
            if (trim($value) === '') {
                $fail('The message body may not be blank.');
            }
        }]];
    }
}
