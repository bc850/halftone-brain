<?php

namespace App\Http\Resources;

use App\Models\QuoteDelivery;

/**
 * Delivery-attempt metadata without provider secrets or raw tokens.
 */
final class QuoteDeliveryResource
{
    /**
     * @param  iterable<int, QuoteDelivery>  $deliveries
     * @return list<array<string, mixed>>
     */
    public static function collection(iterable $deliveries): array
    {
        $payload = [];

        foreach ($deliveries as $delivery) {
            $payload[] = self::make($delivery);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public static function make(?QuoteDelivery $delivery): ?array
    {
        if ($delivery === null) {
            return null;
        }

        return [
            'id' => $delivery->id,
            'quote_revision_document_id' => $delivery->quote_revision_document_id,
            'channel' => $delivery->channel->value,
            'status' => $delivery->status->value,
            'recipient_name_snapshot' => $delivery->recipient_name_snapshot,
            'recipient_email_snapshot' => $delivery->recipient_email_snapshot,
            'external_message_id' => $delivery->external_message_id,
            'requested_at' => $delivery->requested_at->toIso8601String(),
            'sent_at' => $delivery->sent_at?->toIso8601String(),
            'failed_at' => $delivery->failed_at?->toIso8601String(),
            'failure_code' => $delivery->failure_code,
            'failure_message' => $delivery->failure_message,
            'created_at' => $delivery->created_at?->toIso8601String(),
        ];
    }
}
