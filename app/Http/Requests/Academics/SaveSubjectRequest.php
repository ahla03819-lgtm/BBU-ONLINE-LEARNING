<?php

namespace App\Http\Requests\Academics;

use App\Models\Subject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $m = $this->route('subject');

        return $m ? $this->user()->can('update', $m) : $this->user()->can('create', Subject::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => mb_strtoupper(trim((string) $this->code)), 'is_active' => $this->boolean('is_active')]);
    }

    public function rules(): array
    {
        $id = $this->route('subject')?->id;

        return ['code' => ['required', 'string', 'max:30', Rule::unique('subjects')->ignore($id)], 'name' => ['required', 'string', 'max:150', Rule::unique('subjects')->ignore($id)], 'is_active' => ['required', 'boolean']];
    }
}
