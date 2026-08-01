<?php

namespace Tests\Support;

use App\Models\IntegrationOutbox;
use App\Models\IntegrationOutboxDelivery;
use App\Support\Integrations\Outbox\IntegrationConsumerHandler;
use App\Support\Integrations\Outbox\IntegrationConsumerResult;

final class ScriptedIntegrationConsumer implements IntegrationConsumerHandler
{
    /**
     * @param  list<IntegrationConsumerResult>  $results
     */
    public function __construct(
        private string $key,
        private string $eventType,
        private array $results,
        private int $supportedSchemaVersion = 1,
    ) {}

    public function consumerKey(): string
    {
        return $this->key;
    }

    public function supports(string $eventType, int $schemaVersion): bool
    {
        return $eventType === $this->eventType && $schemaVersion === $this->supportedSchemaVersion;
    }

    public function handle(IntegrationOutbox $outbox, IntegrationOutboxDelivery $delivery): IntegrationConsumerResult
    {
        if ($this->results === []) {
            return IntegrationConsumerResult::succeeded([
                'provider' => 'scripted',
                'resource_type' => 'test',
                'remote_id' => (string) $delivery->id,
            ]);
        }

        return array_shift($this->results);
    }
}
