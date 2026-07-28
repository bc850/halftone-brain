<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesQuoteDraft;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Recomputing why a revision would need approval reads nothing from the client
 * except the request for a manual escalation.
 */
class EvaluateQuoteApprovalRequest extends FormRequest
{
    use AuthorizesQuoteDraft;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'manual_escalation' => ['nullable', 'boolean'],
        ];
    }

    public function manualEscalation(): bool
    {
        return $this->boolean('manual_escalation');
    }
}
