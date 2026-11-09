<?php

namespace App\Http\Requests\Site;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A transfer receipt from a guest. The file is kept on the private disk, never in public storage.
 */
class UploadPaymentProofRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'proof.mimes' => 'Upload a JPG, PNG, WebP or PDF file.',
            'proof.max' => 'The file must be 5 MB or smaller.',
        ];
    }
}
