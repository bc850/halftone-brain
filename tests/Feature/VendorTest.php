<?php

use App\Models\User;
use App\Models\Vendor;

test('admins can create vendors', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('vendors.store'), [
            'name' => 'Avery Dennison',
            'account_number' => 'AD-100',
            'email' => 'orders@example.com',
            'is_active' => true,
        ])
        ->assertRedirect();

    expect(Vendor::query()->where('name', 'Avery Dennison')->exists())->toBeTrue();
});

test('salesmen cannot create vendors but can view them', function () {
    $salesman = User::factory()->salesman()->create();
    $vendor = Vendor::factory()->create();

    $this->actingAs($salesman)
        ->post(route('vendors.store'), [
            'name' => 'Blocked Vendor',
        ])
        ->assertForbidden();

    $this->actingAs($salesman)
        ->get(route('vendors.show', $vendor))
        ->assertOk();
});

test('salesmen can list vendors without account details', function () {
    $salesman = User::factory()->salesman()->create();
    Vendor::factory()->create([
        'account_number' => 'SECRET-99',
        'email' => 'orders@vendor.test',
    ]);

    $this->actingAs($salesman)
        ->get(route('vendors.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('vendors/Index')
            ->where('canViewDetails', false)
            ->missing('vendors.data.0.account_number')
            ->missing('vendors.data.0.email'));
});
