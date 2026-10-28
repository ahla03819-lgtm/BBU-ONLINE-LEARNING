<?php

namespace App\Http\Requests\GuestMeetings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideGuestMeetingSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['decision' => ['required', 'string', Rule::in(['admitted', 'rejected'])]];
    }
}
