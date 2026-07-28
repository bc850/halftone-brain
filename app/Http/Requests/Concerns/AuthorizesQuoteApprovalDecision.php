<?php

namespace App\Http\Requests\Concerns;

use App\Models\QuoteApprovalRequest;

/**
 * Queue decisions authorize against the quote behind the request, and carry both
 * lock versions so a decision made against a stale queue row is refused rather
 * than applied to a quote that has since moved.
 */
trait AuthorizesQuoteApprovalDecision
{
    public function authorize(): bool
    {
        $approvalRequest = $this->route('approvalRequest');

        if (! $approvalRequest instanceof QuoteApprovalRequest) {
            return false;
        }

        $quote = $approvalRequest->quote;

        return $quote !== null && ($this->user()?->can('approve', $quote) ?? false);
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

    public function expectedQuoteLockVersion(): int
    {
        return (int) $this->validated('expected_quote_lock_version');
    }
}
