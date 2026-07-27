<?php

namespace App\Http\Resources;

use App\Models\QuoteRevisionPartySnapshot;

final class QuotePartySnapshotResource
{
    /**
     * @return array<string, mixed>|null
     */
    public static function make(?QuoteRevisionPartySnapshot $snapshot): ?array
    {
        if ($snapshot === null) {
            return null;
        }

        return [
            'id' => $snapshot->id,
            'selling_organization_name' => $snapshot->selling_organization_name,
            'selling_organization_slug' => $snapshot->selling_organization_slug,
            'company_id' => $snapshot->company_id,
            'customer_company_name' => $snapshot->customer_company_name,
            'customer_number' => $snapshot->customer_number,
            'primary_contact_id' => $snapshot->primary_contact_id,
            'contact_name' => $snapshot->contact_name,
            'contact_email' => $snapshot->contact_email,
            'contact_phone' => $snapshot->contact_phone,
            'billing_address' => $snapshot->billing_address_json,
            'service_address' => $snapshot->service_address_json,
            'salesperson_name' => $snapshot->salesperson_name,
            'salesperson_email' => $snapshot->salesperson_email,
            'preparer_name' => $snapshot->preparer_name,
            'preparer_email' => $snapshot->preparer_email,
            'customer_po_reference' => $snapshot->customer_po_reference,
        ];
    }
}
