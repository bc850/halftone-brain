<?php

namespace App\Support\Integrations\Outbox;

final class IntegrationDeliveryIdempotency
{
    public static function design(int $outboxId, string $consumerKey): string
    {
        return hash('sha256', 'delivery:'.$outboxId.':'.$consumerKey);
    }
}
