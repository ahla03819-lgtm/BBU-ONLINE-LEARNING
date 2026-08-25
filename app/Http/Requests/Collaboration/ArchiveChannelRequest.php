<?php

namespace App\Http\Requests\Collaboration;

use Illuminate\Foundation\Http\FormRequest;

class ArchiveChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('archive', $this->route('channel'));
    }

    public function rules(): array
    {
        return [];
    }
}
