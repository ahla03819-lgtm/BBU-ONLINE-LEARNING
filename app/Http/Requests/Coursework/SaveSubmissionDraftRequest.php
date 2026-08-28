<?php

namespace App\Http\Requests\Coursework;

use App\Models\AssignmentSubmission;
use Illuminate\Foundation\Http\FormRequest;

class SaveSubmissionDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assignment = $this->route('assignment');
        $submission = AssignmentSubmission::query()->where('assignment_id', $assignment->id)->whereHas('studentProfile', fn ($q) => $q->where('user_id', $this->user()->id))->first();

        return $submission ? $this->user()->can('update', $submission) : $this->user()->can('create', [AssignmentSubmission::class, $assignment]);
    }

    public function rules(): array
    {
        $files = $this->file('attachments', []);

        return ['client_uuid' => ['required', 'uuid'], 'body' => ['nullable', 'string', 'max:100000'], 'attachments' => ['nullable', 'array', 'max:'.config('coursework-attachments.max_files')], 'attachments.*' => ['required', 'file', 'max:'.config('coursework-attachments.max_file_kilobytes')], 'attachment_client_uuids' => ['required_with:attachments', 'array', 'size:'.count($files)], 'attachment_client_uuids.*' => ['required', 'uuid', 'distinct'], 'student_profile_id' => ['prohibited'], 'submitted_at' => ['prohibited'], 'is_late' => ['prohibited'], 'status' => ['prohibited']];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (array_sum(array_map(fn ($f) => $f?->getSize() ?? 0, $this->file('attachments', []))) > config('coursework-attachments.max_combined_bytes')) {
                $validator->errors()->add('attachments', 'Combined attachments exceed the allowed limit.');
            }
        });
    }
}
