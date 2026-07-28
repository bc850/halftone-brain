<?php

namespace App\Support\Quotes;

use App\Models\Contact;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\ParentAccount;
use App\Models\Quote;
use App\Models\QuoteRevision;
use App\Models\QuoteRevisionPartySnapshot;
use App\Models\User;
use App\Support\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Explicit, opt-in reconciliation between a revision's frozen party snapshot and live CRM data.
 *
 * CRM edits never leak into an existing revision implicitly. A user previews the drift, then
 * chooses to refresh; only draft revisions can be refreshed.
 */
final class QuotePartySnapshotService
{
    /**
     * Customer-facing identity fields compared during a refresh preview.
     *
     * @var list<string>
     */
    public const REFRESHABLE_FIELDS = [
        'selling_organization_name',
        'selling_organization_slug',
        'customer_company_name',
        'customer_number',
        'contact_name',
        'contact_email',
        'contact_phone',
        'billing_address_json',
        'service_address_json',
    ];

    /**
     * Snapshot fields a user may hand-edit on a draft. Company and organization identities
     * are structural and can only change by recreating the quote.
     *
     * @var list<string>
     */
    private const EDITABLE_FIELDS = [
        'contact_name',
        'contact_email',
        'contact_phone',
        'billing_address_json',
        'service_address_json',
        'customer_po_reference',
    ];

    /**
     * Revision content a user may hand-edit on a draft alongside the party snapshot.
     *
     * @var list<string>
     */
    private const EDITABLE_REVISION_FIELDS = [
        'introduction',
        'terms_text',
        'customer_notes',
        'internal_notes',
        'expiration_date',
    ];

    /**
     * Revision content a customer reads on the document. Editing any of these means the
     * quote a reviewer approved is no longer the quote that would go out, so approval and
     * the tax position that was resolved against it are invalidated. `internal_notes` is
     * absent on purpose: it never reaches the customer and never changes the numbers.
     *
     * @var list<string>
     */
    private const CUSTOMER_VISIBLE_REVISION_FIELDS = [
        'introduction',
        'terms_text',
        'customer_notes',
        'expiration_date',
    ];

    public function __construct(
        private QuoteDraftLock $lock,
        private QuotePartySnapshotBuilder $builder,
        private QuoteApprovalInvalidationService $invalidation,
        private Auditor $auditor,
    ) {}

    /**
     * Read-only drift report between the frozen snapshot and live CRM rows.
     *
     * @return array{
     *     current: array<string, mixed>,
     *     proposed: array<string, mixed>,
     *     changes: list<array{field: string, from: mixed, to: mixed}>
     * }
     */
    public function previewRefresh(QuoteRevision $revision): array
    {
        $quote = Quote::query()->findOrFail($revision->quote_id);
        $snapshot = $this->requireSnapshot($revision);
        $proposedAttributes = $this->liveAttributes($quote, $revision, $snapshot);

        $current = [];
        $proposed = [];
        $changes = [];

        foreach (self::REFRESHABLE_FIELDS as $field) {
            $from = $snapshot->getAttribute($field);
            $to = $proposedAttributes[$field] ?? null;

            $current[$field] = $from;
            $proposed[$field] = $to;

            if ($from !== $to) {
                $changes[] = ['field' => $field, 'from' => $from, 'to' => $to];
            }
        }

        return ['current' => $current, 'proposed' => $proposed, 'changes' => $changes];
    }

    /**
     * Pull live customer identity onto a draft revision's snapshot.
     *
     * The preparer stays as recorded, the salesperson follows the quote's current sales owner,
     * and `customer_po_reference` is intentionally left alone — it is buyer-supplied, not CRM data.
     */
    public function refreshFromCustomer(
        Quote $quote,
        QuoteRevision $revision,
        int $expectedRevisionLockVersion,
        ?User $actor = null,
    ): QuoteRevisionPartySnapshot {
        return DB::transaction(function () use ($quote, $revision, $expectedRevisionLockVersion, $actor): QuoteRevisionPartySnapshot {
            ['quote' => $lockedQuote, 'revision' => $lockedRevision] = $this->lock->lockDraft(
                $quote,
                $revision,
                $expectedRevisionLockVersion,
            );

            $snapshot = $this->requireSnapshot($lockedRevision);
            $before = $this->payload($snapshot);

            $live = $this->liveAttributes($lockedQuote, $lockedRevision, $snapshot);

            $snapshot->fill([
                'selling_organization_name' => $live['selling_organization_name'],
                'selling_organization_slug' => $live['selling_organization_slug'],
                'selling_organization_display_json' => $live['selling_organization_display_json'],
                'customer_company_name' => $live['customer_company_name'],
                'customer_number' => $live['customer_number'],
                'primary_contact_id' => $live['primary_contact_id'],
                'contact_name' => $live['contact_name'],
                'contact_email' => $live['contact_email'],
                'contact_phone' => $live['contact_phone'],
                'billing_address_json' => $live['billing_address_json'],
                'service_address_json' => $live['service_address_json'],
                'salesperson_membership_id' => $live['salesperson_membership_id'],
                'salesperson_user_id' => $live['salesperson_user_id'],
                'salesperson_name' => $live['salesperson_name'],
                'salesperson_email' => $live['salesperson_email'],
            ]);
            $snapshot->save();

            $correlationId = (string) Str::uuid();

            // Addresses and customer identity decide the jurisdiction and the approval
            // context, so a refresh invalidates both. The bump below is the only one.
            $this->invalidation->invalidateForFinancialMutation(
                $lockedQuote,
                $lockedRevision,
                $actor,
                $correlationId,
            );

            $this->lock->bumpRevisionLock($lockedRevision);

            $this->audit(
                $lockedQuote,
                $snapshot,
                'crm.quote.party_snapshot_refreshed',
                $before,
                $this->payload($snapshot),
                $actor,
                $correlationId,
            );

            return $snapshot->fresh() ?? $snapshot;
        });
    }

