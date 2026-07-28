<?php

namespace App\Http\Requests;

use App\Models\Quote;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RegenerateQuoteCustomerTokenRequest extends FormRequest
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
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'recipient_email' => ['nullable', 'email', 'max:255'],
        ];
    }

    public function recipientName(): ?string
    {
        $value = $this->validated('recipient_name');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function recipientEmail(): ?string
    {
        $value = $this->validated('recipient_email');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
