<?php

namespace App\Http\Requests\Collaboration;

use Illuminate\Foundation\Http\FormRequest;

class ScheduleAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('publish', $this->route('announcement'));
    }

    public function rules(): array
    {
        return ['publish_at' => ['required', 'date', 'after:now']];
    }
}
