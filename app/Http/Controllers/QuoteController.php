<?php

namespace App\Http\Controllers;

use App\Enums\MembershipStatus;
use App\Http\Controllers\Concerns\HandlesQuoteDrafts;
use App\Http\Controllers\Concerns\RequiresTenantContext;
use App\Http\Requests\StoreQuoteRequest;
use App\Http\Resources\QuoteResource;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrganizationCompany;
use App\Models\Quote;
use App\Models\User;
use App\Support\Quotes\QuoteFactoryService;
use App\Support\Quotes\QuoteNumberSequenceDefinitions;
use App\Support\Tenancy\TenantRoute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class QuoteController extends Controller
{
    use HandlesQuoteDrafts;
    use RequiresTenantContext;

    public function __construct(private QuoteFactoryService $quotes) {}

    public function indexForDeal(Request $request, ?Organization $organization, Deal $deal): Response
    {
        $this->requireTenantContext();
        $this->authorize('view', $deal);
        $this->authorize('viewAny', Quote::class);

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('quotes/Index', [
            'deal' => ['id' => $deal->id, 'name' => $deal->name],
            'quotes' => QuoteResource::collection(self::quotesForDeal($deal), $user),
            'canCreate' => $user->can('create', Quote::class),
        ]);
    }

    public function create(Request $request, ?Organization $organization, Deal $deal): Response
    {
        $tenant = $this->requireTenantContext();
        $this->authorize('view', $deal);
        $this->authorize('create', Quote::class);

        $organizationCompany = $deal->organization_company_id === null
            ? null
            : OrganizationCompany::query()->find($deal->organization_company_id);

        return Inertia::render('quotes/Create', [
            'deal' => [
                'id' => $deal->id,
                'name' => $deal->name,
                'company_id' => $deal->company_id,
                'primary_contact_id' => $deal->primary_contact_id,
                'organization_company_id' => $deal->organization_company_id,
            ],
            'customerReady' => $organizationCompany !== null,
            'contacts' => Contact::query()
                ->where('company_id', $deal->company_id)
                ->orderByDesc('is_primary')
                ->orderBy('last_name')
                ->get(['id', 'first_name', 'last_name'])
                ->map(fn (Contact $contact): array => [
                    'id' => $contact->id,
                    'name' => trim($contact->first_name.' '.$contact->last_name),
                ])
                ->values()
                ->all(),
            'salespeople' => Membership::query()
                ->with('user:id,name')
                ->where('organization_id', $tenant->organizationId)
                ->where('status', MembershipStatus::Active)
                ->get()
                ->map(fn (Membership $membership): array => [
                    'id' => $membership->id,
                    'name' => $membership->user->name,
                ])
                ->sortBy('name')
                ->values()
                ->all(),
            'defaultSalesOwnerMembershipId' => $tenant->organizationMembershipId,
            'quoteNumberPrefix' => QuoteNumberSequenceDefinitions::prefixForOrganizationSlug(
                $tenant->organization->slug,
            ),
        ]);
    }

    public function store(StoreQuoteRequest $request, ?Organization $organization, Deal $deal): RedirectResponse
    {
        $tenant = $this->requireTenantContext();
        $this->authorize('view', $deal);
        $this->authorize('create', Quote::class);

        $data = $request->validated();
        $createdBy = Membership::query()->findOrFail($tenant->organizationMembershipId);

        $salesOwner = empty($data['sales_owner_membership_id'])
            ? $createdBy
            : Membership::query()->findOrFail((int) $data['sales_owner_membership_id']);

        $primaryContact = empty($data['primary_contact_id'])
            ? null
            : Contact::query()->findOrFail((int) $data['primary_contact_id']);

        $slug = $tenant->organization->slug;

        $quote = $this->runDraftMutation(fn (): Quote => $this->quotes->create(
            deal: $deal,
            createdByMembership: $createdBy,
            organization: $tenant->organization,
            quotePrefix: QuoteNumberSequenceDefinitions::prefixForOrganizationSlug($slug),
            padLength: QuoteNumberSequenceDefinitions::padLengthForOrganizationSlug($slug),
            salesOwnerMembership: $salesOwner,
            actor: $request->user(),
            primaryContact: $primaryContact,
            expirationDate: $data['expiration_date'] ?? null,
            customerPoReference: $data['customer_po_reference'] ?? null,
            introduction: $data['introduction'] ?? null,
            termsText: $data['terms_text'] ?? null,
            customerNotes: $data['customer_notes'] ?? null,
            internalNotes: $data['internal_notes'] ?? null,
        ));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Quote :number created.', ['number' => $quote->quote_number]),
        ]);

        return redirect()->to(TenantRoute::to('quotes.revisions.edit', [
            'quote' => $quote,
            'quoteRevision' => $quote->current_revision_id,
        ]));
    }

    public function show(Request $request, ?Organization $organization, Quote $quote): Response
    {
        $this->requireTenantContext();
        $this->authorize('view', $quote);

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('quotes/Show', [
            'quote' => QuoteResource::make($quote, $user),
        ]);
    }

    /**
     * Quote summaries for a deal, used by both the quote list and the deal detail page.
     *
     * @return Collection<int, Quote>
     */
    public static function quotesForDeal(Deal $deal)
    {
        return Quote::query()
            ->with('currentRevision')
            ->where('deal_id', $deal->id)
            ->where('organization_id', $deal->organization_id)
            ->orderByDesc('id')
            ->get();
    }
}
