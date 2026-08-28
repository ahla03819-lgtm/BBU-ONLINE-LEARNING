<?php

namespace App\Http\Requests\Coursework;

use App\Models\Assignment;
use Illuminate\Foundation\Http\FormRequest;

class CreateAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [Assignment::class, $this->route('classSubject')]);
    }

    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:180', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u'], 'instructions' => ['nullable', 'string', 'max:50000'], 'max_points' => ['required', 'numeric', 'gt:0', 'max:999999.99'], 'due_at' => ['nullable', 'date'], 'allow_resubmission' => ['required', 'boolean'], ...$this->prohibited()];
    }

    private function prohibited(): array
    {
        return collect(['class_subject_id', 'created_by', 'status', 'published_at', 'closed_at', 'archived_at', 'archived_by', 'archived_from_status', 'lifecycle_version'])->mapWithKeys(fn ($f) => [$f => ['prohibited']])->all();
    }
}
