<?php

use App\Enums\OverheadMode;
use App\Enums\PricingMethod;
use App\Models\AuditEvent;
use App\Models\OrganizationProduct;
use App\Support\Money;

test('matching pricing version updates and increments once', function () {
    $ctx = createTenantUser('owner', 'parent_owner');
    $op = OrganizationProduct::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
        'material_cost_micro_units' => Money::dollarsToMicroUnits('40'),
        'labor_cost_micro_units' => Money::dollarsToMicroUnits('30'),
        'overhead_mode' => OverheadMode::Fixed,
        'overhead_amount_micro_units' => Money::dollarsToMicroUnits('10'),
        'pricing_method' => PricingMethod::Markup,
        'markup_basis_points' => 5000,
        'pricing_version' => 3,
    ]);

    $this->actingAs($ctx['user'])
        ->patch(route('org.products.update-pricing', [$ctx['organization'], $op]), [
            'pricing_version' => 3,
            'material_cost' => '50',
            'labor_cost' => '30',
            'overhead_mode' => OverheadMode::Fixed->value,
            'overhead_amount' => '10',
            'pricing_method' => PricingMethod::Markup->value,
            'markup_percent' => '50',
        ])
        ->assertRedirect();

    expect($op->fresh()->pricing_version)->toBe(4)
        ->and($op->fresh()->material_cost_micro_units)->toBe(Money::dollarsToMicroUnits('50'))
        ->and(AuditEvent::query()->where('action', 'catalog.organization_product.pricing_updated')->count())->toBe(1);
});

test('stale pricing version returns 409 and changes nothing', function () {
    $ctx = createTenantUser('owner', 'parent_owner');
    $op = OrganizationProduct::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
        'material_cost_micro_units' => Money::dollarsToMicroUnits('40'),
        'labor_cost_micro_units' => Money::dollarsToMicroUnits('30'),
        'overhead_mode' => OverheadMode::None,
        'pricing_method' => PricingMethod::Markup,
        'markup_basis_points' => 5000,
        'pricing_version' => 2,
    ]);
    $beforeAudits = AuditEvent::query()->count();

    $this->actingAs($ctx['user'])
        ->patch(route('org.products.update-pricing', [$ctx['organization'], $op]), [
            'pricing_version' => 1,
            'material_cost' => '99',
            'labor_cost' => '0',
            'overhead_mode' => OverheadMode::None->value,
            'pricing_method' => PricingMethod::Markup->value,
            'markup_percent' => '50',
        ])
        ->assertStatus(409);

    expect($op->fresh()->pricing_version)->toBe(2)
        ->and($op->fresh()->material_cost_micro_units)->toBe(Money::dollarsToMicroUnits('40'))
        ->and(AuditEvent::query()->count())->toBe($beforeAudits);
});

test('settings-only update does not increment pricing version', function () {
    $ctx = createTenantUser('owner', 'parent_owner');
    $op = OrganizationProduct::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'organization_id' => $ctx['organization']->id,
        'pricing_version' => 5,
        'is_available' => true,
    ]);

    $this->actingAs($ctx['user'])
        ->patch(route('org.products.update-settings', [$ctx['organization'], $op]), [
            'display_name' => 'Org display',
            'is_available' => true,
            'is_sellable' => $op->is_sellable,
            'is_purchasable' => $op->is_purchasable,
            'inventory_tracking_mode' => $op->inventory_tracking_mode->value,
            'purchase_unit_of_measure' => $op->purchase_unit_of_measure?->value,
            'stock_unit_of_measure' => $op->stock_unit_of_measure?->value,
            'usage_unit_of_measure' => $op->usage_unit_of_measure?->value,
            'lead_time_days' => 3,
            'notes' => 'settings only',
        ])
        ->assertRedirect();

    expect($op->fresh()->pricing_version)->toBe(5)
        ->and($op->fresh()->display_name)->toBe('Org display')
        ->and(AuditEvent::query()->where('action', 'catalog.organization_product.settings_updated')->count())->toBe(1);
});
