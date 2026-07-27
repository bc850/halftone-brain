<?php

namespace App\Http\Requests;

use App\Enums\QuoteAdjustmentMethod;
use App\Http\Requests\Concerns\AuthorizesQuoteDraft;
use App\Http\Requests\Concerns\NormalizesQuoteAdjustmentValue;
use App\Models\QuoteRevisionAdjustment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuoteAdjustmentRequest extends FormRequest
{
    use AuthorizesQuoteDraft;
    use NormalizesQuoteAdjustmentValue;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'expected_lock_version' => $this->lockVersionRules(),
            'description' => ['sometimes', 'string', 'max:255'],
            'method' => ['sometimes', Rule::enum(QuoteAdjustmentMethod::class)],
            'value' => ['sometimes', 'regex:/^\d+(\.\d{1,2})?$/'],
            'is_taxable' => ['sometimes', 'boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adjustmentChanges(): array
    {
        /** @var QuoteRevisionAdjustment $adjustment */
        $adjustment = $this->route('adjustment');

        $validated = $this->validated();
        $changes = [];

        if (array_key_exists('description', $validated)) {
            $changes['description_snapshot'] = $validated['description'];
        }

        $method = array_key_exists('method', $validated)
            ? QuoteAdjustmentMethod::from((string) $validated['method'])
            : $adjustment->method;

        if (array_key_exists('method', $validated)) {
            $changes['method'] = $method;
        }

        if (array_key_exists('value', $validated)) {
            $changes['input_value'] = $this->adjustmentInputValue($method, $validated['value']);
        }

        if (array_key_exists('is_taxable', $validated)) {
            $changes['is_taxable'] = (bool) $validated['is_taxable'];
        }

        if (array_key_exists('reason', $validated)) {
            $changes['reason'] = $validated['reason'];
        }

        return $changes;
    }
}
