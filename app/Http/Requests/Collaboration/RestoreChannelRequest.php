<?php

namespace App\Http\Requests\Collaboration;

use Illuminate\Foundation\Http\FormRequest;

class RestoreChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('restore', $this->route('channel'));
    }

    public function rules(): array
    {
        return [];
    }
}
