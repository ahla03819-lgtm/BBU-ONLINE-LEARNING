<?php

namespace App\Http\Requests\Recordings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A browser-supplied duration is only ever a request.
 *
 * The bounds come from configuration and the deadline is computed from server time
 * inside the action, so a tampered duration_minutes cannot buy a longer recording
 * than the application allows, and a client clock cannot move the deadline.
 */
class StartMeetingRecordingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'duration_minutes' => ['nullable', 'integer', 'min:'.config('meeting-recordings.min_duration_minutes'), 'max:'.config('meeting-recordings.max_duration_minutes')],
        ];
    }
}
