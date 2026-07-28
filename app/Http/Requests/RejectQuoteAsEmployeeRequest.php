<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesQuoteDraft;
use App\Models\Quote;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RejectQuoteAsEmployeeRequest extends FormRequest
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
            'typed_name' => ['nullable', 'string', 'max:255'],
            'rejection_reason' => ['nullable', 'string', 'max:2000'],
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
        $value = $this->validated('typed_name');

        return is_string($value) ? trim($value) : '';
    }

    public function rejectionReason(): ?string
    {
        $value = $this->validated('rejection_reason');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function employeeRecordedReason(): string
    {
        return trim((string) $this->validated('employee_recorded_reason'));
    }
}
