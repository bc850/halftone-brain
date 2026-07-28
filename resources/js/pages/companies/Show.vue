<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Stamp } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { useTenantRoute } from '@/composables/useTenantAction';
import { dashboard } from '@/routes';
import {
    destroy as legacyDestroy,
    edit as legacyEdit,
    index as legacyIndex,
} from '@/routes/companies';
import {
    create as legacyCreateContact,
    show as legacyShowContact,
} from '@/routes/contacts';
import {
    create as legacyCreateDeal,
    show as legacyShowDeal,
} from '@/routes/deals';
import { destroy as orgDestroy, edit as orgEdit } from '@/routes/org/companies';
import {
    create as orgCreateContact,
    show as orgShowContact,
} from '@/routes/org/contacts';
import {
    create as orgCreateDeal,
    show as orgShowDeal,
} from '@/routes/org/deals';

const destroy = useTenantRoute(legacyDestroy, orgDestroy);
const edit = useTenantRoute(legacyEdit, orgEdit);
const createContact = useTenantRoute(legacyCreateContact, orgCreateContact);
const showContact = useTenantRoute(legacyShowContact, orgShowContact);
const createDeal = useTenantRoute(legacyCreateDeal, orgCreateDeal);
const showDeal = useTenantRoute(legacyShowDeal, orgShowDeal);

type Contact = {
    id: number;
    first_name: string;
    last_name: string;
    email: string | null;
    phone: string | null;
    title: string | null;
    is_primary: boolean;
};

type Deal = {
    id: number;
    name: string;
    stage: string;
    amount: string | null;
    owner?: { id: number; name: string } | null;
};

type Company = {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    sales_tax_status: string;
    notes: string | null;
    billing_address_line1: string | null;
    billing_city: string | null;
    billing_state: string | null;
    billing_postal_code: string | null;
    shipping_address_line1: string | null;
    shipping_city: string | null;
    shipping_state: string | null;
    shipping_postal_code: string | null;
    owner?: { id: number; name: string } | null;
    contacts: Contact[];
    deals: Deal[];
};

const props = defineProps<{
    company: Company;
    canCreateDeal: boolean;
    taxCertificatesUrl?: string | null;
    canViewTaxCertificates?: boolean;
}>();

function formatAddress(
    line1: string | null,
    city: string | null,
    state: string | null,
    postal: string | null,
): string {
    return (
        [line1, [city, state].filter(Boolean).join(', '), postal]
            .filter(Boolean)
            .join(' · ') || '—'
    );
}

function deleteCompany(): void {
    if (confirm('Delete this company?')) {
        router.delete(destroy.url(props.company.id));
    }
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Companies', href: legacyIndex() },
            { title: 'Company', href: legacyIndex() },
        ],
    },
});
</script>

<template>
    <Head :title="company.name" />

    <div class="flex flex-col gap-6 p-4">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                :title="company.name"
                :description="
                    company.owner ? `Owned by ${company.owner.name}` : undefined
                "
            />
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" as-child>
                    <Link :href="edit(company.id)">
                        <Pencil class="size-4" />
                        Edit
                    </Link>
                </Button>
                <Button
                    v-if="
                        props.canViewTaxCertificates && props.taxCertificatesUrl
                    "
                    variant="outline"
                    as-child
                >
                    <Link :href="props.taxCertificatesUrl">
                        <Stamp class="size-4" />
                        Tax certificates
                    </Link>
                </Button>
                <Button variant="outline" as-child>
                    <Link
                        :href="
                            createContact({ query: { company_id: company.id } })
                        "
                    >
                        <Plus class="size-4" />
                        Contact
                    </Link>
                </Button>
                <Button as-child>
                    <Link
                        :href="
                            createDeal({ query: { company_id: company.id } })
                        "
                    >
                        <Plus class="size-4" />
                        Deal
                    </Link>
                </Button>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="space-y-3 rounded-xl border p-4">
                <h2 class="font-medium">Details</h2>
                <dl class="grid gap-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Email</dt>
                        <dd>{{ company.email ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Phone</dt>
                        <dd>{{ company.phone ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Tax status</dt>
                        <dd class="capitalize">
                            {{ company.sales_tax_status }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Billing</dt>
                        <dd class="text-right">
                            {{
                                formatAddress(
                                    company.billing_address_line1,
                                    company.billing_city,
                                    company.billing_state,
                                    company.billing_postal_code,
                                )
                            }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Shipping</dt>
                        <dd class="text-right">
                            {{
                                formatAddress(
                                    company.shipping_address_line1,
                                    company.shipping_city,
                                    company.shipping_state,
                                    company.shipping_postal_code,
                                )
                            }}
                        </dd>
                    </div>
                </dl>
                <p v-if="company.notes" class="text-sm text-muted-foreground">
                    {{ company.notes }}
                </p>
            </section>

            <section class="space-y-3 rounded-xl border p-4">
                <h2 class="font-medium">Contacts</h2>
                <ul class="divide-y text-sm">
                    <li
                        v-for="contact in company.contacts"
                        :key="contact.id"
                        class="flex items-center justify-between gap-3 py-2"
                    >
                        <div class="min-w-0">
                            <Link
                                :href="showContact(contact.id)"
                                class="hover:underline"
                            >
                                {{ contact.first_name }} {{ contact.last_name }}
                                <span
                                    v-if="contact.is_primary"
                                    class="ml-2 text-xs text-muted-foreground"
                                    >Primary</span
                                >
                            </Link>
                            <p class="truncate text-muted-foreground">
                                {{ contact.email ?? contact.phone ?? '—' }}
                            </p>
                        </div>
                        <Button
                            v-if="canCreateDeal"
                            variant="outline"
                            size="sm"
                            as-child
                        >
                            <Link
                                :href="
                                    createDeal({
                                        query: {
                                            company_id: company.id,
                                            contact_id: contact.id,
                                        },
                                    })
                                "
                            >
                                <Plus class="size-3.5" />
                                Deal
                            </Link>
                        </Button>
                    </li>
                    <li
                        v-if="company.contacts.length === 0"
                        class="py-4 text-muted-foreground"
                    >
                        No contacts yet.
                    </li>
                </ul>
            </section>

            <section class="space-y-3 rounded-xl border p-4 lg:col-span-2">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="font-medium">Deals</h2>
                    <Button v-if="canCreateDeal" size="sm" as-child>
                        <Link
                            :href="
                                createDeal({
                                    query: { company_id: company.id },
                                })
                            "
                        >
                            <Plus class="size-3.5" />
                            New deal
                        </Link>
                    </Button>
                </div>
                <ul class="divide-y text-sm">
                    <li
                        v-for="deal in company.deals"
                        :key="deal.id"
                        class="flex items-center justify-between gap-3 py-2"
                    >
                        <Link :href="showDeal(deal.id)" class="hover:underline">
                            {{ deal.name }}
                        </Link>
                        <div class="flex gap-4 text-muted-foreground">
                            <span class="capitalize">{{
                                deal.stage.replaceAll('_', ' ')
                            }}</span>
                            <span>{{
                                deal.amount ? `$${deal.amount}` : '—'
                            }}</span>
                        </div>
                    </li>
                    <li
                        v-if="company.deals.length === 0"
                        class="py-4 text-muted-foreground"
                    >
                        No deals yet.
                    </li>
                </ul>
            </section>
        </div>

        <Button variant="destructive" @click="deleteCompany"
            >Delete company</Button
        >
    </div>
</template>
