<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AcceptPublicQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'typed_name' => ['required', 'string', 'max:255'],
            'terms_accepted' => ['accepted'],
        ];
    }

    public function typedName(): string
    {
        return trim((string) $this->validated('typed_name'));
    }

    public function termsAccepted(): bool
    {
        return $this->boolean('terms_accepted');
    }
}
