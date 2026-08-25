<?php

namespace App\Http\Requests\Collaboration;

use App\Models\Announcement;
use Illuminate\Foundation\Http\FormRequest;

class CreateAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [Announcement::class, $this->route('channel')]);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_pinned' => $this->boolean('is_pinned')]);
    }

    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:200'], 'body' => ['required', 'string', 'max:20000'], 'expires_at' => ['nullable', 'date', 'after:now'], 'is_pinned' => ['boolean']];
    }
}
