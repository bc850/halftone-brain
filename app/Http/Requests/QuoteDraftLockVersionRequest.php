<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesQuoteDraft;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload for draft mutations whose only input is the revision lock version:
 * line and adjustment deletion, reprice, and override reset.
 */
class QuoteDraftLockVersionRequest extends FormRequest
{
    use AuthorizesQuoteDraft;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'expected_lock_version' => $this->lockVersionRules(),
            'preserve_override' => ['nullable', 'boolean'],
        ];
    }

    public function preserveOverride(): bool
    {
        return $this->boolean('preserve_override', true);
    }
}
