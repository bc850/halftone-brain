<?php

use App\Models\ProductCategory;
use App\Models\User;

test('admins can create categories', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('categories.store'), [
            'name' => 'ACM Panels',
            'description' => 'Aluminum composite material',
        ])
        ->assertRedirect();

    $category = ProductCategory::query()->where('name', 'ACM Panels')->first();

    expect($category)->not->toBeNull()
        ->and($category->slug)->toBe('acm-panels');
});

test('salesmen cannot manage categories', function () {
    $salesman = User::factory()->salesman()->create();

    $this->actingAs($salesman)
        ->post(route('categories.store'), [
            'name' => 'Blocked',
        ])
        ->assertForbidden();
});
