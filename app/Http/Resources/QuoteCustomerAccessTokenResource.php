<?php

namespace App\Http\Resources;

use App\Models\QuoteCustomerAccessToken;

/**
 * Token lifecycle metadata. Never includes the raw token or token hash.
 */
final class QuoteCustomerAccessTokenResource
{
    /**
     * @param  iterable<int, QuoteCustomerAccessToken>  $tokens
     * @return list<array<string, mixed>>
     */
    public static function collection(iterable $tokens): array
    {
        $payload = [];

        foreach ($tokens as $token) {
            $payload[] = self::make($token);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public static function make(?QuoteCustomerAccessToken $token): ?array
    {
        if ($token === null) {
            return null;
        }

        return [
            'id' => $token->id,
            'quote_revision_document_id' => $token->quote_revision_document_id,
            'purpose' => $token->purpose->value,
            'expires_at' => $token->expires_at->toIso8601String(),
            'revoked_at' => $token->revoked_at?->toIso8601String(),
            'revoke_reason' => $token->revoke_reason,
            'is_usable' => $token->isUsable(),
            'is_revoked' => $token->isRevoked(),
            'is_expired' => $token->isExpired(),
            'view_count' => $token->view_count,
            'last_viewed_at' => $token->last_viewed_at?->toIso8601String(),
            'terminal_response_at' => $token->terminal_response_at?->toIso8601String(),
            'created_at' => $token->created_at?->toIso8601String(),
        ];
    }
}
