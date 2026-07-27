<?php

namespace App\Http\Requests\Concerns;

use App\Models\Quote;

/**
 * Every draft mutation authorizes against the parent quote and carries the
 * revision lock version the client last rendered.
 */
trait AuthorizesQuoteDraft
{
    public function authorize(): bool
    {
        $quote = $this->route('quote');

        return $quote instanceof Quote && ($this->user()?->can('update', $quote) ?? false);
    }

    /**
     * @return list<string>
     */
    protected function lockVersionRules(): array
    {
        return ['required', 'integer', 'min:1'];
    }

    public function expectedLockVersion(): int
    {
        return (int) $this->validated('expected_lock_version');
    }
}
