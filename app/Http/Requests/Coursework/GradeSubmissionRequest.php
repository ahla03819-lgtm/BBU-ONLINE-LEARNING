<?php

namespace App\Http\Requests\Coursework;

use App\Models\AssignmentGrade;
use Illuminate\Foundation\Http\FormRequest;

class GradeSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $submission = $this->route('submission');

        return $this->user()->can($submission->grades()->exists() ? 'update' : 'create', [AssignmentGrade::class, $submission]);
    }

    public function rules(): array
    {
        $hasGrade = $this->route('submission')->grades()->exists();

        return ['points_awarded' => ['required', 'numeric', 'min:0'], 'feedback' => ['nullable', 'string', 'max:50000'], 'change_reason' => [$hasGrade ? 'required' : 'nullable', 'string', 'max:500'], 'graded_by' => ['prohibited'], 'max_points_snapshot' => ['prohibited'], 'revision_number' => ['prohibited']];
    }
}
