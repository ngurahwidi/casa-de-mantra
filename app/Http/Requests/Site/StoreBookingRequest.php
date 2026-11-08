<?php

namespace App\Http\Requests\Site;

use App\Rules\Turnstile;
use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'adults' => ['required', 'integer', 'min:1'],
            'children' => ['nullable', 'integer', 'min:0'],
            'full_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:191'],
            'phone' => ['required', 'string', 'max:50'],
            'country' => ['nullable', 'string', 'max:100'],
            'special_requests' => ['nullable', 'string', 'max:2000'],
            'policy_accepted' => ['required', 'accepted'],
            'website' => ['nullable', 'prohibited'],
            'cf-turnstile-response' => [new Turnstile],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'policy_accepted.required' => 'Please agree to the non-refundable policy before sending your request.',
            'policy_accepted.accepted' => 'Please agree to the non-refundable policy before sending your request.',
            'website.prohibited' => 'This request could not be processed.',
        ];
    }
}
