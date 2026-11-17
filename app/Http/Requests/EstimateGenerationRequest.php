<?php

namespace App\Http\Requests;

class EstimateGenerationRequest extends StoreGenerationRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['prompt'] = ['sometimes', 'string', 'max:4000'];

        return $rules;
    }

    /**
     * @return array{prompt: string, size: string, quality: string, reference_images: array<int, string>}
     */
    public function params(): array
    {
        $params = parent::params();
        $params['prompt'] = $params['prompt'] ?? '';

        return $params;
    }
}
