<?php

namespace App\Support\Integrations\Outbox;

use App\Models\IntegrationOutbox;
use App\Models\IntegrationOutboxDelivery;

interface IntegrationConsumerHandler
{
    public function consumerKey(): string;

    public function supports(string $eventType, int $schemaVersion): bool;

    public function handle(IntegrationOutbox $outbox, IntegrationOutboxDelivery $delivery): IntegrationConsumerResult;
}
