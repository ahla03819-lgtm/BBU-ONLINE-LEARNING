<?php

namespace App\Http\Requests\Collaboration;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('channel'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['slug' => Str::slug((string) ($this->slug ?: $this->name))]);
    }

    public function rules(): array
    {
        $channel = $this->route('channel');

        return ['name' => ['required', 'string', 'max:100'], 'slug' => ['required', 'string', 'max:120', Rule::unique('channels')->where('school_class_id', $channel->school_class_id)->ignore($channel->id)], 'description' => ['nullable', 'string', 'max:2000']];
    }
}
