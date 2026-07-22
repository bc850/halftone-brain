<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Models\Vendor;

final class VendorResource
{
    /**
     * @return array<string, mixed>
     */
    public static function make(Vendor $vendor, User $user): array
    {
        $canViewDetails = $user->can('viewDetails', $vendor);

        $payload = [
            'id' => $vendor->id,
            'name' => $vendor->name,
            'is_active' => $vendor->is_active,
        ];

        if ($canViewDetails) {
            $payload['account_number'] = $vendor->account_number;
            $payload['phone'] = $vendor->phone;
            $payload['email'] = $vendor->email;
            $payload['website'] = $vendor->website;
            $payload['notes'] = $vendor->notes;
        }

        if ($vendor->relationLoaded('products')) {
            $payload['products'] = ProductResource::collection($vendor->products, $user);
        }

        if (isset($vendor->products_count)) {
            $payload['products_count'] = $vendor->products_count;
        }

        return $payload;
    }
}
