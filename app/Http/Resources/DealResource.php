<?php

namespace App\Http\Resources;

use App\Models\Deal;
use App\Models\User;
use App\Support\Money;

final class DealResource
{
    /**
     * @return array<string, mixed>
     */
    public static function make(Deal $deal, User $user): array
    {
        return [
            'id' => $deal->id,
            'name' => $deal->name,
            'stage' => $deal->stage->value,
            'amount' => $deal->amount_cents !== null ? Money::centsToDollars($deal->amount_cents) : null,
            'expected_close_date' => $deal->expected_close_date?->toDateString(),
            'notes' => $deal->notes,
            'company' => $deal->relationLoaded('company') && $deal->company
                ? ['id' => $deal->company->id, 'name' => $deal->company->name]
                : null,
            'owner' => $deal->relationLoaded('owner') && $deal->owner
                ? ['id' => $deal->owner->id, 'name' => $deal->owner->name]
                : null,
            'primary_contact' => $deal->relationLoaded('primaryContact') && $deal->primaryContact
                ? [
                    'id' => $deal->primaryContact->id,
                    'first_name' => $deal->primaryContact->first_name,
                    'last_name' => $deal->primaryContact->last_name,
                    'email' => $deal->primaryContact->email,
                    'phone' => $deal->primaryContact->phone,
                ]
                : null,
            'contacts' => $deal->relationLoaded('contacts')
                ? $deal->contacts->map(fn ($contact): array => [
                    'id' => $contact->id,
                    'first_name' => $contact->first_name,
                    'last_name' => $contact->last_name,
                    'email' => $contact->email,
                    'phone' => $contact->phone,
                ])->values()->all()
                : [],
            'can_update' => $user->can('update', $deal),
            'can_delete' => $user->can('delete', $deal),
        ];
    }
}
