<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesQuoteDraft;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Submitting moves both the quote and the revision, so both lock versions the
 * client rendered travel with the request.
 */
class SubmitQuoteApprovalRequest extends FormRequest
{
    use AuthorizesQuoteDraft;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'expected_lock_version' => $this->lockVersionRules(),
            'expected_quote_lock_version' => $this->lockVersionRules(),
            'manual_escalation' => ['nullable', 'boolean'],
        ];
    }

    public function expectedQuoteLockVersion(): int
    {
        return (int) $this->validated('expected_quote_lock_version');
    }

    public function manualEscalation(): bool
    {
        return $this->boolean('manual_escalation');
    }
}
