<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesQuoteDraft;
use App\Models\Quote;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RecordManualQuoteDeliveryRequest extends FormRequest
{
    use AuthorizesQuoteDraft;

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
            'expected_lock_version' => $this->lockVersionRules(),
            'expected_quote_lock_version' => $this->lockVersionRules(),
            'quote_customer_access_token_id' => ['required', 'integer', 'min:1'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'recipient_email' => ['required', 'email', 'max:255'],
            'confirmed' => ['accepted'],
            'external_reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function expectedQuoteLockVersion(): int
    {
        return (int) $this->validated('expected_quote_lock_version');
    }

    public function customerAccessTokenId(): int
    {
        return (int) $this->validated('quote_customer_access_token_id');
    }

    public function recipientName(): string
    {
        return trim((string) $this->validated('recipient_name'));
    }

    public function recipientEmail(): string
    {
        return trim((string) $this->validated('recipient_email'));
    }

    public function confirmed(): bool
    {
        return $this->boolean('confirmed');
    }

    public function externalReference(): ?string
    {
        $value = $this->validated('external_reference');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
