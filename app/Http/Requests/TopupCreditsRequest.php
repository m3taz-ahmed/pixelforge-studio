<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TopupCreditsRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'min:1', 'max:500', 'decimal:0,4'],
        ];
    }
}
