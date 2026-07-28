<?php

namespace App\Http\Requests;

use App\Models\Quote;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RevokeQuoteCustomerTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        $quote = $this->route('quote');

        return $quote instanceof Quote && ($this->user()?->can('send', $quote) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
