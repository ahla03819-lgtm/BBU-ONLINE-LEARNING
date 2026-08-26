<?php

namespace App\Http\Requests\Meetings;

use Illuminate\Foundation\Http\FormRequest;

class EndMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('end', $this->route('meeting'));
    }

    public function rules(): array
    {
        return [];
    }
}
