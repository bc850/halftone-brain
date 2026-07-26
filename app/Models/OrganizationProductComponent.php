<?php

namespace App\Models;

use App\Enums\UnitOfMeasure;
use Database\Factories\OrganizationProductComponentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Fixed estimated material usage for a finished OrganizationProduct.
 *
 * Estimates pricing cost only. Does not consume inventory.
 *
 * @property int $id
 * @property int $parent_account_id
 * @property int $organization_id
 * @property int $organization_product_id
 * @property int $component_organization_product_id
 * @property int $quantity_scaled
 * @property UnitOfMeasure $usage_uom
 * @property int $waste_basis_points
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'parent_account_id',
    'organization_id',
    'organization_product_id',
    'component_organization_product_id',
    'quantity_scaled',
    'usage_uom',
    'waste_basis_points',
    'sort_order',
    'is_active',
])]
class OrganizationProductComponent extends Model
{
    /** @use HasFactory<OrganizationProductComponentFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'waste_basis_points' => 0,
        'sort_order' => 0,
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $component): void {
            $component->assertValidQuantityAndWaste();
            $component->assertNotSelfReference();
            $component->assertTenantAlignment();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_scaled' => 'integer',
            'usage_uom' => UnitOfMeasure::class,
            'waste_basis_points' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ParentAccount, $this>
     */
    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(ParentAccount::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Finished organization product that receives the estimated cost.
     *
     * @return BelongsTo<OrganizationProduct, $this>
     */
    public function organizationProduct(): BelongsTo
    {
        return $this->belongsTo(OrganizationProduct::class);
    }

    /**
     * Raw-material organization product supplying purchase cost.
     *
     * @return BelongsTo<OrganizationProduct, $this>
     */
    public function componentOrganizationProduct(): BelongsTo
    {
        return $this->belongsTo(OrganizationProduct::class, 'component_organization_product_id');
    }

    private function assertValidQuantityAndWaste(): void
    {
        if ($this->quantity_scaled < 1) {
            throw ValidationException::withMessages([
                'quantity_scaled' => 'Component quantity must be greater than zero.',
            ]);
        }

        if ($this->waste_basis_points < 0 || $this->waste_basis_points > 10_000) {
            throw ValidationException::withMessages([
                'waste_basis_points' => 'Waste basis points must be between 0 and 10000.',
            ]);
        }
    }

    private function assertNotSelfReference(): void
    {
        if ($this->organization_product_id === $this->component_organization_product_id) {
            throw ValidationException::withMessages([
                'component_organization_product_id' => 'A product cannot be a component of itself.',
            ]);
        }
    }

    private function assertTenantAlignment(): void
    {
        $parent = OrganizationProduct::query()
            ->whereKey($this->organization_product_id)
            ->first();

        if ($parent === null) {
            throw ValidationException::withMessages([
                'organization_product_id' => 'The finished organization product does not exist.',
            ]);
        }

        $component = OrganizationProduct::query()
            ->whereKey($this->component_organization_product_id)
            ->first();

        if ($component === null) {
            throw ValidationException::withMessages([
                'component_organization_product_id' => 'The component organization product does not exist.',
            ]);
        }

        if ($parent->organization_id !== $this->organization_id
            || $component->organization_id !== $this->organization_id) {
            throw ValidationException::withMessages([
                'organization_id' => 'Finished and component products must belong to the component organization.',
            ]);
        }

        if ($parent->parent_account_id !== $this->parent_account_id
            || $component->parent_account_id !== $this->parent_account_id) {
            throw ValidationException::withMessages([
                'parent_account_id' => 'Finished and component products must belong to the component parent account.',
            ]);
        }
    }
}
