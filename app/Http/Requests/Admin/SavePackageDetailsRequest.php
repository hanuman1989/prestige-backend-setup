<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SavePackageDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-packages') ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'current_step' => ['prohibited'],
            'description' => ['required', 'string'],
            'inclusion' => ['nullable', 'string'],
            'exclusion' => ['nullable', 'string'],
            'accommodation_details' => ['nullable', 'string'],
            'transportation' => ['nullable', 'string'],
        ];
    }
}
