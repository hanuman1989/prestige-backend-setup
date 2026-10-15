<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePackageRelationsRequest extends FormRequest
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
            'category_ids' => ['required', 'array', 'min:1', 'max:100'],
            'category_ids.*' => ['required', 'integer', 'distinct', Rule::exists('categories', 'id')],
            'destination_ids' => ['required', 'array', 'min:1', 'max:100'],
            'destination_ids.*' => ['required', 'integer', 'distinct', Rule::exists('destinations', 'id')],
        ];
    }
}
