<?php

namespace App\Support\Integrations\Outbox;

use InvalidArgumentException;

/**
 * Provider-neutral registry: declared event types → approved consumer handlers.
 */
final class IntegrationConsumerRegistry
{
    /**
     * @var array<string, true>
     */
    private array $declaredEventTypes = [];

    /**
     * @var array<string, array<string, IntegrationConsumerHandler>>
     */
    private array $handlersByEvent = [];

    public function declareEventType(string $eventType): void
    {
        $this->assertEventType($eventType);
        $this->declaredEventTypes[$eventType] = true;
        $this->handlersByEvent[$eventType] ??= [];
    }

    public function register(string $eventType, IntegrationConsumerHandler $handler): void
    {
        $this->declareEventType($eventType);

        $key = $handler->consumerKey();

        if ($key === '' || strlen($key) > 191) {
            throw new InvalidArgumentException('Consumer key must be a non-empty string up to 191 characters.');
        }

        if (isset($this->handlersByEvent[$eventType][$key])) {
            throw new InvalidArgumentException("Consumer [{$key}] is already registered for [{$eventType}].");
        }

        $this->handlersByEvent[$eventType][$key] = $handler;
    }

    public function isKnownEventType(string $eventType): bool
    {
        return isset($this->declaredEventTypes[$eventType]);
    }

    /**
     * @return list<IntegrationConsumerHandler>
     */
    public function handlersFor(string $eventType): array
    {
        if (! $this->isKnownEventType($eventType)) {
            return [];
        }

        return array_values($this->handlersByEvent[$eventType] ?? []);
    }

    public function handler(string $eventType, string $consumerKey): ?IntegrationConsumerHandler
    {
        return $this->handlersByEvent[$eventType][$consumerKey] ?? null;
    }

    /**
     * @return list<string>
     */
    public function declaredEventTypes(): array
    {
        return array_keys($this->declaredEventTypes);
    }

    private function assertEventType(string $eventType): void
    {
        if ($eventType === '' || strlen($eventType) > 191) {
            throw new InvalidArgumentException('Event type must be a non-empty string up to 191 characters.');
        }
    }
}
