<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RejectPublicQuoteRequest extends FormRequest
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
            'typed_name' => ['nullable', 'string', 'max:255'],
            'rejection_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function typedName(): string
    {
        $value = $this->validated('typed_name');

        return is_string($value) ? trim($value) : '';
    }

    public function rejectionReason(): ?string
    {
        $value = $this->validated('rejection_reason');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
