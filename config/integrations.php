<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Outbox / delivery processing
    |--------------------------------------------------------------------------
    |
    | Phase 2E.1 durable integration infrastructure. No production scheduler is
    | registered yet — proposed later:
    |   $schedule->command('integrations:materialize-outbox')->everyMinute();
    |   $schedule->command('integrations:process-deliveries')->everyMinute();
    |   $schedule->command('integrations:reclaim-leases')->everyMinute();
    |
    */

    'outbox' => [
        'batch_size' => (int) env('INTEGRATIONS_OUTBOX_BATCH_SIZE', 50),
        'lease_seconds' => (int) env('INTEGRATIONS_OUTBOX_LEASE_SECONDS', 120),
        'max_attempts' => (int) env('INTEGRATIONS_OUTBOX_MAX_ATTEMPTS', 12),
        'backoff_base_seconds' => (int) env('INTEGRATIONS_OUTBOX_BACKOFF_BASE_SECONDS', 5),
        'backoff_max_seconds' => (int) env('INTEGRATIONS_OUTBOX_BACKOFF_MAX_SECONDS', 3600),
    ],

    'deliveries' => [
        'batch_size' => (int) env('INTEGRATIONS_DELIVERY_BATCH_SIZE', 50),
        'lease_seconds' => (int) env('INTEGRATIONS_DELIVERY_LEASE_SECONDS', 120),
        'max_attempts' => (int) env('INTEGRATIONS_DELIVERY_MAX_ATTEMPTS', 12),
        'backoff_base_seconds' => (int) env('INTEGRATIONS_DELIVERY_BACKOFF_BASE_SECONDS', 5),
        'backoff_max_seconds' => (int) env('INTEGRATIONS_DELIVERY_BACKOFF_MAX_SECONDS', 3600),
    ],

    'errors' => [
        'max_message_length' => 500,
    ],

    /*
    | Allowed keys inside provider_reference_json. Never store tokens, headers,
    | or raw request/response bodies.
    */
    'provider_reference_allowed_keys' => [
        'provider',
        'resource_type',
        'remote_id',
        'remote_board_id',
        'remote_url',
        'provider_request_id',
        'idempotency_replayed',
        'api_version',
        'idempotency_key',
    ],

];
