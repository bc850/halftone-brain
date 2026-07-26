<?php

use App\Enums\DealStage;
use App\Enums\SalesTaxStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\OrganizationCompany;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Vendor;

dataset('legacy_mutation_routes', function () {
    return [
        'companies.store' => ['post', 'companies.store', fn () => [
            'name' => 'Legacy Block Co',
            'sales_tax_status' => SalesTaxStatus::Taxable->value,
        ], fn () => Company::query()->where('name', 'Legacy Block Co')->exists()],
        'contacts.store' => ['post', 'contacts.store', function () {
            $company = Company::factory()->create();

            return [
                'company_id' => $company->id,
                'first_name' => 'Legacy',
                'last_name' => 'Contact',
            ];
        }, fn () => Contact::query()->where('first_name', 'Legacy')->where('last_name', 'Contact')->exists()],
        'deals.store' => ['post', 'deals.store', function () {
            $company = Company::factory()->create();

            return [
                'name' => 'Legacy Deal',
                'company_id' => $company->id,
                'stage' => DealStage::Lead->value,
            ];
        }, fn () => Deal::query()->where('name', 'Legacy Deal')->exists()],
        'products.store' => ['post', 'products.store', fn () => [
            'name' => 'Legacy Product',
            'sku' => 'LEGACY-SKU-1',
            'unit_of_measure' => 'each',
            'true_cost' => '1.00',
            'markup_percent' => '10',
            'list_price' => '1.10',
            'is_active' => true,
        ], fn () => Product::query()->where('sku', 'LEGACY-SKU-1')->exists()],
        'vendors.store' => ['post', 'vendors.store', fn () => [
            'name' => 'Legacy Vendor',
            'is_active' => true,
        ], fn () => Vendor::query()->where('name', 'Legacy Vendor')->exists()],
        'categories.store' => ['post', 'categories.store', fn () => [
            'name' => 'Legacy Category',
            'sort_order' => 1,
        ], fn () => ProductCategory::query()->where('name', 'Legacy Category')->exists()],
    ];
});

test('legacy store mutations return 409 and write nothing', function (string $method, string $routeName, callable $payload, callable $exists) {
    $ctx = createTenantUser('owner', 'parent_owner');

    $request = match ($method) {
        'post' => $this->actingAs($ctx['user'])->postJson(route($routeName), $payload()),
        'put' => $this->actingAs($ctx['user'])->putJson(route($routeName), $payload()),
        'patch' => $this->actingAs($ctx['user'])->patchJson(route($routeName), $payload()),
        'delete' => $this->actingAs($ctx['user'])->deleteJson(route($routeName), $payload()),
        default => throw new InvalidArgumentException($method),
    };

    $request->assertStatus(409)
        ->assertJson(['message' => 'An organization context is required for this action.']);

    expect($exists())->toBeFalse();
})->with('legacy_mutation_routes');

test('legacy update destroy and stage mutations return 409 without changing rows', function () {
    $ctx = createTenantUser('owner', 'parent_owner');
    $company = Company::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'owner_id' => $ctx['user']->id,
        'name' => 'Keep Name',
    ]);
    OrganizationCompany::factory()->create([
        'organization_id' => $ctx['organization']->id,
        'company_id' => $company->id,
        'parent_account_id' => $ctx['parent']->id,
    ]);
    $contact = Contact::factory()->create([
        'company_id' => $company->id,
        'parent_account_id' => $ctx['parent']->id,
        'first_name' => 'Keep',
    ]);
    $deal = Deal::factory()->create([
        'company_id' => $company->id,
        'organization_id' => $ctx['organization']->id,
        'parent_account_id' => $ctx['parent']->id,
        'owner_id' => $ctx['user']->id,
        'stage' => DealStage::Lead,
        'name' => 'Keep Deal',
    ]);
    $vendor = Vendor::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'name' => 'Keep Vendor',
    ]);
    $category = ProductCategory::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'name' => 'Keep Category',
    ]);
    $product = Product::factory()->create([
        'parent_account_id' => $ctx['parent']->id,
        'product_category_id' => $category->id,
        'name' => 'Keep Product',
    ]);

    $this->actingAs($ctx['user'])->putJson(route('companies.update', $company), [
        'name' => 'Changed',
        'sales_tax_status' => SalesTaxStatus::Taxable->value,
    ])->assertStatus(409);
    $this->actingAs($ctx['user'])->deleteJson(route('companies.destroy', $company))->assertStatus(409);

    $this->actingAs($ctx['user'])->putJson(route('contacts.update', $contact), [
        'company_id' => $company->id,
        'first_name' => 'Changed',
        'last_name' => $contact->last_name,
    ])->assertStatus(409);
    $this->actingAs($ctx['user'])->deleteJson(route('contacts.destroy', $contact))->assertStatus(409);

    $this->actingAs($ctx['user'])->putJson(route('deals.update', $deal), [
        'name' => 'Changed Deal',
        'company_id' => $company->id,
        'stage' => DealStage::Qualified->value,
    ])->assertStatus(409);
    $this->actingAs($ctx['user'])->patchJson(route('deals.stage', $deal), [
        'stage' => DealStage::Qualified->value,
    ])->assertStatus(409);
    $this->actingAs($ctx['user'])->deleteJson(route('deals.destroy', $deal))->assertStatus(409);

    $this->actingAs($ctx['user'])->putJson(route('products.update', $product), [
        'name' => 'Changed Product',
        'sku' => $product->sku,
        'unit_of_measure' => $product->unit_of_measure->value,
        'true_cost' => '1.00',
        'markup_percent' => '10',
        'list_price' => '1.10',
        'is_active' => true,
    ])->assertStatus(409);
    $this->actingAs($ctx['user'])->deleteJson(route('products.destroy', $product))->assertStatus(409);

    $this->actingAs($ctx['user'])->putJson(route('vendors.update', $vendor), [
        'name' => 'Changed Vendor',
        'is_active' => true,
    ])->assertStatus(409);
    $this->actingAs($ctx['user'])->deleteJson(route('vendors.destroy', $vendor))->assertStatus(409);

    $this->actingAs($ctx['user'])->putJson(route('categories.update', $category), [
        'name' => 'Changed Category',
        'sort_order' => 1,
    ])->assertStatus(409);
    $this->actingAs($ctx['user'])->deleteJson(route('categories.destroy', $category))->assertStatus(409);

    expect($company->fresh()->name)->toBe('Keep Name')
        ->and($contact->fresh()->first_name)->toBe('Keep')
        ->and($deal->fresh()->stage)->toBe(DealStage::Lead)
        ->and($deal->fresh()->name)->toBe('Keep Deal')
        ->and($product->fresh()->name)->toBe('Keep Product')
        ->and($vendor->fresh()->name)->toBe('Keep Vendor')
        ->and($category->fresh()->name)->toBe('Keep Category')
        ->and(Company::query()->find($company->id))->not->toBeNull()
        ->and(Contact::query()->find($contact->id))->not->toBeNull()
        ->and(Deal::query()->find($deal->id))->not->toBeNull()
        ->and(Product::query()->find($product->id))->not->toBeNull()
        ->and(Vendor::query()->find($vendor->id))->not->toBeNull()
        ->and(ProductCategory::query()->find($category->id))->not->toBeNull();
});
