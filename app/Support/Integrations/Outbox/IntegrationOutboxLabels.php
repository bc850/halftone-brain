<?php

namespace App\Support\Integrations\Outbox;

/**
 * Human-readable labels for outbox / delivery statuses and event types.
 */
final class IntegrationOutboxLabels
{
    /**
     * @return array<string, string>
     */
    public static function deliveryStatuses(): array
    {
        return [
            'pending' => 'Waiting',
            'processing' => 'Processing',
            'retrying' => 'Retrying',
            'succeeded' => 'Successful',
            'blocked_configuration' => 'Blocked by configuration',
            'failed' => 'Failed',
            'dead' => 'Dead',
            'abandoned' => 'Abandoned',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function outboxStatuses(): array
    {
        return [
            'pending' => 'Waiting to prepare',
            'processing' => 'Preparing deliveries',
            'dispatched' => 'Deliveries prepared',
            'failed' => 'Preparation failed',
            'dead' => 'Preparation dead',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function eventTypes(): array
    {
        return [
            'quote_revision.accepted' => 'Quote accepted',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function consumers(): array
    {
        return [
            'integrations.diagnostic.accepted_quote_probe' => 'Diagnostic accepted-quote probe',
        ];
    }

    public static function deliveryStatus(string $status): string
    {
        return self::deliveryStatuses()[$status] ?? $status;
    }

    public static function outboxStatus(string $status): string
    {
        return self::outboxStatuses()[$status] ?? $status;
    }

    public static function eventType(string $eventType): string
    {
        return self::eventTypes()[$eventType] ?? $eventType;
    }

    public static function consumer(string $consumerKey): string
    {
        return self::consumers()[$consumerKey] ?? $consumerKey;
    }
}
