<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import {
    useOrganizationSlug,
    useTenantRoute,
} from '@/composables/useTenantAction';
import { dashboard } from '@/routes';
import { show as legacyShowCompany } from '@/routes/companies';
import { show as legacyShowContact } from '@/routes/contacts';
import {
    destroy as legacyDestroy,
    edit as legacyEdit,
    index as legacyIndex,
    stage as legacyStage,
} from '@/routes/deals';
import { show as orgShowCompany } from '@/routes/org/companies';
import { show as orgShowContact } from '@/routes/org/contacts';
import {
    destroy as orgDestroy,
    edit as orgEdit,
    stage as orgStage,
} from '@/routes/org/deals';
import {
    create as createQuote,
    index as quoteIndex,
} from '@/routes/org/deals/quotes';
import { show as showQuote } from '@/routes/org/quotes';
import type { QuoteSummary } from '@/types';

const destroy = useTenantRoute(legacyDestroy, orgDestroy);
const edit = useTenantRoute(legacyEdit, orgEdit);
const updateStage = useTenantRoute(legacyStage, orgStage);
const showCompany = useTenantRoute(legacyShowCompany, orgShowCompany);
const showContact = useTenantRoute(legacyShowContact, orgShowContact);

type StageOption = { value: string; label: string };

type Deal = {
    id: number;
    name: string;
    stage: string;
    amount: string | null;
    expected_close_date: string | null;
    notes: string | null;
    company?: { id: number; name: string } | null;
    owner?: { id: number; name: string } | null;
    primary_contact?: {
        id: number;
        first_name: string;
        last_name: string;
        email: string | null;
        phone: string | null;
    } | null;
    contacts: {
        id: number;
        first_name: string;
        last_name: string;
        email: string | null;
        phone: string | null;
    }[];
    can_update: boolean;
    can_delete: boolean;
};

const props = defineProps<{
    deal: Deal;
    stages: StageOption[];
    quotes: QuoteSummary[];
    canViewQuotes: boolean;
    canCreateQuote: boolean;
}>();

const organizationSlug = useOrganizationSlug();

const fieldClass =
    'border-input bg-transparent dark:bg-input/30 h-9 rounded-md border px-3 py-1 text-sm outline-none';

function stageLabel(value: string): string {
    return props.stages.find((stage) => stage.value === value)?.label ?? value;
}

function moveDeal(event: Event): void {
    const nextStage = (event.target as HTMLSelectElement).value;
    router.patch(
        updateStage.url(props.deal.id),
        { stage: nextStage },
        { preserveScroll: true },
    );
}

function deleteDeal(): void {
    if (confirm('Delete this deal?')) {
        router.delete(destroy.url(props.deal.id));
    }
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Deals', href: legacyIndex() },
            { title: 'Deal', href: legacyIndex() },
        ],
    },
});
</script>

