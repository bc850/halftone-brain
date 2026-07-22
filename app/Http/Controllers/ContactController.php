<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreContactRequest;
use App\Http\Requests\UpdateContactRequest;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Contact::class);

        /** @var User $user */
        $user = $request->user();

        $contacts = Contact::query()
            ->visibleTo($user)
            ->with('company:id,name,owner_id')
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('contacts/Index', [
            'contacts' => $contacts,
            'filters' => [
                'search' => $request->string('search')->toString(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Contact::class);

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('contacts/Create', [
            'companies' => Company::query()
                ->visibleTo($user)
                ->orderBy('name')
                ->get(['id', 'name']),
            'selectedCompanyId' => $request->integer('company_id') ?: null,
        ]);
    }

    public function store(StoreContactRequest $request): RedirectResponse
    {
        $this->authorize('view', $request->company());

        $data = $request->validated();
        $data['is_primary'] = $request->boolean('is_primary');

        $contact = DB::transaction(function () use ($data): Contact {
            if ($data['is_primary'] === true) {
                Contact::query()
                    ->where('company_id', $data['company_id'])
                    ->update(['is_primary' => false]);
            }

            return Contact::query()->create($data);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contact created.')]);

        return to_route('contacts.show', $contact);
    }

    public function show(Contact $contact): Response
    {
        $this->authorize('view', $contact);

        $contact->load('company:id,name,owner_id');

        return Inertia::render('contacts/Show', [
            'contact' => $contact,
        ]);
    }

    public function edit(Request $request, Contact $contact): Response
    {
        $this->authorize('update', $contact);

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('contacts/Edit', [
            'contact' => $contact,
            'companies' => Company::query()
                ->visibleTo($user)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function update(UpdateContactRequest $request, Contact $contact): RedirectResponse
    {
        $data = $request->validated();
        $data['is_primary'] = $request->boolean('is_primary');

        DB::transaction(function () use ($contact, $data): void {
            if ($data['is_primary'] === true) {
                Contact::query()
                    ->where('company_id', $data['company_id'])
                    ->whereKeyNot($contact->id)
                    ->update(['is_primary' => false]);
            }

            $contact->update($data);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contact updated.')]);

        return to_route('contacts.show', $contact);
    }

    public function destroy(Contact $contact): RedirectResponse
    {
        $this->authorize('delete', $contact);

        $contact->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contact deleted.')]);

        return to_route('contacts.index');
    }
}
