<?php

namespace App\Support\Tenancy;

use App\Models\NumberSequence;
use App\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class NumberSequenceAllocator
{
    public const KEY_CUSTOMER = 'customer';

    public const KEY_DEAL = 'deal';

    public const KEY_QUOTE = 'quote';

    /**
     * @var list<string>
     */
    public const ALLOWED_KEYS = [self::KEY_CUSTOMER, self::KEY_DEAL, self::KEY_QUOTE];

    public function allocate(Organization $organization, string $sequenceKey, string $prefix = '', int $padLength = 5): string
    {
        if (! in_array($sequenceKey, self::ALLOWED_KEYS, true)) {
            throw new InvalidArgumentException("Unsupported sequence key [{$sequenceKey}].");
        }

        if ($padLength < 1 || $padLength > 20) {
            throw new InvalidArgumentException('Sequence pad length must be between 1 and 20.');
        }

        if (! Organization::query()->whereKey($organization->id)->exists()) {
            throw new InvalidArgumentException('Organization does not exist for sequence allocation.');
        }

        return DB::transaction(function () use ($organization, $sequenceKey, $prefix, $padLength): string {
            $this->ensureSequenceRow($organization->id, $sequenceKey, $prefix, $padLength);

            /** @var NumberSequence|null $sequence */
            $sequence = NumberSequence::query()
                ->where('organization_id', $organization->id)
                ->where('sequence_key', $sequenceKey)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                throw new RuntimeException("Number sequence [{$sequenceKey}] missing after ensure for organization #{$organization->id}.");
            }

            if ($sequence->prefix !== $prefix || $sequence->pad_length !== $padLength) {
                throw new RuntimeException(
                    "Sequence [{$sequenceKey}] for organization #{$organization->id} has conflicting prefix/padding.",
                );
            }

            $number = $sequence->next_number;
            $sequence->next_number = $number + 1;
            $sequence->save();

            return $sequence->prefix.str_pad((string) $number, $sequence->pad_length, '0', STR_PAD_LEFT);
        });
    }

    private function ensureSequenceRow(int $organizationId, string $sequenceKey, string $prefix, int $padLength): void
    {
        $existing = NumberSequence::query()
            ->where('organization_id', $organizationId)
            ->where('sequence_key', $sequenceKey)
            ->first();

        if ($existing !== null) {
            return;
        }

        try {
            NumberSequence::query()->create([
                'organization_id' => $organizationId,
                'sequence_key' => $sequenceKey,
                'prefix' => $prefix,
                'next_number' => 1,
                'pad_length' => $padLength,
            ]);
        } catch (QueryException) {
            // Concurrent first insert lost the race; the FOR UPDATE select will load the winner.
        }
    }
}
