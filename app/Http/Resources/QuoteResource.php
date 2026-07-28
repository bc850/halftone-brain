<?php

namespace App\Http\Resources;

use App\Models\Quote;
use App\Models\User;

final class QuoteResource
{
    /**
     * @param  iterable<int, Quote>  $quotes
     * @return list<array<string, mixed>>
     */
    public static function collection(iterable $quotes, User $user): array
    {
        $payload = [];

        foreach ($quotes as $quote) {
            $payload[] = self::summary($quote, $user);
        }

        return $payload;
    }

    /**
     * Deal-list sized payload: identity plus the current revision's status and pre-tax total.
     *
     * @return array<string, mixed>
     */
    public static function summary(Quote $quote, User $user): array
    {
        $quote->loadMissing('currentRevision');
        $current = $quote->currentRevision;

        return [
            'id' => $quote->id,
            'quote_number' => $quote->quote_number,
            'lifecycle_status' => $quote->lifecycle_status->value,
            'deal_id' => $quote->deal_id,
            'current_revision_id' => $quote->current_revision_id,
            'accepted_revision_id' => $quote->accepted_revision_id,
            'lock_version' => $quote->lock_version,
            'current_revision' => $current === null ? null : QuoteRevisionResource::summary($current),
            'created_at' => $quote->created_at?->toIso8601String(),
            'can_update' => $user->can('update', $quote),
            'can_void' => $user->can('void', $quote),
            'can_generate_document' => $user->can('generateDocument', $quote),
            'can_send' => $user->can('send', $quote),
            'can_record_customer_response' => $user->can('recordCustomerResponse', $quote),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function make(Quote $quote, User $user): array
    {
        $quote->loadMissing(['deal:id,name', 'organizationCompany', 'revisions']);

        return [
            ...self::summary($quote, $user),
            'deal' => $quote->deal === null
                ? null
                : ['id' => $quote->deal->id, 'name' => $quote->deal->name],
            'revisions' => QuoteRevisionResource::collection(
                $quote->revisions->sortByDesc('revision_number')->values(),
            ),
        ];
    }
}