    /**
     * Hand-edit contact details, addresses, and the buyer PO reference on a draft.
     *
     * @param  array{
     *     primary_contact_id?: int|null,
     *     contact_name?: string|null,
     *     contact_email?: string|null,
     *     contact_phone?: string|null,
     *     billing_address_json?: array<string, mixed>|null,
     *     service_address_json?: array<string, mixed>|null,
     *     customer_po_reference?: string|null
     * }  $data
     */
    public function updateDraft(
        Quote $quote,
        QuoteRevision $revision,
        int $expectedLockVersion,
        array $data,
        ?User $actor = null,
    ): QuoteRevisionPartySnapshot {
        return DB::transaction(function () use ($quote, $revision, $expectedLockVersion, $data, $actor): QuoteRevisionPartySnapshot {
            ['quote' => $lockedQuote, 'revision' => $lockedRevision] = $this->lock->lockDraft(
                $quote,
                $revision,
                $expectedLockVersion,
            );

            $snapshot = $this->requireSnapshot($lockedRevision);
            $before = $this->payload($snapshot);

            $this->assertOnlyKnownKeys($data, [...self::EDITABLE_FIELDS, 'primary_contact_id']);

            if (array_key_exists('primary_contact_id', $data)) {
                $this->applyContact($snapshot, $data['primary_contact_id'], $data);
            }

            foreach (self::EDITABLE_FIELDS as $field) {
                if (array_key_exists($field, $data)) {
                    $snapshot->setAttribute($field, $data[$field]);
                }
            }

            $snapshot->save();

            $correlationId = (string) Str::uuid();

            // Contact details and addresses are the sourcing inputs for tax and the
            // customer identity an approver reviewed, so both are invalidated here.
            $this->invalidation->invalidateForFinancialMutation(
                $lockedQuote,
                $lockedRevision,
                $actor,
                $correlationId,
            );

            $this->lock->bumpRevisionLock($lockedRevision);

            $this->audit(
                $lockedQuote,
                $snapshot,
                'crm.quote.party_snapshot_updated',
                $before,
                $this->payload($snapshot),
                $actor,
                $correlationId,
            );

            return $snapshot->fresh() ?? $snapshot;
        });
    }

