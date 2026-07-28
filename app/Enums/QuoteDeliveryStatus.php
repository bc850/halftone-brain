<?php

namespace App\Enums;

enum QuoteDeliveryStatus: string
{
    case Pending = 'pending';
    case ProviderAccepted = 'provider_accepted';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case ManuallyRecorded = 'manually_recorded';

    /**
     * Whether this delivery status may mark the revision as customer-visible sent.
     *
     * Queued/pending attempts are delivery attempts, not sends.
     */
    public function marksRevisionSent(): bool
    {
        return match ($this) {
            self::ProviderAccepted, self::ManuallyRecorded => true,
            self::Pending, self::Failed, self::Cancelled => false,
        };
    }
}
