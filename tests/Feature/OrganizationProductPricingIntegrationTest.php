<?php

use App\Enums\OverheadMode;
use App\Enums\PricingMethod;
use App\Models\Organization;
use App\Models\OrganizationProduct;
use App\Models\ParentAccount;
use App\Models\Product;
use App\Support\Money;
use App\Support\Pricing\InvalidPricingException;
use App\Support\Pricing\OrganizationProductPricingMapper;
use App\Support\Pricing\PricingCalculator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('organization product maps to pricing input and calculates without writing', function () {
    $parent = ParentAccount::factory()->create();
    $organization = Organization::factory()->create(['parent_account_id' => $parent->id]);
    $product = Product::factory()->create([
        'parent_account_id' => $parent->id,
        'true_cost_micro_units' => Money::dollarsToMicroUnits('999'),
        'list_price_cents' => 99900,
        'markup_basis_points' => 9999,
    ]);

    $organizationProduct = OrganizationProduct::factory()->create([
        'parent_account_id' => $parent->id,
        'organization_id' => $organization->id,
        'product_id' => $product->id,
        'material_cost_micro_units' => Money::dollarsToMicroUnits('40'),
        'labor_cost_micro_units' => Money::dollarsToMicroUnits('30'),
        'overhead_mode' => OverheadMode::Fixed,
        'overhead_amount_micro_units' => Money::dollarsToMicroUnits('10'),
        'pricing_method' => PricingMethod::Markup,
        'markup_basis_points' => Money::percentToBasisPoints('50'),
        'pricing_version' => 3,
    ]);

    $before = $organizationProduct->fresh();
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $input = $organizationProduct->toPricingInput('2');
    $result = (new PricingCalculator)->calculate($input);

    expect($queries)->toBe(0)
        ->and($result->calculatedUnitPriceCents)->toBe(12000)
        ->and($result->finalUnitPriceCents)->toBe(12000)
        ->and($result->extendedPriceCents)->toBe(24000)
        ->and($result->pricingVersion)->toBe(3);

    $after = $organizationProduct->fresh();

    expect($after->pricing_version)->toBe(3)
        ->and($after->updated_at?->equalTo($before->updated_at))->toBeTrue();

    // Product Master legacy fields must not influence OP pricing.
    expect($input->materialCostMicroUnits)->toBe(Money::dollarsToMicroUnits('40'))
        ->and($input->materialCostMicroUnits)->not->toBe($product->true_cost_micro_units);
});

test('organization product enums cast correctly for pricing mapper', function () {
    $organizationProduct = OrganizationProduct::factory()->create([
        'overhead_mode' => OverheadMode::Rate,
        'overhead_rate_basis_points' => 1250,
        'pricing_method' => PricingMethod::TargetMargin,
        'target_margin_basis_points' => 2500,
    ]);

    $fresh = $organizationProduct->fresh();

    expect($fresh->overhead_mode)->toBe(OverheadMode::Rate)
        ->and($fresh->pricing_method)->toBe(PricingMethod::TargetMargin)
        ->and($fresh->allow_price_override)->toBeFalse()
        ->and($fresh->is_available)->toBeTrue();

    $input = (new OrganizationProductPricingMapper)->toPricingInput($fresh);

    expect($input->overheadMode)->toBe(OverheadMode::Rate)
        ->and($input->pricingMethod)->toBe(PricingMethod::TargetMargin);
});

test('cross-parent organization product linkage remains rejected', function () {
    $parentA = ParentAccount::factory()->create();
    $parentB = ParentAccount::factory()->create();
    $organizationA = Organization::factory()->create(['parent_account_id' => $parentA->id]);
    $productB = Product::factory()->create(['parent_account_id' => $parentB->id]);

    expect(fn () => OrganizationProduct::factory()->create([
        'parent_account_id' => $parentA->id,
        'organization_id' => $organizationA->id,
        'product_id' => $productB->id,
    ]))->toThrow(QueryException::class);
});

test('mapper rejects incomplete organization product identity', function () {
    $organizationProduct = OrganizationProduct::factory()->make([
        'parent_account_id' => 0,
        'organization_id' => 0,
        'product_id' => 0,
    ]);

    expect(fn () => (new OrganizationProductPricingMapper)->toPricingInput($organizationProduct))
        ->toThrow(InvalidPricingException::class, 'Organization product is missing required tenant or product identity.');
});
