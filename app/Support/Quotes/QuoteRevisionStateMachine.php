<?php

namespace App\Support\Quotes;

use App\Enums\QuoteRevisionStatus;
use InvalidArgumentException;

/**
 * Pure legal transition table for QuoteRevisionStatus.
 * Controllers must not assign status outside QuoteRevisionTransitionService.
 */
final class QuoteRevisionStateMachine
{
    /**
     * @return array<string, list<string>>
     */
    public static function transitionMap(): array
    {
        return [
            QuoteRevisionStatus::Draft->value => [
                QuoteRevisionStatus::PendingApproval->value,
                QuoteRevisionStatus::Approved->value,
                QuoteRevisionStatus::Void->value,
            ],
            QuoteRevisionStatus::PendingApproval->value => [
                QuoteRevisionStatus::Approved->value,
                QuoteRevisionStatus::Draft->value, // approval rejection → editable draft
                QuoteRevisionStatus::Void->value,
            ],
            QuoteRevisionStatus::Approved->value => [
                QuoteRevisionStatus::Sent->value,
                QuoteRevisionStatus::Draft->value, // edit invalidates approval
                QuoteRevisionStatus::Void->value,
            ],
            QuoteRevisionStatus::Sent->value => [
                QuoteRevisionStatus::Viewed->value,
                QuoteRevisionStatus::Accepted->value,
                QuoteRevisionStatus::Rejected->value,
                QuoteRevisionStatus::Expired->value,
                QuoteRevisionStatus::Superseded->value,
                QuoteRevisionStatus::Void->value,
            ],
            QuoteRevisionStatus::Viewed->value => [
                QuoteRevisionStatus::Accepted->value,
                QuoteRevisionStatus::Rejected->value,
                QuoteRevisionStatus::Expired->value,
                QuoteRevisionStatus::Superseded->value,
                QuoteRevisionStatus::Void->value,
            ],
            QuoteRevisionStatus::Accepted->value => [],
            QuoteRevisionStatus::Rejected->value => [],
            QuoteRevisionStatus::Expired->value => [],
            QuoteRevisionStatus::Superseded->value => [],
            QuoteRevisionStatus::Void->value => [],
        ];
    }

    public static function canTransition(QuoteRevisionStatus $from, QuoteRevisionStatus $to): bool
    {
        $allowed = self::transitionMap()[$from->value] ?? [];

        return in_array($to->value, $allowed, true);
    }

    public static function assertCanTransition(QuoteRevisionStatus $from, QuoteRevisionStatus $to): void
    {
        if ($from === $to) {
            return;
        }

        if (! self::canTransition($from, $to)) {
            throw new InvalidArgumentException(
                "Illegal quote revision transition [{$from->value}] → [{$to->value}]."
            );
        }
    }
}