    /**
     * Companion editor for the customer-facing narrative fields on the same draft revision.
     *
     * @param  array{
     *     introduction?: string|null,
     *     terms_text?: string|null,
     *     customer_notes?: string|null,
     *     internal_notes?: string|null,
     *     expiration_date?: string|null
     * }  $data
     */
    public function updateRevisionContent(
        Quote $quote,
        QuoteRevision $revision,
        int $expectedLockVersion,
        array $data,
        ?User $actor = null,
    ): QuoteRevision {
        return DB::transaction(function () use ($quote, $revision, $expectedLockVersion, $data, $actor): QuoteRevision {
            ['quote' => $lockedQuote, 'revision' => $lockedRevision] = $this->lock->lockDraft(
                $quote,
                $revision,
                $expectedLockVersion,
            );

            $this->assertOnlyKnownKeys($data, self::EDITABLE_REVISION_FIELDS);

            $before = $this->revisionPayload($lockedRevision);

            foreach (self::EDITABLE_REVISION_FIELDS as $field) {
                if (array_key_exists($field, $data)) {
                    $lockedRevision->setAttribute($field, $data[$field]);
                }
            }

            $customerVisibleChange = array_intersect(
                array_keys($lockedRevision->getDirty()),
                self::CUSTOMER_VISIBLE_REVISION_FIELDS,
            ) !== [];

            $lockedRevision->save();

            $correlationId = (string) Str::uuid();

            if ($customerVisibleChange) {
                $this->invalidation->invalidateForFinancialMutation(
                    $lockedQuote,
                    $lockedRevision,
                    $actor,
                    $correlationId,
                );
            }

            $this->lock->bumpRevisionLock($lockedRevision);

            $this->auditor->append(
                parentAccount: ParentAccount::query()->findOrFail($lockedQuote->parent_account_id),
                action: 'crm.quote.revision_content_updated',
                subjectType: QuoteRevision::class,
                subjectId: $lockedRevision->id,
                organization: Organization::query()->findOrFail($lockedQuote->organization_id),
                actor: $actor,
                before: $before,
                after: $this->revisionPayload($lockedRevision),
                correlationId: $correlationId,
            );

            return $lockedRevision->fresh() ?? $lockedRevision;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function liveAttributes(
        Quote $quote,
        QuoteRevision $revision,
        QuoteRevisionPartySnapshot $snapshot,
    ): array {
        $preparer = Membership::query()->findOrFail($snapshot->preparer_membership_id);

        $salesperson = $quote->sales_owner_membership_id === null
            ? null
            : Membership::query()->find($quote->sales_owner_membership_id);

        $contact = $snapshot->primary_contact_id === null
            ? null
            : Contact::query()->find($snapshot->primary_contact_id);

        return $this->builder->buildAttributes(
            quote: $quote,
            revision: $revision,
            preparer: $preparer,
            primaryContact: $contact,
            salesperson: $salesperson,
            customerPoReference: $snapshot->customer_po_reference,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyContact(QuoteRevisionPartySnapshot $snapshot, mixed $contactId, array &$data): void
    {
        if ($contactId === null) {
            $snapshot->primary_contact_id = null;

            return;
        }

        $contact = Contact::query()->findOrFail((int) $contactId);

        if ($contact->company_id !== $snapshot->company_id
            || $contact->parent_account_id !== $snapshot->parent_account_id) {
            throw new InvalidQuoteDraftException('Primary contact must belong to the snapshot customer company.');
        }

        $snapshot->primary_contact_id = $contact->id;

        // Selecting a contact seeds the display fields unless the caller supplied its own values.
        $data['contact_name'] = array_key_exists('contact_name', $data)
            ? $data['contact_name']
            : $this->builder->contactName($contact);
        $data['contact_email'] = array_key_exists('contact_email', $data)
            ? $data['contact_email']
            : $contact->email;
        $data['contact_phone'] = array_key_exists('contact_phone', $data)
            ? $data['contact_phone']
            : $contact->phone;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $allowed
     */
    private function assertOnlyKnownKeys(array $data, array $allowed): void
    {
        $unknown = array_diff(array_keys($data), $allowed);

        if ($unknown !== []) {
            throw new InvalidQuoteDraftException(
                'Unsupported draft fields: '.implode(', ', $unknown).'.'
            );
        }
    }

    private function requireSnapshot(QuoteRevision $revision): QuoteRevisionPartySnapshot
    {
        $snapshot = QuoteRevisionPartySnapshot::query()
            ->where('quote_revision_id', $revision->id)
            ->first();

        if ($snapshot === null) {
            throw new InvalidQuoteDraftException("Quote revision [{$revision->id}] has no party snapshot.");
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function audit(
        Quote $quote,
        QuoteRevisionPartySnapshot $snapshot,
        string $action,
        array $before,
        array $after,
        ?User $actor,
        string $correlationId,
    ): void {
        $this->auditor->append(
            parentAccount: ParentAccount::query()->findOrFail($quote->parent_account_id),
            action: $action,
            subjectType: QuoteRevisionPartySnapshot::class,
            subjectId: $snapshot->id,
            organization: Organization::query()->findOrFail($quote->organization_id),
            actor: $actor,
            before: $before,
            after: $after,
            correlationId: $correlationId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(QuoteRevisionPartySnapshot $snapshot): array
    {
        return [
            'selling_organization_name' => $snapshot->selling_organization_name,
            'selling_organization_slug' => $snapshot->selling_organization_slug,
            'company_id' => $snapshot->company_id,
            'customer_company_name' => $snapshot->customer_company_name,
            'organization_company_id' => $snapshot->organization_company_id,
            'customer_number' => $snapshot->customer_number,
            'primary_contact_id' => $snapshot->primary_contact_id,
            'contact_name' => $snapshot->contact_name,
            'contact_email' => $snapshot->contact_email,
            'contact_phone' => $snapshot->contact_phone,
            'billing_address_json' => $snapshot->billing_address_json,
            'service_address_json' => $snapshot->service_address_json,
            'salesperson_membership_id' => $snapshot->salesperson_membership_id,
            'salesperson_name' => $snapshot->salesperson_name,
            'preparer_membership_id' => $snapshot->preparer_membership_id,
            'preparer_name' => $snapshot->preparer_name,
            'customer_po_reference' => $snapshot->customer_po_reference,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function revisionPayload(QuoteRevision $revision): array
    {
        return [
            'introduction' => $revision->introduction,
            'terms_text' => $revision->terms_text,
            'customer_notes' => $revision->customer_notes,
            'internal_notes' => $revision->internal_notes,
            'expiration_date' => $revision->expiration_date?->toDateString(),
        ];
    }
}