<template>
    <Head :title="deal.name" />

    <div class="flex flex-col gap-6 p-4">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                :title="deal.name"
                :description="deal.company ? deal.company.name : undefined"
            />
            <Button v-if="deal.can_update" variant="outline" as-child>
                <Link :href="edit(deal.id)">
                    <Pencil class="size-4" />
                    Edit
                </Link>
            </Button>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="space-y-3 rounded-xl border p-4 text-sm">
                <h2 class="font-medium">Details</h2>
                <dl class="grid gap-2">
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Company</dt>
                        <dd>
                            <Link
                                v-if="deal.company"
                                :href="showCompany(deal.company.id)"
                                class="hover:underline"
                            >
                                {{ deal.company.name }}
                            </Link>
                            <span v-else>—</span>
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Owner</dt>
                        <dd>{{ deal.owner?.name ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Amount</dt>
                        <dd>{{ deal.amount ? `$${deal.amount}` : '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Expected close</dt>
                        <dd>
                            {{ deal.expected_close_date?.slice(0, 10) ?? '—' }}
                        </dd>
                    </div>
                    <div
                        v-if="deal.can_update"
                        class="flex items-center justify-between gap-4"
                    >
                        <dt class="text-muted-foreground">Stage</dt>
                        <dd>
                            <select
                                :class="fieldClass"
                                :value="deal.stage"
                                @change="moveDeal"
                            >
                                <option
                                    v-for="stage in stages"
                                    :key="stage.value"
                                    :value="stage.value"
                                >
                                    {{ stage.label }}
                                </option>
                            </select>
                        </dd>
                    </div>
                    <div v-else class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Stage</dt>
                        <dd>{{ stageLabel(deal.stage) }}</dd>
                    </div>
                </dl>
                <p v-if="deal.notes" class="text-muted-foreground">
                    {{ deal.notes }}
                </p>
            </section>

            <section class="space-y-3 rounded-xl border p-4 text-sm">
                <h2 class="font-medium">Contacts</h2>
                <div
                    v-if="deal.primary_contact"
                    class="rounded-md bg-muted/40 p-3"
                >
                    <p class="text-xs text-muted-foreground">Primary</p>
                    <Link
                        :href="showContact(deal.primary_contact.id)"
                        class="font-medium hover:underline"
                    >
                        {{ deal.primary_contact.first_name }}
                        {{ deal.primary_contact.last_name }}
                    </Link>
                    <p class="text-muted-foreground">
                        {{
                            deal.primary_contact.email ??
                            deal.primary_contact.phone ??
                            '—'
                        }}
                    </p>
                </div>
                <ul class="divide-y">
                    <li
                        v-for="contact in deal.contacts"
                        :key="contact.id"
                        class="py-2"
                    >
                        <Link
                            :href="showContact(contact.id)"
                            class="hover:underline"
                        >
                            {{ contact.first_name }} {{ contact.last_name }}
                        </Link>
                    </li>
                    <li
                        v-if="
                            deal.contacts.length === 0 && !deal.primary_contact
                        "
                        class="py-4 text-muted-foreground"
                    >
                        No contacts linked.
                    </li>
                </ul>
            </section>
        </div>

        <section
            v-if="props.canViewQuotes && organizationSlug"
            class="space-y-3 rounded-xl border p-4 text-sm"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-medium">Quotes</h2>
                <div class="flex gap-2">
                    <Button variant="ghost" size="sm" as-child>
                        <Link :href="quoteIndex([organizationSlug, deal.id])">
                            View all
                        </Link>
                    </Button>
                    <Button
                        v-if="props.canCreateQuote"
                        variant="outline"
                        size="sm"
                        as-child
                    >
                        <Link :href="createQuote([organizationSlug, deal.id])">
                            New quote
                        </Link>
                    </Button>
                </div>
            </div>
            <ul class="divide-y">
                <li
                    v-for="quote in props.quotes"
                    :key="quote.id"
                    class="flex flex-wrap items-center justify-between gap-2 py-2"
                >
                    <Link
                        class="font-medium hover:underline"
                        :href="showQuote([organizationSlug, quote.id])"
                    >
                        {{ quote.quote_number }}
                    </Link>
                    <div class="flex items-center gap-3 text-muted-foreground">
                        <span>
                            {{ quote.current_revision?.status ?? '—' }}
                        </span>
                        <span v-if="quote.current_revision?.tax_pending">
                            Tax pending
                        </span>
                        <span class="text-foreground">
                            {{
                                quote.current_revision
                                    ? `$${quote.current_revision.pretax_total} pre-tax`
                                    : '—'
                            }}
                        </span>
                    </div>
                </li>
                <li
                    v-if="props.quotes.length === 0"
                    class="py-3 text-muted-foreground"
                >
                    No quotes on this deal yet.
                </li>
            </ul>
        </section>

        <Button
            v-if="deal.can_delete"
            variant="destructive"
            class="w-fit"
            @click="deleteDeal"
        >
            Delete deal
        </Button>
    </div>
</template>
