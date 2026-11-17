<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::in(['1024x1024', '1536x1024', '1024x1536'])],
            'quality' => ['required', Rule::in(['low', 'medium', 'high'])],
            'reference_images' => ['sometimes', 'array', 'max:4'],
            'reference_images.*' => ['url:http,https', 'max:2048'],
        ];
    }

    /**
     * @return array{prompt: string, size: string, quality: string, reference_images: array<int, string>}
     */
    public function params(): array
    {
        $validated = $this->validated();

        return [
            'prompt' => $validated['prompt'] ?? '',
            'size' => $validated['size'],
            'quality' => $validated['quality'],
            'reference_images' => array_values(array_filter($validated['reference_images'] ?? [])),
        ];
    }
}
