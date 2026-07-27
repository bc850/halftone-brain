<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesQuoteDraft;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CloneQuoteRevisionRequest extends FormRequest
{
    use AuthorizesQuoteDraft;

    /**
     * Cloning creates a new revision on the quote, so the guarded version is the quote's.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'expected_lock_version' => $this->lockVersionRules(),
        ];
    }
}
