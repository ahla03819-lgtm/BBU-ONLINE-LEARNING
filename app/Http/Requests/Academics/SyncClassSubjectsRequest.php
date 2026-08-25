<?php

namespace App\Http\Requests\Academics;

use Illuminate\Foundation\Http\FormRequest;

class SyncClassSubjectsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('assignSubjects', $this->route('schoolClass'));
    }

    public function rules(): array
    {
        return ['subject_ids' => ['array'], 'subject_ids.*' => ['integer', 'distinct', 'exists:subjects,id'], 'effective_on' => ['required', 'date']];
    }
}
