<?php

namespace App\Support\Integrations\Outbox;

use App\Models\IntegrationOutbox;
use App\Models\IntegrationOutboxDelivery;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Support\Quotes\Acceptance\QuoteAcceptanceAtomicityContract;

/**
 * Explicit allowlisted projections for operational UI — never dump raw JSON.
 */
final class IntegrationOperationalProjection
{
    private const MAX_ERROR_DISPLAY = 240;

    /**
     * @var list<string>
     */
    private const ACCEPTED_PAYLOAD_KEYS = [
        'quote_id',
        'quote_revision_id',
        'organization_id',
        'document_id',
        'document_version',
    ];

    public function __construct(
        private IntegrationErrorSanitizer $sanitizer,
    ) {}

    /**
     * @return list<array{key: string, label: string, value: string|int|null}>
     */
    public function projectPayload(IntegrationOutbox $outbox): array
    {
        $payload = $outbox->payload_json;

        if ($outbox->event_type !== QuoteAcceptanceAtomicityContract::ACCEPTED_EVENT_TYPE) {
            return [];
        }

        $rows = [];

        foreach (self::ACCEPTED_PAYLOAD_KEYS as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];
            $display = $this->scalarDisplayValue($value);

            if ($display === false) {
                continue;
            }

            $rows[] = [
                'key' => $key,
                'label' => $this->payloadLabel($key),
                'value' => $display,
            ];
        }

