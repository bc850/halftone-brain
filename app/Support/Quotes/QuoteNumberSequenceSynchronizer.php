<?php

namespace App\Support\Quotes;

use App\Models\NumberSequence;
use App\Models\Organization;
use App\Support\Tenancy\NumberSequenceAllocator;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent ensure of quote number sequences for known organization slugs.
 * Safe for a later operational checkpoint; does not run against primary in 2A.
 */
final class QuoteNumberSequenceSynchronizer
{
    /**
     * @return list<array{organization_id: int, slug: string, created: bool, prefix: string}>
     */
    public function syncMissing(): array
    {
        $results = [];

        foreach (QuoteNumberSequenceDefinitions::byOrganizationSlug() as $slug => $definition) {
            $organization = Organization::query()->where('slug', $slug)->first();
            if ($organization === null) {
                continue;
            }

            $created = false;

            DB::transaction(function () use ($organization, $definition, &$created): void {
                $existing = NumberSequence::query()
                    ->where('organization_id', $organization->id)
                    ->where('sequence_key', NumberSequenceAllocator::KEY_QUOTE)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return;
                }

                NumberSequence::query()->create([
                    'organization_id' => $organization->id,
                    'sequence_key' => NumberSequenceAllocator::KEY_QUOTE,
                    'prefix' => $definition['prefix'],
                    'next_number' => 1,
                    'pad_length' => $definition['pad_length'],
                ]);
                $created = true;
            });

            $results[] = [
                'organization_id' => $organization->id,
                'slug' => $slug,
                'created' => $created,
                'prefix' => $definition['prefix'],
            ];
        }

        return $results;
    }
}
