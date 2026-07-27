<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesQuoteDraft;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared payload for section and note lines: presentation only, no money.
 */
class StoreQuotePresentationLineRequest extends FormRequest
{
    use AuthorizesQuoteDraft;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'expected_lock_version' => $this->lockVersionRules(),
            'name' => ['required', 'string', 'max:255'],
            'customer_description' => ['nullable', 'string', 'max:20000'],
        ];
    }
}
