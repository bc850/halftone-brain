<?php

namespace App\Http\Requests\Concerns;

/**
 * A tax rate arrives as a decimal percentage string so it never passes through a
 * float on its way to parts per million.
 */
trait NormalizesTaxRatePercent
{
    /**
     * @return list<string>
     */
    protected function ratePercentRules(): array
    {
        return ['required', 'string', 'regex:/^\d+(\.\d{1,6})?$/', 'max:12'];
    }

    public function ratePercent(): string
    {
        return trim((string) $this->validated('rate_percent'));
    }
}
