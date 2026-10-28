<?php

namespace App\Http\Requests\GuestMeetings;

use Illuminate\Foundation\Http\FormRequest;

class CreateGuestMeetingSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('display_name');
        if (is_string($name)) {
            $this->merge(['display_name' => preg_replace('/\s+/u', ' ', trim($name))]);
        }
    }

    public function rules(): array
    {
        return ['display_name' => ['required', 'string', 'min:2', 'max:80', 'not_regex:/[\p{C}]/u']];
    }
}
