<?php

namespace App\Support\Quotes\Acceptance;

use App\Enums\QuoteCustomerResponseSource;
use App\Enums\QuoteCustomerResponseType;
use App\Enums\QuoteDocumentGenerationStatus;
use App\Enums\QuoteRevisionStatus;
use App\Enums\QuoteStatusTransitionSource;
use App\Models\IntegrationOutbox;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\ParentAccount;
use App\Models\Quote;
use App\Models\QuoteCustomerAccessToken;
use App\Models\QuoteCustomerResponseEvent;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionDocument;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Quotes\Documents\CustomerQuoteDocumentIntegrity;
use App\Support\Quotes\Documents\InvalidQuoteDocumentException;
use App\Support\Quotes\QuoteRevisionTransitionService;
use App\Support\Quotes\StaleQuoteStateException;
use App\Support\Quotes\Token\QuoteCustomerTokenLifecycleService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Customer and employee accept/reject flows following QuoteAcceptanceAtomicityContract.
 */
final class QuoteCustomerResponseService
{
    public function __construct(
        private QuoteRevisionTransitionService $transitions,
        private QuoteCustomerTokenLifecycleService $tokenLifecycle,
        private CustomerQuoteDocumentIntegrity $integrity,
        private QuoteAcceptanceAtomicityContract $contract,
        private Auditor $auditor,
    ) {}

    public function acceptAsCustomer(
        QuoteCustomerAccessToken $token,
        string $typedName,
        bool $termsAccepted,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): QuoteCustomerResponseEvent {
        return $this->respond(
            token: $token,
            response: QuoteCustomerResponseType::Accepted,
            source: QuoteCustomerResponseSource::Customer,
            typedName: $typedName,
            termsAccepted: $termsAccepted,
            rejectionReason: null,
            employeeMembership: null,
            employeeUser: null,
            employeeRecordedReason: null,
            expectedQuoteLockVersion: null,
            expectedRevisionLockVersion: null,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
        );
    }

    public function rejectAsCustomer(
        QuoteCustomerAccessToken $token,
        string $typedName = '',
        ?string $rejectionReason = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): QuoteCustomerResponseEvent {
        return $this->respond(
            token: $token,
            response: QuoteCustomerResponseType::Rejected,
            source: QuoteCustomerResponseSource::Customer,
            typedName: $typedName,
            termsAccepted: false,
            rejectionReason: $rejectionReason,
            employeeMembership: null,
            employeeUser: null,
            employeeRecordedReason: null,
            expectedQuoteLockVersion: null,
            expectedRevisionLockVersion: null,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
        );
    }

    public function acceptAsEmployee(
        Quote $quote,
        QuoteRevision $revision,
        QuoteCustomerAccessToken $token,
        int $expectedQuoteLockVersion,
        int $expectedRevisionLockVersion,
        string $typedName,
        bool $termsAccepted,
        string $employeeRecordedReason,
        Membership $employeeMembership,
        User $employeeUser,
    ): QuoteCustomerResponseEvent {
        return $this->respond(
            token: $token,
            response: QuoteCustomerResponseType::Accepted,
            source: QuoteCustomerResponseSource::Employee,
            typedName: $typedName,
            termsAccepted: $termsAccepted,
            rejectionReason: null,
            employeeMembership: $employeeMembership,
            employeeUser: $employeeUser,
            employeeRecordedReason: $employeeRecordedReason,
            expectedQuoteLockVersion: $expectedQuoteLockVersion,
            expectedRevisionLockVersion: $expectedRevisionLockVersion,
            ipAddress: null,
            userAgent: null,
            quote: $quote,
            revision: $revision,
        );
    }

