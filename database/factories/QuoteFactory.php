<?php

namespace Database\Factories;

use App\Enums\QuoteLifecycleStatus;
use App\Models\Deal;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Quote;
use App\Support\Quotes\QuoteFactoryService;
use App\Support\Quotes\QuoteNumberSequenceDefinitions;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quote>
 */
class QuoteFactory extends Factory
{
    protected $model = Quote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'deal_id' => Deal::factory(),
            'lifecycle_status' => QuoteLifecycleStatus::Open,
            'lock_version' => 1,
        ];
    }

    /**
     * Preferred factory path: creates quote + revision 1 via domain service.
     */
    public static function createForDeal(
        ?Deal $deal = null,
        ?Membership $membership = null,
        string $prefix = 'TST-Q-',
        int $padLength = 5,
    ): Quote {
        $deal ??= Deal::factory()->create();
        $organization = $deal->organization()->first()
            ?? Organization::query()->findOrFail($deal->organization_id);

        $membership ??= Membership::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $deal->owner_id,
        ]);

        $definition = QuoteNumberSequenceDefinitions::forOrganizationSlug($organization->slug);
        if ($definition !== null) {
            $prefix = $definition['prefix'];
            $padLength = $definition['pad_length'];
        }

        return app(QuoteFactoryService::class)->create(
            deal: $deal,
            createdByMembership: $membership,
            organization: $organization,
            quotePrefix: $prefix,
            padLength: $padLength,
            salesOwnerMembership: $membership,
            actor: $membership->user,
        );
    }
}
