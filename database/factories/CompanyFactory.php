<?php

namespace Database\Factories;

use App\Enums\SalesTaxStatus;
use App\Models\Company;
use App\Models\ParentAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parent_account_id' => ParentAccount::factory(),
            'name' => fake()->company(),
            'owner_id' => User::factory(),
            'phone' => fake()->optional()->phoneNumber(),
            'email' => fake()->optional()->companyEmail(),
            'billing_address_line1' => fake()->streetAddress(),
            'billing_city' => fake()->city(),
            'billing_state' => fake()->stateAbbr(),
            'billing_postal_code' => fake()->postcode(),
            'billing_country' => 'US',
            'shipping_address_line1' => fake()->streetAddress(),
            'shipping_city' => fake()->city(),
            'shipping_state' => fake()->stateAbbr(),
            'shipping_postal_code' => fake()->postcode(),
            'shipping_country' => 'US',
            'sales_tax_status' => SalesTaxStatus::Taxable,
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
