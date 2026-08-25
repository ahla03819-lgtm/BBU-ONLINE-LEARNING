<?php

namespace App\Http\Requests\Collaboration;

use App\Models\Channel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CreateChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [Channel::class, $this->route('schoolClass')]);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['slug' => Str::slug((string) ($this->slug ?: $this->name))]);
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100'], 'slug' => ['required', 'string', 'max:120', Rule::unique('channels')->where('school_class_id', $this->route('schoolClass')->id)], 'description' => ['nullable', 'string', 'max:2000']];
    }
}
