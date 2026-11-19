<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationRequest extends FormRequest
{
    /** Route is already behind auth middleware; any signed-in user may generate. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Mirror Pixelforge's own limits so we reject bad input before spending a call.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::in(['1024x1024', '1536x1024', '1024x1536'])],
            'quality' => ['required', Rule::in(['low', 'medium', 'high'])],
            'reference_images' => ['nullable', 'array', 'max:4'],
            'reference_images.*' => ['url', 'max:2048'],
        ];
    }
}
