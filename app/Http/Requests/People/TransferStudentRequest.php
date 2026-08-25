<?php

namespace App\Http\Requests\People;

use Illuminate\Foundation\Http\FormRequest;

class TransferStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('transfer', $this->route('enrollment'));
    }

    public function rules(): array
    {
        return ['school_class_id' => ['required', 'exists:school_classes,id', 'different:current_class_id'], 'transferred_on' => ['required', 'date', 'after_or_equal:'.$this->route('enrollment')->enrolled_on->toDateString()], 'reason' => ['required', 'string', 'max:255']];
    }
}
