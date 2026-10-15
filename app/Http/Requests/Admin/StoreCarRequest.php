<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-cars') ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'car_category_id' => ['required', 'integer', Rule::exists('car_categories', 'id')],
            'brand' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:150'],
            'passenger_seats' => ['required', 'integer', 'min:1', 'max:65535'],
            'luggage_bags' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'transmission' => ['sometimes', 'required', Rule::in(['manual', 'automatic'])],
            'has_ac' => ['sometimes', 'boolean'],
            'rate_basis' => ['sometimes', 'required', Rule::in(['per_km', 'per_day', 'per_trip'])],
            'starting_rate' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'minimum_km_per_day' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'driver_allowance_per_day' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'extra_km_rate' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'inclusion' => ['nullable', 'string'],
            'exclusion' => ['nullable', 'string'],
            'cancellation_policy' => ['nullable', 'string'],
            'is_featured' => ['sometimes', 'boolean'],
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::unique('cars')],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_keyword' => ['nullable', 'string', 'max:65535'],
            'meta_description' => ['nullable', 'string', 'max:65535'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'boolean'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:2048'],
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
