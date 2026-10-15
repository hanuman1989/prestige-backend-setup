<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateCarRequest extends FormRequest
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
        return $this->user()?->can('manage-cars') ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'car_category_id' => ['sometimes', 'required', 'integer', Rule::exists('car_categories', 'id')],
            'brand' => ['sometimes', 'nullable', 'string', 'max:100'],
            'model' => ['sometimes', 'nullable', 'string', 'max:150'],
            'passenger_seats' => ['sometimes', 'required', 'integer', 'min:1', 'max:65535'],
            'luggage_bags' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'transmission' => ['sometimes', 'required', Rule::in(['manual', 'automatic'])],
            'has_ac' => ['sometimes', 'boolean'],
            'rate_basis' => ['sometimes', 'required', Rule::in(['per_km', 'per_day', 'per_trip'])],
            'starting_rate' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'minimum_km_per_day' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
            'driver_allowance_per_day' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'extra_km_rate' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'inclusion' => ['sometimes', 'nullable', 'string'],
            'exclusion' => ['sometimes', 'nullable', 'string'],
            'cancellation_policy' => ['sometimes', 'nullable', 'string'],
            'is_featured' => ['sometimes', 'boolean'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::unique('cars')->ignore($this->route('car'))],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meta_keyword' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'boolean'],
            'image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:2048'],
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
