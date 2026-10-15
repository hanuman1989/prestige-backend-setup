<?php

namespace App\Http\Requests\Admin;

use App\Models\Package;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePackageBasicDetailsRequest extends FormRequest
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
            'package_id' => ['sometimes', 'integer', Rule::exists('packages', 'id')],
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::unique('packages')->ignore($this->package())],
            'duration' => ['required', 'string', 'max:65535'],
            'tour_best_time' => ['nullable', 'string', 'max:65535'],
            'is_special_package' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'boolean'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_keyword' => ['nullable', 'string', 'max:65535'],
            'meta_description' => ['nullable', 'string', 'max:65535'],
        ];
    }

    public function package(): ?Package
    {
        if ($this->route('package') instanceof Package) {
            return $this->route('package');
        }

        $packageId = $this->input('package_id');

        return filter_var($packageId, FILTER_VALIDATE_INT) !== false
            ? Package::find($packageId)
            : null;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['slug.regex' => 'The slug must contain lowercase letters, numbers, and single hyphens between words.'];
    }
}
