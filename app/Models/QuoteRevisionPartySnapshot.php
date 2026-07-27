<?php

namespace App\Models;

use App\Models\Concerns\GuardsImmutableQuoteRevisionChildren;
use Database\Factories\QuoteRevisionPartySnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable customer-visible party identity for a QuoteRevision.
 *
 * @property int $id
 * @property int $parent_account_id
 * @property int $organization_id
 * @property int $quote_id
 * @property int $quote_revision_id
 * @property string $selling_organization_name
 * @property string $selling_organization_slug
 * @property array<string, mixed>|null $selling_organization_display_json
 * @property int $company_id
 * @property string $customer_company_name
 * @property int $organization_company_id
 * @property string|null $customer_number
 * @property int|null $primary_contact_id
 * @property string|null $contact_name
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property array<string, mixed>|null $billing_address_json
 * @property array<string, mixed>|null $service_address_json
 * @property int|null $salesperson_membership_id
 * @property int|null $salesperson_user_id
 * @property string|null $salesperson_name
 * @property string|null $salesperson_email
 * @property int $preparer_membership_id
 * @property int $preparer_user_id
 * @property string $preparer_name
 * @property string|null $preparer_email
 * @property string|null $customer_po_reference
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'parent_account_id',
    'organization_id',
    'quote_id',
    'quote_revision_id',
    'selling_organization_name',
    'selling_organization_slug',
    'selling_organization_display_json',
    'company_id',
    'customer_company_name',
    'organization_company_id',
    'customer_number',
    'primary_contact_id',
    'contact_name',
    'contact_email',
    'contact_phone',
    'billing_address_json',
    'service_address_json',
    'salesperson_membership_id',
    'salesperson_user_id',
    'salesperson_name',
    'salesperson_email',
    'preparer_membership_id',
    'preparer_user_id',
    'preparer_name',
    'preparer_email',
    'customer_po_reference',
])]
class QuoteRevisionPartySnapshot extends Model
{
    use GuardsImmutableQuoteRevisionChildren;

    /** @use HasFactory<QuoteRevisionPartySnapshotFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'selling_organization_display_json' => 'array',
            'billing_address_json' => 'array',
            'service_address_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<QuoteRevision, $this>
     */
    public function quoteRevision(): BelongsTo
    {
        return $this->belongsTo(QuoteRevision::class);
    }

    /**
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }
}
