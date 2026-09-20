<?php

namespace App\Http\Requests\Meetings;

use App\Services\MeetingAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('meeting'));
    }

    public function rules(): array
    {
        $class = $this->route('schoolClass');
        $administrator = app(MeetingAccess::class)->isAdministrator($this->user());

        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'class_subject_id' => ['nullable', 'integer', Rule::exists('class_subjects', 'id')->where('school_class_id', $class->id)],
            'host_user_id' => [Rule::requiredIf($administrator), 'nullable', 'integer', 'exists:users,id'],
            'scheduled_start_at' => ['required', 'date'],
            'scheduled_end_at' => ['nullable', 'date', 'after:scheduled_start_at'],
            'max_participants' => ['required', 'integer', 'min:'.config('meetings.min_participants'), 'max:'.config('meetings.max_participants')],
            ...collect(['uuid', 'livekit_room_name', 'actual_start_at', 'session_started_at', 'actual_end_at', 'lifecycle_version', 'status', 'join_policy', 'start_attempt_uuid', 'last_provider_error'])
                ->mapWithKeys(fn (string $field) => [$field => ['prohibited']])->all(),
        ];
    }
}
