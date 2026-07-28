<?php

namespace App\Support\Quotes\Documents;

use App\Support\Quotes\Snapshots\CustomerSafeQuoteProjection;
use App\Support\Quotes\Totals\QuoteLineCalculationInput;
use App\Support\Quotes\Totals\QuoteTotalsResult;
use InvalidArgumentException;
use JsonException;

/**
 * Pure document integrity helpers for customer-safe quote payloads.
 *
 * Canonicalization recursively sorts object keys before hashing so the same
 * logical payload always yields the same SHA-256 digest.
 */
final class CustomerQuoteDocumentIntegrity
{
    /**
     * @param  list<QuoteLineCalculationInput>  $lineInputs
     * @return array<string, mixed>
     */
    public function buildCustomerPayload(QuoteTotalsResult $totals, array $lineInputs = [], ?string $termsText = null): array
    {
        $projection = (new CustomerSafeQuoteProjection)->fromTotals($totals, $lineInputs);

        $payload = [
            'document_type' => 'customer_quote',
            'totals' => $projection,
            'terms_checksum' => $this->termsChecksum($termsText),
        ];

        $this->assertNoForbiddenKeys($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $value
     * @return array<string, mixed>|list<mixed>
     */
    public function canonicalize(array $value): array
    {
        if ($this->isList($value)) {
            $canonical = [];
            foreach ($value as $item) {
                $canonical[] = is_array($item) ? $this->canonicalize($item) : $item;
            }

            return $canonical;
        }

        ksort($value);
        $canonical = [];
        foreach ($value as $key => $item) {
            $canonical[$key] = is_array($item) ? $this->canonicalize($item) : $item;
        }

        return $canonical;
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     */
    public function payloadChecksum(array $payload): string
    {
        return $this->sha256Hex($this->canonicalJson($payload));
    }

    public function fileChecksum(string $contents): string
    {
        return $this->sha256Hex($contents);
    }

    public function termsChecksum(?string $termsText): string
    {
        return $this->sha256Hex((string) $termsText);
    }

    /**
     * Stable identity string for a revision document version.
     */
    public function documentVersionIdentity(int $quoteRevisionId, string $documentType, int $documentVersion): string
    {
        return $quoteRevisionId.':'.$documentType.':'.$documentVersion;
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     * @param  array<string, mixed>|list<mixed>  $shownPayload
     */
    public function assertResponseMatchesShownDocument(array $payload, array $shownPayload): void
    {
        $this->assertResponseMatchesDocument(
            $this->payloadChecksum($payload),
            $this->payloadChecksum($shownPayload),
        );
    }

    /**
     * @throws InvalidArgumentException when the response checksum does not match
     */
    public function assertResponseMatchesDocument(string $responseChecksum, string $documentChecksum): void
    {
        if (! hash_equals($documentChecksum, $responseChecksum)) {
            throw new InvalidArgumentException('Customer response document checksum does not match the shown document.');
        }
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     */
    public function assertNoForbiddenKeys(array $payload): void
    {
        $encoded = $this->canonicalJson($payload);

        foreach (CustomerSafeQuoteProjection::forbiddenKeys() as $key) {
            if (str_contains($encoded, '"'.$key.'"')) {
                throw new InvalidArgumentException("Customer payload must not contain forbidden key [{$key}].");
            }
        }
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     */
    public function canonicalJson(array $payload): string
    {
        try {
            return json_encode(
                $this->canonicalize($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Customer payload could not be canonicalized.', 0, $exception);
        }
    }

    private function sha256Hex(string $value): string
    {
        return hash('sha256', $value);
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }
}