        return $rows;
    }

    /**
     * @return array{code: string|null, message: string|null}
     */
    public function projectError(?string $code, ?string $message): array
    {
        $safeMessage = $this->sanitizer->message($message);

        if ($safeMessage !== null && strlen($safeMessage) > self::MAX_ERROR_DISPLAY) {
            $safeMessage = substr($safeMessage, 0, self::MAX_ERROR_DISPLAY).'…';
        }

        return [
            'code' => $this->sanitizer->code($code),
            'message' => $safeMessage,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $reference
     * @return array<string, string|int|null>
     */
    public function projectProviderReference(?array $reference): array
    {
        $filtered = $this->sanitizer->providerReference($reference) ?? [];

        $safe = [];

        foreach ($filtered as $key => $value) {
            if (is_bool($value)) {
                $safe[(string) $key] = $value ? 1 : 0;

                continue;
            }

            if (is_int($value) || is_string($value) || $value === null) {
                $safe[(string) $key] = $value;
            }
        }

        return $safe;
    }

    /**
     * @return array{
     *     quote_id: int|null,
     *     quote_number: string|null,
     *     quote_revision_id: int|null,
     *     deal_id: int|null,
     *     company_name: string|null
     * }
     */
    public function resolveBusinessContext(IntegrationOutbox $outbox): array
    {
        $payload = $outbox->payload_json;
        $quoteId = isset($payload['quote_id']) && is_numeric($payload['quote_id'])
            ? (int) $payload['quote_id']
            : null;
        $revisionId = isset($payload['quote_revision_id']) && is_numeric($payload['quote_revision_id'])
            ? (int) $payload['quote_revision_id']
            : ($outbox->aggregate_type === 'quote_revision' ? (int) $outbox->aggregate_id : null);

        $quoteNumber = null;
        $dealId = null;
        $companyName = null;

        if ($quoteId !== null) {
            $quote = Quote::query()
                ->with(['deal.company', 'organizationCompany.company'])
                ->whereKey($quoteId)
                ->where('organization_id', $outbox->organization_id)
                ->where('parent_account_id', $outbox->parent_account_id)
                ->first();

            if ($quote !== null) {
                $quoteNumber = $quote->quote_number;
                $dealId = $quote->deal_id;
                $companyName = $quote->deal?->company->name
                    ?? $quote->organizationCompany?->company->name;
            }
        } elseif ($revisionId !== null) {
            $revision = QuoteRevision::query()
                ->with(['quote.deal.company'])
                ->whereKey($revisionId)
                ->first();

            if (
                $revision?->quote !== null
                && (int) $revision->quote->organization_id === (int) $outbox->organization_id
            ) {
                $quoteId = (int) $revision->quote->id;
                $quoteNumber = $revision->quote->quote_number;
                $dealId = $revision->quote->deal_id;
                $companyName = $revision->quote->deal?->company->name;
            }
        }

        return [
            'quote_id' => $quoteId,
            'quote_number' => $quoteNumber,
            'quote_revision_id' => $revisionId,
            'deal_id' => $dealId,
            'company_name' => $companyName,
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     consumer_key: string,
     *     consumer_label: string,
     *     status: string,
     *     status_label: string,
     *     attempt_count: int,
     *     available_at: string|null,
     *     locked_at: string|null,
     *     locked_by_worker: string|null,
     *     succeeded_at: string|null,
     *     blocked_at: string|null,
     *     abandoned_at: string|null,
     *     updated_at: string|null,
     *     error: array{code: string|null, message: string|null},
     *     provider_reference: array<string, string|int|null>,
     *     lease_active: bool,
     *     lease_expired: bool,
     *     can_replay: bool,
     *     can_abandon: bool
     * }
     */
    public function projectDelivery(IntegrationOutboxDelivery $delivery, bool $canReplay, bool $canAbandon): array
    {
        $leaseSeconds = (int) config('integrations.deliveries.lease_seconds', 120);
        $leaseActive = $delivery->status->value === 'processing' && $delivery->locked_at !== null;
        $leaseExpired = $leaseActive
            && $delivery->locked_at->lte(now()->subSeconds($leaseSeconds));

        $replayable = in_array($delivery->status->value, ['failed', 'dead', 'blocked_configuration'], true);
        $abandonable = in_array($delivery->status->value, [
            'pending', 'retrying', 'failed', 'dead', 'blocked_configuration', 'processing',
        ], true) && ! ($leaseActive && ! $leaseExpired);

        return [
            'id' => (int) $delivery->id,
            'consumer_key' => $delivery->consumer_key,
            'consumer_label' => IntegrationOutboxLabels::consumer($delivery->consumer_key),
            'status' => $delivery->status->value,
            'status_label' => IntegrationOutboxLabels::deliveryStatus($delivery->status->value),
            'attempt_count' => (int) $delivery->attempt_count,
            'available_at' => $delivery->available_at->toIso8601String(),
            'locked_at' => $delivery->locked_at?->toIso8601String(),
            'locked_by_worker' => $delivery->locked_by_worker,
            'succeeded_at' => $delivery->succeeded_at?->toIso8601String(),
            'blocked_at' => $delivery->blocked_at?->toIso8601String(),
            'abandoned_at' => $delivery->abandoned_at?->toIso8601String(),
            'updated_at' => $delivery->updated_at?->toIso8601String(),
            'error' => $this->projectError($delivery->last_error_code, $delivery->last_error_message),
            'provider_reference' => $this->projectProviderReference($delivery->provider_reference_json),
            'lease_active' => $leaseActive && ! $leaseExpired,
            'lease_expired' => $leaseExpired,
            'can_replay' => $canReplay && $replayable,
            'can_abandon' => $canAbandon && $abandonable,
        ];
    }

    private function payloadLabel(string $key): string
    {
        return match ($key) {
            'quote_id' => 'Quote ID',
            'quote_revision_id' => 'Revision ID',
            'organization_id' => 'Organization ID',
            'document_id' => 'Document ID',
            'document_version' => 'Document version',
            default => $key,
        };
    }

    /**
     * @return string|int|null|false False when the value is not display-safe.
     */
    private function scalarDisplayValue(mixed $value): string|int|null|false
    {
        if ($value === null || is_int($value) || is_string($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_float($value)) {
            return (string) $value;
        }

        return false;
    }
}
