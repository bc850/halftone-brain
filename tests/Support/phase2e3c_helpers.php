<?php

use App\Support\Integrations\Monday\FakeMondayApiClient;
use App\Support\Integrations\Monday\HttpMondayApiClient;
use Illuminate\Support\Facades\Log;
use Tests\Support\Phase2E3CHelpers;

function phase2e3cBindTestCredentials(string $token = 'test-monday-personal-token'): void
{
    Phase2E3CHelpers::bindTestCredentials($token);
}

function phase2e3cClearCredentials(): void
{
    Phase2E3CHelpers::clearCredentials();
}

/**
 * @param  array<string, mixed>  $ctx
 */
function phase2e3cEstablishTenant(array $ctx): void
{
    Phase2E3CHelpers::establishTenant($ctx);
}

function phase2e3cBuildHttpClient(int $maxResponseBytes = 1_048_576): HttpMondayApiClient
{
    return Phase2E3CHelpers::buildHttpClient($maxResponseBytes);
}

function phase2e3cBindFakeClient(?FakeMondayApiClient $client = null): FakeMondayApiClient
{
    return Phase2E3CHelpers::bindFakeClient($client);
}

function phase2e3cFullColumnMapping(): array
{
    return Phase2E3CHelpers::fullColumnMapping();
}

function phase2e3cAcceptedQuoteFixture(array $settingsOverrides = [], bool $withOptionalPartyFields = true): array
{
    return Phase2E3CHelpers::acceptedQuoteFixture($settingsOverrides, $withOptionalPartyFields);
}

function phase2e3cReconciliationFixture(): array
{
    return Phase2E3CHelpers::reconciliationFixture();
}

function phase2e3cConsumerFixture(array $settingsOverrides = [], bool $createSettings = true): array
{
    return Phase2E3CHelpers::consumerFixture($settingsOverrides, $createSettings);
}

/**
 * @return list<array{level: mixed, message: mixed, context: mixed}>
 */
function phase2e3cStartLogCapture(): array
{
    $messages = [];
    Log::listen(function ($level, $message, $context = []) use (&$messages): void {
        $messages[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
    });

    return $messages;
}

function phase2e3cAssertLogsExcludeToken(array $messages, string $token): void
{
    $encoded = json_encode($messages) ?: '';

    expect($encoded)->not->toContain($token)
        ->and($encoded)->not->toContain('Bearer');
}
