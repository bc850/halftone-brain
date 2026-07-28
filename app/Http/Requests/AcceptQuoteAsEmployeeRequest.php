<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesQuoteDraft;
use App\Models\Quote;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AcceptQuoteAsEmployeeRequest extends FormRequest
{
    use AuthorizesQuoteDraft;

    public function authorize(): bool
    {
        $quote = $this->route('quote');

        return $quote instanceof Quote && ($this->user()?->can('recordCustomerResponse', $quote) ?? false);
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
            'typed_name' => ['required', 'string', 'max:255'],
            'terms_accepted' => ['accepted'],
            'employee_recorded_reason' => ['required', 'string', 'max:2000'],
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

    public function typedName(): string
    {
        return trim((string) $this->validated('typed_name'));
    }

    public function termsAccepted(): bool
    {
        return $this->boolean('terms_accepted');
    }

    public function employeeRecordedReason(): string
    {
        return trim((string) $this->validated('employee_recorded_reason'));
    }
}
