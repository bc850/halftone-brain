<?php

namespace App\Enums;

enum IntegrationProviderReceiptDiscoveryMethod: string
{
    case Created = 'created';
    case IdempotencyReplay = 'idempotency_replay';
    case Reconciled = 'reconciled';
}
