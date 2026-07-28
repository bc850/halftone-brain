<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesQuoteDraft;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Withdrawing a pending submission or reopening an approved revision for editing.
 * Neither writes a decision, so the only payload is the state the client saw.
 */
class QuoteApprovalTransitionRequest extends FormRequest
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
        ];
    }

    public function expectedQuoteLockVersion(): int
    {
        return (int) $this->validated('expected_quote_lock_version');
    }
}
