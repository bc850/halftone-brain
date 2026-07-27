<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesQuoteDraft;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReorderQuoteLinesRequest extends FormRequest
{
    use AuthorizesQuoteDraft;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'expected_lock_version' => $this->lockVersionRules(),
            'line_ids' => ['required', 'array', 'min:1'],
            'line_ids.*' => ['integer'],
        ];
    }

    /**
     * @return list<int>
     */
    public function orderedLineIds(): array
    {
        /** @var list<mixed> $ids */
        $ids = $this->validated('line_ids');

        return array_map(static fn (mixed $id): int => (int) $id, $ids);
    }
}
