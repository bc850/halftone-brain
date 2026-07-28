<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesQuoteApprovalDecision;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RejectQuoteApprovalRequest extends FormRequest
{
    use AuthorizesQuoteApprovalDecision;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'expected_lock_version' => $this->lockVersionRules(),
            'expected_quote_lock_version' => $this->lockVersionRules(),
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
