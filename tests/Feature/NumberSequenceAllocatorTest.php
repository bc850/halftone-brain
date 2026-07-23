<?php

use App\Models\NumberSequence;
use App\Models\Organization;
use App\Support\Tenancy\NumberSequenceAllocator;

test('allocator returns expected formatted values', function () {
    $organization = Organization::factory()->create();

    NumberSequence::factory()->create([
        'organization_id' => $organization->id,
        'sequence_key' => 'customer',
        'prefix' => 'PEL-C-',
        'next_number' => 1,
        'pad_length' => 5,
    ]);

    $allocator = app(NumberSequenceAllocator::class);

    expect($allocator->allocate($organization, 'customer', 'PEL-C-', 5))->toBe('PEL-C-00001')
        ->and($allocator->allocate($organization, 'customer', 'PEL-C-', 5))->toBe('PEL-C-00002')
        ->and(NumberSequence::query()->where('organization_id', $organization->id)->where('sequence_key', 'customer')->value('next_number'))->toBe(3);
});

test('organization sequences allocate independently', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $allocator = app(NumberSequenceAllocator::class);

    expect($allocator->allocate($orgA, 'deal', 'PEL-D-', 5))->toBe('PEL-D-00001')
        ->and($allocator->allocate($orgB, 'deal', 'BRIM-D-', 5))->toBe('BRIM-D-00001')
        ->and(NumberSequence::query()->where('organization_id', $orgA->id)->value('next_number'))->toBe(2)
        ->and(NumberSequence::query()->where('organization_id', $orgB->id)->value('next_number'))->toBe(2);
});

test('concurrent allocation does not return duplicates on sqlite serialized transactions', function () {
    $organization = Organization::factory()->create();
    $allocator = app(NumberSequenceAllocator::class);

    $results = [];

    foreach (range(1, 10) as $ignored) {
        $results[] = $allocator->allocate($organization, 'customer', 'TEST-C-', 5);
    }

    expect($results)->toHaveCount(10)
        ->and(count(array_unique($results)))->toBe(10)
        ->and($results[0])->toBe('TEST-C-00001')
        ->and($results[9])->toBe('TEST-C-00010');
});

test('concurrent first allocation creates only one sequence row', function () {
    $organization = Organization::factory()->create();
    $allocator = app(NumberSequenceAllocator::class);

    $first = $allocator->allocate($organization, 'customer', 'TEST-C-', 5);
    $second = $allocator->allocate($organization, 'customer', 'TEST-C-', 5);

    expect($first)->toBe('TEST-C-00001')
        ->and($second)->toBe('TEST-C-00002')
        ->and(NumberSequence::query()->where('organization_id', $organization->id)->where('sequence_key', 'customer')->count())->toBe(1);
});

test('allocator rejects unsupported sequence keys', function () {
    $organization = Organization::factory()->create();

    app(NumberSequenceAllocator::class)->allocate($organization, 'invoice', 'INV-', 5);
})->throws(InvalidArgumentException::class);
