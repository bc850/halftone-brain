<?php

use App\Models\Vendor;

test('admins can create vendors', function () {
    $ctx = createTenantUser('owner', 'parent_owner');

    $this->actingAs($ctx['user'])
        ->post(route('org.vendors.store', $ctx['organization']), [
            'name' => 'Avery Dennison',
            'account_number' => 'AD-100',
            'email' => 'orders@example.com',
            'is_active' => true,
        ])
        ->assertRedirect();

    expect(Vendor::query()->where('name', 'Avery Dennison')->exists())->toBeTrue();
});

test('salesmen cannot create vendors but can view them', function () {
    $ctx = createTenantUser('salesperson');
    $vendor = Vendor::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
    ]);

    $this->actingAs($ctx['user'])
        ->post(route('org.vendors.store', $ctx['organization']), [
            'name' => 'Blocked Vendor',
        ])
        ->assertForbidden();

    $this->actingAs($ctx['user'])
        ->get(route('org.vendors.show', [$ctx['organization'], $vendor]))
        ->assertOk();
});

test('salesmen can list vendors without account details', function () {
    $ctx = createTenantUser('salesperson');
    Vendor::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'account_number' => 'SECRET-99',
        'email' => 'orders@vendor.test',
    ]);

    $this->actingAs($ctx['user'])
        ->get(route('org.vendors.index', $ctx['organization']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('vendors/Index')
            ->where('canViewDetails', false)
            ->missing('vendors.data.0.account_number')
            ->missing('vendors.data.0.email'));
});
