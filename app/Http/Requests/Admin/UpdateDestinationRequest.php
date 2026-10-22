<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateDestinationRequest extends FormRequest
{
    /**
     * PHP 8.2 only parses multipart uploads sent using POST.
     */
    protected function prepareForValidation(): void
    {
        if (in_array($this->getRealMethod(), ['PUT', 'PATCH'], true)
            && str_starts_with(strtolower((string) $this->header('Content-Type')), 'multipart/form-data')) {
            throw ValidationException::withMessages([
                '_method' => 'Send form-data updates using POST with _method set to PUT or PATCH, or send a JSON body with PUT or PATCH.',
            ]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('manage-destinations') ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::unique('destinations')->ignore($this->route('destination'))],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meta_keyword' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'description' => ['sometimes', 'nullable', 'string'],
            'destination_type' => ['sometimes', 'required', 'integer', Rule::in([1, 2])],
            'is_most_popular' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'boolean'],
            'image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:2048'],
            'banner' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:10240'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug must contain lowercase letters, numbers, and single hyphens between words.',
        ];
    }
}
