<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useOrganizationSlug } from '@/composables/useTenantAction';
import { dashboard } from '@/routes';
import { index as legacyDealIndex } from '@/routes/deals';
import { show as showDeal } from '@/routes/org/deals';
import { store as storeQuote } from '@/routes/org/deals/quotes';

const props = defineProps<{
    deal: {
        id: number;
        name: string;
        company_id: number;
        primary_contact_id: number | null;
        organization_company_id: number | null;
    };
    customerReady: boolean;
    contacts: { id: number; name: string }[];
    salespeople: { id: number; name: string }[];
    defaultSalesOwnerMembershipId: number;
    quoteNumberPrefix: string;
}>();

const slug = useOrganizationSlug();

const fieldClass =
    'border-input bg-transparent dark:bg-input/30 h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs outline-none';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Deals', href: legacyDealIndex() },
            { title: 'New quote', href: legacyDealIndex() },
        ],
    },
});
</script>

<template>
    <Head title="New quote" />

    <div class="mx-auto flex max-w-2xl flex-col gap-6 p-4">
        <Heading
            title="New quote"
            :description="`Creates revision 1 as a draft on ${props.deal.name}.`"
        />

        <div
            v-if="!props.customerReady"
            class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
        >
            This deal has no organization customer record yet. Associate the
            company with this organization before quoting.
        </div>

        <p class="text-sm text-muted-foreground">
            The quote number is allocated on save using the
            <span class="font-mono">{{ props.quoteNumberPrefix }}</span>
            sequence. A failed save burns the number rather than reusing it.
        </p>

        <Form
            v-bind="storeQuote.form([slug, props.deal.id])"
            class="space-y-4"
            v-slot="{ errors, processing }"
        >
            <div class="space-y-2">
                <Label for="sales_owner_membership_id">Sales owner</Label>
                <select
                    id="sales_owner_membership_id"
                    name="sales_owner_membership_id"
                    :class="fieldClass"
                    :value="props.defaultSalesOwnerMembershipId"
                >
                    <option
                        v-for="person in props.salespeople"
                        :key="person.id"
                        :value="person.id"
                    >
                        {{ person.name }}
                    </option>
                </select>
                <InputError :message="errors.sales_owner_membership_id" />
            </div>

            <div class="space-y-2">
                <Label for="primary_contact_id">Primary contact</Label>
                <select
                    id="primary_contact_id"
                    name="primary_contact_id"
                    :class="fieldClass"
                    :value="props.deal.primary_contact_id ?? ''"
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
                <InputError :message="errors.primary_contact_id" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-2">
                    <Label for="expiration_date">Expiration date</Label>
                    <Input
                        id="expiration_date"
                        name="expiration_date"
                        type="date"
                        :class="fieldClass"
                    />
                    <InputError :message="errors.expiration_date" />
                </div>
                <div class="space-y-2">
                    <Label for="customer_po_reference">Customer PO</Label>
                    <Input
                        id="customer_po_reference"
                        name="customer_po_reference"
                        type="text"
                        :class="fieldClass"
                    />
                    <InputError :message="errors.customer_po_reference" />
                </div>
            </div>

            <div class="space-y-2">
                <Label for="introduction">Introduction</Label>
                <textarea
                    id="introduction"
                    name="introduction"
                    rows="3"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm outline-none dark:bg-input/30"
                />
                <InputError :message="errors.introduction" />
            </div>

            <div class="space-y-2">
                <Label for="terms_text">Terms</Label>
                <textarea
                    id="terms_text"
                    name="terms_text"
                    rows="3"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm outline-none dark:bg-input/30"
                />
                <InputError :message="errors.terms_text" />
            </div>

            <InputError :message="errors.quote" />

            <div class="flex gap-2">
                <Button
                    type="submit"
                    :disabled="processing || !slug || !props.customerReady"
                >
                    Create quote
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="showDeal([slug, props.deal.id])">Cancel</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
