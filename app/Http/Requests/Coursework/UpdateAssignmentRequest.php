<?php

namespace App\Http\Requests\Coursework;

use App\Enums\AssignmentStatus;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('assignment'));
    }

    public function rules(): array
    {
        $published = $this->route('assignment')->status === AssignmentStatus::Published;

        return ['title' => ['required', 'string', 'max:180'], 'instructions' => ['nullable', 'string', 'max:50000'], 'max_points' => [$published ? 'prohibited' : 'required', 'numeric', 'gt:0', 'max:999999.99'], 'due_at' => ['nullable', 'date'], 'allow_resubmission' => [$published ? 'prohibited' : 'required', 'boolean'], 'lifecycle_version' => ['required', 'integer', 'min:0'], 'class_subject_id' => ['prohibited'], 'created_by' => ['prohibited'], 'status' => ['prohibited'], 'published_at' => ['prohibited'], 'closed_at' => ['prohibited'], 'archived_at' => ['prohibited']];
    }
}
