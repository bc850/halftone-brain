<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesQuoteApprovalDecision;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ApproveQuoteApprovalRequest extends FormRequest
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
        ];
    }
}
