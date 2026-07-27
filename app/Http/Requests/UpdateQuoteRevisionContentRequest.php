<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesQuoteDraft;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateQuoteRevisionContentRequest extends FormRequest
{
    use AuthorizesQuoteDraft;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'expected_lock_version' => $this->lockVersionRules(),
            'introduction' => ['nullable', 'string', 'max:20000'],
            'terms_text' => ['nullable', 'string', 'max:20000'],
            'customer_notes' => ['nullable', 'string', 'max:20000'],
            'internal_notes' => ['nullable', 'string', 'max:20000'],
            'expiration_date' => ['nullable', 'date'],
        ];
    }

    /**
     * Only keys the client actually submitted are forwarded, so a partial edit
     * never blanks a field the form did not render.
     *
     * @return array<string, mixed>
     */
    public function contentChanges(): array
    {
        $fields = ['introduction', 'terms_text', 'customer_notes', 'internal_notes', 'expiration_date'];
        $validated = $this->validated();

        $changes = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $validated)) {
                $changes[$field] = $validated[$field];
            }
        }

        return $changes;
    }
}
