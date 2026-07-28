<?php

namespace App\Enums;

/**
 * How an organization chooses which jurisdiction sources a taxable sale.
 *
 * This records the organization's configured intent. It is not a legal
 * determination, and a billing address alone never decides the jurisdiction.
 */
enum TaxSourcingStrategy: string
{
    case Delivery = 'delivery';
    case Origin = 'origin';
    case BillingAddress = 'billing_address';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Delivery => 'Delivery location',
            self::Origin => 'Origin location',
            self::BillingAddress => 'Billing address',
            self::Manual => 'Manually selected',
        };
    }
}
