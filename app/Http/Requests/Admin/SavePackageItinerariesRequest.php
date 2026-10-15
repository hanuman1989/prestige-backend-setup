<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SavePackageItinerariesRequest extends FormRequest
{
    /**
     * PHP 8.2 parses multipart uploads only for actual POST requests.
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
        return $this->user()?->can('manage-packages') ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'current_step' => ['prohibited'],
            'itineraries' => ['required', 'array', 'min:1', 'max:100'],
            'itineraries.*' => ['required', 'array:id,title,description,image,sort_order'],
            'itineraries.*.id' => ['nullable', 'integer', 'distinct', Rule::exists('package_itineraries', 'id')->where('package_id', $this->route('package')->id)],
            'itineraries.*.title' => ['required', 'string', 'max:255'],
            'itineraries.*.description' => ['required', 'string'],
            'itineraries.*.image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:2048'],
            'itineraries.*.sort_order' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'deleted_itinerary_ids' => ['sometimes', 'array', 'max:100'],
            'deleted_itinerary_ids.*' => ['required', 'integer', 'distinct', Rule::exists('package_itineraries', 'id')->where('package_id', $this->route('package')->id)],
        ];
    }
}
