<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SavePackagePhotosRequest extends FormRequest
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
            'photos' => ['sometimes', 'array', 'max:100'],
            'photos.*' => ['required', 'array:id,image,sort_order'],
            'photos.*.id' => ['nullable', 'required_without:photos.*.image', 'integer', 'distinct', Rule::exists('package_images', 'id')->where('package_id', $this->route('package')->id)],
            'photos.*.image' => ['nullable', 'required_without:photos.*.id', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:2048'],
            'photos.*.sort_order' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'deleted_image_ids' => ['sometimes', 'array', 'max:100'],
            'deleted_image_ids.*' => ['required', 'integer', 'distinct', Rule::exists('package_images', 'id')->where('package_id', $this->route('package')->id)],
        ];
    }
}
