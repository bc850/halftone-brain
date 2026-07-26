<?php

namespace Database\Factories;

use App\Enums\QuoteRevisionStatus;
use App\Enums\QuoteStatusTransitionSource;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\QuoteStatusEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<QuoteStatusEvent>
 */
class QuoteStatusEventFactory extends Factory
{
    protected $model = QuoteStatusEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quote_id' => Quote::factory(),
            'quote_revision_id' => function (array $attributes): int {
                $revision = QuoteRevision::query()
                    ->where('quote_id', $attributes['quote_id'])
                    ->first();

                if ($revision !== null) {
                    return $revision->id;
                }

                return (int) QuoteRevision::factory()->create([
                    'quote_id' => $attributes['quote_id'],
                ])->id;
            },
            'parent_account_id' => fn (array $attributes): int => (int) Quote::query()
                ->whereKey($attributes['quote_id'])
                ->value('parent_account_id'),
            'organization_id' => fn (array $attributes): int => (int) Quote::query()
                ->whereKey($attributes['quote_id'])
                ->value('organization_id'),
            'from_status' => null,
            'to_status' => QuoteRevisionStatus::Draft,
            'actor_user_id' => null,
            'actor_membership_id' => null,
            'transition_source' => QuoteStatusTransitionSource::System,
            'metadata_json' => null,
            'occurred_at' => now(),
            'correlation_id' => (string) Str::uuid(),
        ];
    }
}
