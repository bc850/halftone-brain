<?php

use App\Models\ProductCategory;

test('admins can create categories', function () {
    $ctx = createTenantUser('owner', 'parent_owner');

    $this->actingAs($ctx['user'])
        ->post(route('org.categories.store', $ctx['organization']), [
            'name' => 'ACM Panels',
            'description' => 'Aluminum composite material',
        ])
        ->assertRedirect();

    $category = ProductCategory::query()->where('name', 'ACM Panels')->first();

    expect($category)->not->toBeNull()
        ->and($category->slug)->toBe('acm-panels');
});

test('salesmen cannot manage categories', function () {
    $ctx = createTenantUser('salesperson');

    $this->actingAs($ctx['user'])
        ->post(route('org.categories.store', $ctx['organization']), [
            'name' => 'Blocked',
        ])
        ->assertForbidden();
});
