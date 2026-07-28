<?php

namespace App\Models;

use Database\Factories\OrganizationCompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $company_id
 * @property int $parent_account_id
 * @property string $lifecycle_status
 * @property string $relationship_status
 * @property bool $is_flagged
 * @property string|null $flagged_reason
 * @property int|null $sales_owner_membership_id
 * @property string|null $payment_terms
 * @property bool $credit_hold
 * @property string|null $customer_number
 * @property string $tax_posture
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'organization_id',
    'company_id',
    'parent_account_id',
    'lifecycle_status',
    'relationship_status',
    'is_flagged',
    'flagged_reason',
    'sales_owner_membership_id',
    'payment_terms',
    'credit_hold',
    'customer_number',
    'tax_posture',
    'notes',
])]
class OrganizationCompany extends Model
{
    /** @use HasFactory<OrganizationCompanyFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_flagged' => 'boolean',
            'credit_hold' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<ParentAccount, $this>
     */
    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(ParentAccount::class);
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function salesOwnerMembership(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'sales_owner_membership_id');
    }

    /**
     * @return HasMany<OrganizationCompanyTaxCertificate, $this>
     */
    public function taxCertificates(): HasMany
    {
        return $this->hasMany(OrganizationCompanyTaxCertificate::class);
    }
}
