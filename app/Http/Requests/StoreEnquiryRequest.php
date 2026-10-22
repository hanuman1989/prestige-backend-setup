<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEnquiryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'source' => ['required', 'string', Rule::in(['destination', 'package', 'car_rental', 'contact'])],
            'destination_id' => ['nullable', 'integer', Rule::exists('destinations', 'id')],
            'package_id' => ['nullable', 'integer', Rule::exists('packages', 'id')],
            'car_id' => ['nullable', 'integer', Rule::exists('cars', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'mobile' => ['required', 'string', 'max:30'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:65535'],
            'travel_date' => ['nullable', 'date_format:Y-m-d'],
            'adults' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'children' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
