<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\QuoteDeliveryStatus;
use App\Enums\QuoteDocumentGenerationStatus;
use App\Enums\QuoteRevisionStatus;
use App\Http\Resources\QuoteCustomerAccessTokenResource;
use App\Http\Resources\QuoteDeliveryResource;
use App\Http\Resources\QuoteDocumentResource;
use App\Models\Quote;
use App\Models\QuoteCustomerAccessToken;
use App\Models\QuoteDelivery;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionDocument;
use App\Models\QuoteRevisionPartySnapshot;
use App\Models\User;

/**
 * Delivery / document / token panel shared by the revision show and delivery page.
 */
trait BuildsQuoteDeliveryPanel
{
    /**
     * @return array<string, mixed>
     */
    protected function deliveryPanel(Quote $quote, QuoteRevision $revision, User $user): array
    {
        $revision->loadMissing(['currentDocument', 'partySnapshot']);

        $documents = QuoteRevisionDocument::query()
            ->where('quote_revision_id', $revision->id)
            ->orderByDesc('document_version')
            ->orderByDesc('id')
            ->get();

        $deliveries = QuoteDelivery::query()
            ->where('quote_revision_id', $revision->id)
            ->orderByDesc('id')
            ->get();

        $tokens = QuoteCustomerAccessToken::query()
            ->where('quote_revision_id', $revision->id)
            ->orderByDesc('id')
            ->get();

        $currentDocument = $revision->currentDocument;
        $activeToken = $tokens->first(fn (QuoteCustomerAccessToken $token): bool => $token->isUsable());
        $pendingDelivery = $deliveries->first(
            fn (QuoteDelivery $delivery): bool => $delivery->status === QuoteDeliveryStatus::Pending
                && ($currentDocument === null || $delivery->quote_revision_document_id === $currentDocument->id)
        );

        $canGenerate = $user->can('generateDocument', $quote)
            && $quote->current_revision_id === $revision->id
            && $revision->status === QuoteRevisionStatus::Approved
            && $revision->tax_calculation_status->isResolved();

        $canSend = $user->can('send', $quote)
            && $quote->current_revision_id === $revision->id
            && in_array($revision->status, [
                QuoteRevisionStatus::Approved,
                QuoteRevisionStatus::Sent,
                QuoteRevisionStatus::Viewed,
            ], true)
            && $revision->tax_calculation_status->isResolved()
            && $currentDocument !== null
            && $currentDocument->generation_status === QuoteDocumentGenerationStatus::Generated;

        $canRecordResponse = $user->can('recordCustomerResponse', $quote)
            && $quote->current_revision_id === $revision->id
            && in_array($revision->status, [
                QuoteRevisionStatus::Sent,
                QuoteRevisionStatus::Viewed,
            ], true)
            && $activeToken !== null;

        return [
            'current_document' => QuoteDocumentResource::make($currentDocument),
            'documents' => QuoteDocumentResource::collection($documents),
            'deliveries' => QuoteDeliveryResource::collection($deliveries),
            'tokens' => QuoteCustomerAccessTokenResource::collection($tokens),
            'active_token' => QuoteCustomerAccessTokenResource::make($activeToken),
            'pending_delivery' => QuoteDeliveryResource::make($pendingDelivery),
            'recipient_defaults' => (static function () use ($revision): array {
                $party = $revision->partySnapshot;

                return [
                    'name' => $party instanceof QuoteRevisionPartySnapshot
                        ? ($party->contact_name ?? $party->customer_company_name)
                        : null,
                    'email' => $party instanceof QuoteRevisionPartySnapshot
                        ? $party->contact_email
                        : null,
                ];
            })(),
            'can_generate_document' => $canGenerate,
            'can_send' => $canSend,
            'can_record_customer_response' => $canRecordResponse,
            'can_preview_document' => $currentDocument !== null
                && $currentDocument->generation_status === QuoteDocumentGenerationStatus::Generated
                && $user->can('view', $quote),
        ];
    }
}
