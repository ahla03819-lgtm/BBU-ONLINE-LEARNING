<?php

namespace App\Http\Requests\Academics;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSchoolClassCoverRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()->can('update', $this->route('schoolClass')); }
    public function rules(): array { return ['cover' => ['required', 'file', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120']]; }
}
