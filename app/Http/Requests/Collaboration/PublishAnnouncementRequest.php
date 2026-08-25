<?php

namespace App\Http\Requests\Collaboration;

use Illuminate\Foundation\Http\FormRequest;

class PublishAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('publish', $this->route('announcement'));
    }

    public function rules(): array
    {
        return [];
    }
}
