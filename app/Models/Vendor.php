<?php

namespace App\Models;

use Database\Factories\VendorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'parent_account_id',
    'name',
    'account_number',
    'phone',
    'email',
    'website',
    'notes',
    'is_active',
])]
class Vendor extends Model
{
    /** @use HasFactory<VendorFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Legacy products that still point at this vendor via products.vendor_id.
     *
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Parent-scoped vendor product offerings.
     *
     * @return HasMany<VendorProductOffering, $this>
     */
    public function vendorProductOfferings(): HasMany
    {
        return $this->hasMany(VendorProductOffering::class);
    }
}
