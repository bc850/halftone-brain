<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, reactive, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useOrganizationSlug } from '@/composables/useTenantAction';
import { dashboard } from '@/routes';
import { index as legacyDealIndex } from '@/routes/deals';
import revisions from '@/routes/org/quotes/revisions';
import type { QuoteAddress, QuotePartySnapshot } from '@/types';

type RefreshPreview = {
    current: Record<string, unknown>;
    proposed: Record<string, unknown>;
    changes: string[];
    has_changes: boolean;
};

const props = defineProps<{
    quote: { id: number; quote_number: string };
    revision: { id: number; revision_number: number; lock_version: number };
    snapshot: QuotePartySnapshot | null;
    contacts: { id: number; name: string }[];
    builderUrl: string;
}>();

const slug = useOrganizationSlug();
const routeArgs: [string, number, number] = [
    slug,
    props.quote.id,
    props.revision.id,
];

const inputClass =
    'border-input bg-transparent dark:bg-input/30 h-9 w-full rounded-md border px-3 py-1 text-sm outline-none';

type AddressForm = {
    line1: string;
    line2: string;
    city: string;
    state: string;
    postal_code: string;
    country: string;
};

function address(source: QuoteAddress | null | undefined): AddressForm {
    return {
        line1: source?.line1 ?? '',
        line2: source?.line2 ?? '',
        city: source?.city ?? '',
        state: source?.state ?? '',
        postal_code: source?.postal_code ?? '',
        country: source?.country ?? '',
    };
}

const form = reactive({
    primary_contact_id: props.snapshot?.primary_contact_id ?? '',
    contact_name: props.snapshot?.contact_name ?? '',
    contact_email: props.snapshot?.contact_email ?? '',
    contact_phone: props.snapshot?.contact_phone ?? '',
    customer_po_reference: props.snapshot?.customer_po_reference ?? '',
    billing: address(props.snapshot?.billing_address),
    service: address(props.snapshot?.service_address),
});

const preview = ref<RefreshPreview | null>(null);

function save(): void {
    router.patch(
        revisions.party.update.url(routeArgs),
        {
            expected_lock_version: props.revision.lock_version,
            primary_contact_id: form.primary_contact_id || null,
            contact_name: form.contact_name || null,
            contact_email: form.contact_email || null,
            contact_phone: form.contact_phone || null,
            customer_po_reference: form.customer_po_reference || null,
            billing_address_json: form.billing,
            service_address_json: form.service,
        },
        { preserveScroll: true },
    );
}

function requestPreview(): void {
    router.post(
        revisions.party.refreshPreview.url(routeArgs),
        {},
        { preserveScroll: true },
    );
}

function confirmRefresh(): void {
    router.post(
        revisions.party.refresh.url(routeArgs),
        { expected_lock_version: props.revision.lock_version },
        { preserveScroll: true, onSuccess: () => (preview.value = null) },
    );
}

function captureFlash(event: Event): void {
    const flash = (event as CustomEvent).detail?.flash;
    const data = flash?.quotePartyRefreshPreview as RefreshPreview | undefined;

    if (data) {
        preview.value = data;
    }
}

let stopListening: (() => void) | undefined;

onMounted(() => {
    stopListening = router.on('flash', captureFlash);
});

onUnmounted(() => stopListening?.());

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Deals', href: legacyDealIndex() },
            { title: 'Quote customer', href: legacyDealIndex() },
        ],
    },
});
</script>

<template>
    <Head :title="`Customer — ${props.quote.quote_number}`" />

    <div class="mx-auto flex max-w-3xl flex-col gap-6 p-4">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                title="Customer and addresses"
                :description="`${props.quote.quote_number} revision ${props.revision.revision_number}. The quote keeps this snapshot even if the CRM record changes.`"
            />
            <Button variant="outline" as-child>
                <Link :href="props.builderUrl">Back to builder</Link>
            </Button>
        </div>

        <section class="space-y-3 rounded-xl border p-4 text-sm">
            <div class="flex items-center justify-between gap-4">
                <h2 class="font-medium">Refresh from CRM</h2>
                <Button variant="outline" size="sm" @click="requestPreview">
                    Preview changes
                </Button>
            </div>
            <div v-if="preview">
                <p v-if="!preview.has_changes" class="text-muted-foreground">
                    The snapshot already matches the CRM records.
                </p>
                <div v-else class="space-y-2">
                    <ul class="list-inside list-disc">
                        <li v-for="field in preview.changes" :key="field">
                            {{ field }}
                        </li>
                    </ul>
                    <Button size="sm" @click="confirmRefresh">
                        Apply refresh
                    </Button>
                </div>
            </div>
        </section>

        <section class="space-y-4 rounded-xl border p-4 text-sm">
            <h2 class="font-medium">Contact</h2>
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="space-y-1">
                    <Label for="primary_contact_id">Primary contact</Label>
                    <select
                        id="primary_contact_id"
                        v-model="form.primary_contact_id"
                        :class="inputClass"
                    >
                        <option value="">No contact</option>
                        <option
                            v-for="contact in props.contacts"
                            :key="contact.id"
                            :value="contact.id"
                        >
                            {{ contact.name }}
                        </option>
                    </select>
                </div>
                <div class="space-y-1">
                    <Label for="contact_name">Contact name</Label>
                    <Input id="contact_name" v-model="form.contact_name" />
                </div>
                <div class="space-y-1">
                    <Label for="contact_email">Email</Label>
                    <Input
                        id="contact_email"
                        v-model="form.contact_email"
                        type="email"
                    />
                </div>
                <div class="space-y-1">
                    <Label for="contact_phone">Phone</Label>
                    <Input id="contact_phone" v-model="form.contact_phone" />
                </div>
                <div class="space-y-1">
                    <Label for="customer_po_reference">Customer PO</Label>
                    <Input
                        id="customer_po_reference"
                        v-model="form.customer_po_reference"
                    />
                </div>
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <div class="space-y-2">
                    <h3 class="text-xs font-medium uppercase">
                        Billing address
                    </h3>
                    <Input v-model="form.billing.line1" placeholder="Line 1" />
                    <Input v-model="form.billing.line2" placeholder="Line 2" />
                    <Input v-model="form.billing.city" placeholder="City" />
                    <Input v-model="form.billing.state" placeholder="State" />
                    <Input
                        v-model="form.billing.postal_code"
                        placeholder="Postal code"
                    />
                    <Input
                        v-model="form.billing.country"
                        placeholder="Country"
                    />
                </div>
                <div class="space-y-2">
                    <h3 class="text-xs font-medium uppercase">
                        Service address
                    </h3>
                    <Input v-model="form.service.line1" placeholder="Line 1" />
                    <Input v-model="form.service.line2" placeholder="Line 2" />
                    <Input v-model="form.service.city" placeholder="City" />
                    <Input v-model="form.service.state" placeholder="State" />
                    <Input
                        v-model="form.service.postal_code"
                        placeholder="Postal code"
                    />
                    <Input
                        v-model="form.service.country"
                        placeholder="Country"
                    />
                </div>
            </div>

            <Button size="sm" @click="save">Save customer details</Button>
        </section>
    </div>
</template>
