<?php

namespace App\Http\Requests\Meetings;

use Illuminate\Foundation\Http\FormRequest;

class StartMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('start', $this->route('meeting'));
    }

    public function rules(): array
    {
        return [];
    }
}
