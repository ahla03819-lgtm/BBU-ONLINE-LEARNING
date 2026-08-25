<?php

namespace App\Http\Requests\Collaboration;

use Illuminate\Foundation\Http\FormRequest;

class ArchiveAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('archive', $this->route('announcement'));
    }

    public function rules(): array
    {
        return [];
    }
}