    public function rejectAsEmployee(
        Quote $quote,
        QuoteRevision $revision,
        QuoteCustomerAccessToken $token,
        int $expectedQuoteLockVersion,
        int $expectedRevisionLockVersion,
        string $typedName,
        string $employeeRecordedReason,
        Membership $employeeMembership,
        User $employeeUser,
        ?string $rejectionReason = null,
    ): QuoteCustomerResponseEvent {
        return $this->respond(
            token: $token,
            response: QuoteCustomerResponseType::Rejected,
            source: QuoteCustomerResponseSource::Employee,
            typedName: $typedName,
            termsAccepted: false,
            rejectionReason: $rejectionReason,
            employeeMembership: $employeeMembership,
            employeeUser: $employeeUser,
            employeeRecordedReason: $employeeRecordedReason,
            expectedQuoteLockVersion: $expectedQuoteLockVersion,
            expectedRevisionLockVersion: $expectedRevisionLockVersion,
            ipAddress: null,
            userAgent: null,
            quote: $quote,
            revision: $revision,
        );
    }

    private function respond(
        QuoteCustomerAccessToken $token,
        QuoteCustomerResponseType $response,
        QuoteCustomerResponseSource $source,
        string $typedName,
        bool $termsAccepted,
        ?string $rejectionReason,
        ?Membership $employeeMembership,
        ?User $employeeUser,
        ?string $employeeRecordedReason,
        ?int $expectedQuoteLockVersion,
        ?int $expectedRevisionLockVersion,
        ?string $ipAddress,
        ?string $userAgent,
        ?Quote $quote = null,
        ?QuoteRevision $revision = null,
    ): QuoteCustomerResponseEvent {
        if ($response === QuoteCustomerResponseType::Accepted) {
            if (! $termsAccepted || trim($typedName) === '') {
                throw new InvalidQuoteDocumentException('Acceptance requires typed name and terms acceptance.');
            }
        }

        if ($source === QuoteCustomerResponseSource::Employee
            && ($employeeMembership === null || $employeeUser === null || trim((string) $employeeRecordedReason) === '')) {
            throw new InvalidQuoteDocumentException('Employee-recorded responses require an actor and evidence reason.');
        }

        if ($rejectionReason !== null && strlen($rejectionReason) > 2000) {
            throw new InvalidQuoteDocumentException('Rejection reason may not exceed 2000 characters.');
        }

        try {
            return DB::transaction(function () use (
                $token,
                $response,
                $source,
                $typedName,
                $termsAccepted,
                $rejectionReason,
                $employeeMembership,
                $employeeUser,
                $employeeRecordedReason,
                $expectedQuoteLockVersion,
                $expectedRevisionLockVersion,
                $ipAddress,
                $userAgent,
                $quote,
                $revision,
            ): QuoteCustomerResponseEvent {
                /** @var QuoteCustomerAccessToken $lockedToken */
                $lockedToken = QuoteCustomerAccessToken::query()->whereKey($token->id)->lockForUpdate()->firstOrFail();
                /** @var Quote $lockedQuote */
                $lockedQuote = Quote::query()
                    ->whereKey($quote !== null ? $quote->id : $lockedToken->quote_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                /** @var QuoteRevision $lockedRevision */
                $lockedRevision = QuoteRevision::query()
                    ->whereKey($revision !== null ? $revision->id : $lockedToken->quote_revision_id)
                    ->where('quote_id', $lockedQuote->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                /** @var QuoteRevisionDocument $document */
                $document = QuoteRevisionDocument::query()
                    ->whereKey($lockedToken->quote_revision_document_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $existing = QuoteCustomerResponseEvent::query()
                    ->where('quote_revision_id', $lockedRevision->id)
                    ->first();

                if ($existing !== null) {
                    if ($response === QuoteCustomerResponseType::Accepted
                        && $existing->response === QuoteCustomerResponseType::Accepted) {
                        return $existing;
                    }

                    throw new InvalidQuoteDocumentException('This quote revision already has a customer response.');
                }

                if ($expectedQuoteLockVersion !== null && $expectedRevisionLockVersion !== null) {
                    if ($lockedQuote->lock_version !== $expectedQuoteLockVersion
                        || $lockedRevision->lock_version !== $expectedRevisionLockVersion) {
                        throw new StaleQuoteStateException;
                    }
                }

                $this->assertRespondable($lockedQuote, $lockedRevision, $lockedToken, $document);

                $termsChecksum = $this->integrity->termsChecksum(
                    (string) ($document->customer_payload_snapshot_json['terms_text'] ?? $lockedRevision->terms_text)
                );
                $shownChecksum = is_string($document->customer_payload_snapshot_json['terms_checksum'] ?? null)
                    ? $document->customer_payload_snapshot_json['terms_checksum']
                    : $termsChecksum;
                $this->integrity->assertResponseMatchesDocument($termsChecksum, $shownChecksum);

                $correlationId = (string) Str::uuid();
                $transitionSource = $source === QuoteCustomerResponseSource::Customer
                    ? QuoteStatusTransitionSource::Customer
                    : QuoteStatusTransitionSource::EmployeeOnBehalf;

                $event = QuoteCustomerResponseEvent::query()->create([
                    'parent_account_id' => $lockedQuote->parent_account_id,
                    'organization_id' => $lockedQuote->organization_id,
                    'quote_id' => $lockedQuote->id,
                    'quote_revision_id' => $lockedRevision->id,
                    'quote_revision_document_id' => $document->id,
                    'quote_customer_access_token_id' => $lockedToken->id,
                    'response' => $response,
                    'source' => $source,
                    'typed_name_snapshot' => trim($typedName),
                    'customer_email_snapshot' => $lockedRevision->partySnapshot?->contact_email,
                    'terms_accepted' => $termsAccepted,
                    'terms_document_checksum' => $shownChecksum,
                    'rejection_reason' => $rejectionReason !== null ? trim($rejectionReason) : null,
                    'employee_membership_id' => $employeeMembership?->id,
                    'employee_user_id' => $employeeUser?->id,
                    'employee_recorded_reason' => $employeeRecordedReason,
                    'ip_address_encrypted' => $ipAddress,
                    'user_agent' => $userAgent !== null ? Str::limit($userAgent, 512, '') : null,
                    'occurred_at' => now(),
                    'correlation_id' => $correlationId,
                ]);

                $toStatus = $response === QuoteCustomerResponseType::Accepted
                    ? QuoteRevisionStatus::Accepted
                    : QuoteRevisionStatus::Rejected;

                $this->transitions->transition(
                    quote: $lockedQuote,
                    revision: $lockedRevision,
                    to: $toStatus,
                    source: $transitionSource,
                    expectedQuoteLockVersion: $lockedQuote->lock_version,
                    expectedRevisionLockVersion: $lockedRevision->lock_version,
                    actor: $employeeUser,
                    actorMembership: $employeeMembership,
                    metadata: [
                        'response_event_id' => $event->id,
                        'source' => $source->value,
                        'document_id' => $document->id,
                    ],
                );

                $lockedToken->forceFill([
                    'terminal_response_at' => now(),
                ])->save();

                $this->tokenLifecycle->revokeActiveTokensForRevision(
                    revision: $lockedRevision->fresh() ?? $lockedRevision,
                    reason: $response === QuoteCustomerResponseType::Accepted ? 'accepted' : 'rejected',
                    actor: $employeeUser,
                    exceptTokenId: null,
                );

                $this->auditor->append(
                    parentAccount: ParentAccount::query()->findOrFail($lockedQuote->parent_account_id),
                    action: $response === QuoteCustomerResponseType::Accepted
                        ? 'crm.quote.customer_accepted'
                        : 'crm.quote.customer_rejected',
                    subjectType: QuoteCustomerResponseEvent::class,
                    subjectId: $event->id,
                    organization: Organization::query()->findOrFail($lockedQuote->organization_id),
                    actor: $employeeUser,
                    after: [
                        'quote_revision_id' => $lockedRevision->id,
                        'document_id' => $document->id,
                        'source' => $source->value,
                        'response' => $response->value,
                    ],
                    correlationId: $correlationId,
                );

                if ($response === QuoteCustomerResponseType::Accepted) {
                    $this->insertAcceptedOutbox($lockedQuote, $lockedRevision, $document, $correlationId);
                }

                return $event->fresh() ?? $event;
            });
        } catch (QueryException $exception) {
            $existing = QuoteCustomerResponseEvent::query()
                ->where('quote_revision_id', $token->quote_revision_id)
                ->first();

            if ($existing !== null
                && $response === QuoteCustomerResponseType::Accepted
                && $existing->response === QuoteCustomerResponseType::Accepted) {
                return $existing;
            }

            throw $exception;
        }
    }

    private function assertRespondable(
        Quote $quote,
        QuoteRevision $revision,
        QuoteCustomerAccessToken $token,
        QuoteRevisionDocument $document,
    ): void {
        if (! $token->isUsable()) {
            throw new InvalidQuoteDocumentException('Customer quote access is unavailable.');
        }

        if ($document->generation_status !== QuoteDocumentGenerationStatus::Generated) {
            throw new InvalidQuoteDocumentException('Customer response requires the generated document.');
        }

        if (! in_array($revision->status, [QuoteRevisionStatus::Sent, QuoteRevisionStatus::Viewed], true)) {
            throw new InvalidQuoteDocumentException('Customer responses require a sent or viewed revision.');
        }

        if (! $revision->tax_calculation_status->isResolved()) {
            throw new InvalidQuoteDocumentException('Tax must be resolved before responding.');
        }

        if ($quote->accepted_revision_id !== null && $quote->accepted_revision_id !== $revision->id) {
            throw new InvalidQuoteDocumentException('Quote already has an accepted revision.');
        }

        if ($revision->expiration_date !== null && $revision->expiration_date->endOfDay()->lt(now())) {
            throw new InvalidQuoteDocumentException('This quote has expired.');
        }
    }

    private function insertAcceptedOutbox(
        Quote $quote,
        QuoteRevision $revision,
        QuoteRevisionDocument $document,
        string $correlationId,
    ): void {
        $payload = [
            'quote_id' => $quote->id,
            'quote_revision_id' => $revision->id,
            'organization_id' => $quote->organization_id,
            'document_id' => $document->id,
            'document_version' => $document->document_version,
        ];

        $this->contract->assertOutboxPayloadIsSafe($payload);

        $existing = IntegrationOutbox::query()
            ->where('idempotency_key', $this->contract->designIdempotencyKey($revision->id))
            ->first();

        if ($existing !== null) {
            return;
        }

        IntegrationOutbox::query()->create([
            'parent_account_id' => $quote->parent_account_id,
            'organization_id' => $quote->organization_id,
            'aggregate_type' => 'quote_revision',
            'aggregate_id' => $revision->id,
            'event_type' => QuoteAcceptanceAtomicityContract::ACCEPTED_EVENT_TYPE,
            'schema_version' => 1,
            'payload_json' => $payload,
            'idempotency_key' => $this->contract->designIdempotencyKey($revision->id),
            'available_at' => now(),
            'correlation_id' => $correlationId,
        ]);

        $this->auditor->append(
            parentAccount: ParentAccount::query()->findOrFail($quote->parent_account_id),
            action: 'crm.quote.accepted_outbox_inserted',
            subjectType: IntegrationOutbox::class,
            subjectId: null,
            organization: Organization::query()->findOrFail($quote->organization_id),
            after: [
                'quote_revision_id' => $revision->id,
                'event_type' => QuoteAcceptanceAtomicityContract::ACCEPTED_EVENT_TYPE,
            ],
            correlationId: $correlationId,
        );
    }
}
