<?php

namespace App\Http\Requests\MyAccount;

use App\Support\Locale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLocaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'locale' => ['required', 'string', Rule::in(Locale::SUPPORTED)],
        ];
    }
}
