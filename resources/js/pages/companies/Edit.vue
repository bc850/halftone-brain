<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTenantRoute } from '@/composables/useTenantAction';
import { dashboard } from '@/routes';
import {
    index as legacyIndex,
    show as legacyShow,
    update as legacyUpdate,
} from '@/routes/companies';
import { show as orgShow, update as orgUpdate } from '@/routes/org/companies';

const show = useTenantRoute(legacyShow, orgShow);
const update = useTenantRoute(legacyUpdate, orgUpdate);

type Option = { value: string; label: string };
type Person = { id: number; name: string };

type Company = {
    id: number;
    name: string;
    owner_id: number;
    phone: string | null;
    email: string | null;
    billing_address_line1: string | null;
    billing_address_line2: string | null;
    billing_city: string | null;
    billing_state: string | null;
    billing_postal_code: string | null;
    billing_country: string | null;
    shipping_address_line1: string | null;
    shipping_address_line2: string | null;
    shipping_city: string | null;
    shipping_state: string | null;
    shipping_postal_code: string | null;
    shipping_country: string | null;
    sales_tax_status: string;
    notes: string | null;
};

defineProps<{
    company: Company;
    salesTaxStatuses: Option[];
    salespeople: Person[];
}>();

const fieldClass =
    'border-input bg-transparent dark:bg-input/30 h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]';
const textareaClass =
    'border-input bg-transparent dark:bg-input/30 min-h-24 w-full rounded-md border px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Companies', href: legacyIndex() },
            { title: 'Edit', href: legacyIndex() },
        ],
    },
});
</script>

<template>
    <Head :title="`Edit ${company.name}`" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            :title="`Edit ${company.name}`"
            description="Update company details"
        />

        <Form
            v-bind="update.form(company.id)"
            class="mx-auto grid w-full max-w-3xl gap-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="name">Company name</Label>
                    <Input
                        id="name"
                        name="name"
                        :default-value="company.name"
                        required
                    />
                    <InputError :message="errors.name" />
                </div>

                <div v-if="salespeople.length" class="grid gap-2">
                    <Label for="owner_id">Owner</Label>
                    <select
                        id="owner_id"
                        name="owner_id"
                        :class="fieldClass"
                        :value="company.owner_id"
                    >
                        <option
                            v-for="person in salespeople"
                            :key="person.id"
                            :value="person.id"
                        >
                            {{ person.name }}
                        </option>
                    </select>
                    <InputError :message="errors.owner_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="sales_tax_status">Sales tax status</Label>
                    <select
                        id="sales_tax_status"
                        name="sales_tax_status"
                        :class="fieldClass"
                        :value="company.sales_tax_status"
                        required
                    >
                        <option
                            v-for="status in salesTaxStatuses"
                            :key="status.value"
                            :value="status.value"
                        >
                            {{ status.label }}
                        </option>
                    </select>
                    <InputError :message="errors.sales_tax_status" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">Email</Label>
                    <Input
                        id="email"
                        name="email"
                        type="email"
                        :default-value="company.email ?? ''"
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="phone">Phone</Label>
                    <Input
                        id="phone"
                        name="phone"
                        :default-value="company.phone ?? ''"
                    />
                    <InputError :message="errors.phone" />
                </div>
            </div>

            <div class="grid gap-4">
                <h2 class="text-sm font-medium">Billing address</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="billing_address_line1"
                            >Address line 1</Label
                        >
                        <Input
                            id="billing_address_line1"
                            name="billing_address_line1"
                            :default-value="company.billing_address_line1 ?? ''"
                        />
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="billing_address_line2"
                            >Address line 2</Label
                        >
                        <Input
                            id="billing_address_line2"
                            name="billing_address_line2"
                            :default-value="company.billing_address_line2 ?? ''"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="billing_city">City</Label>
                        <Input
                            id="billing_city"
                            name="billing_city"
                            :default-value="company.billing_city ?? ''"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="billing_state">State</Label>
                        <Input
                            id="billing_state"
                            name="billing_state"
                            :default-value="company.billing_state ?? ''"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="billing_postal_code">Postal code</Label>
                        <Input
                            id="billing_postal_code"
                            name="billing_postal_code"
                            :default-value="company.billing_postal_code ?? ''"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="billing_country">Country</Label>
                        <Input
                            id="billing_country"
                            name="billing_country"
                            :default-value="company.billing_country ?? 'US'"
                        />
                    </div>
                </div>
            </div>

            <div class="grid gap-4">
                <h2 class="text-sm font-medium">Shipping address</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="shipping_address_line1"
                            >Address line 1</Label
                        >
                        <Input
                            id="shipping_address_line1"
                            name="shipping_address_line1"
                            :default-value="
                                company.shipping_address_line1 ?? ''
                            "
                        />
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="shipping_address_line2"
                            >Address line 2</Label
                        >
                        <Input
                            id="shipping_address_line2"
                            name="shipping_address_line2"
                            :default-value="
                                company.shipping_address_line2 ?? ''
                            "
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="shipping_city">City</Label>
                        <Input
                            id="shipping_city"
                            name="shipping_city"
                            :default-value="company.shipping_city ?? ''"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="shipping_state">State</Label>
                        <Input
                            id="shipping_state"
                            name="shipping_state"
                            :default-value="company.shipping_state ?? ''"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="shipping_postal_code">Postal code</Label>
                        <Input
                            id="shipping_postal_code"
                            name="shipping_postal_code"
                            :default-value="company.shipping_postal_code ?? ''"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="shipping_country">Country</Label>
                        <Input
                            id="shipping_country"
                            name="shipping_country"
                            :default-value="company.shipping_country ?? 'US'"
                        />
                    </div>
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="notes">Notes</Label>
                <textarea
                    id="notes"
                    name="notes"
                    :class="textareaClass"
                    :value="company.notes ?? ''"
                />
                <InputError :message="errors.notes" />
            </div>

            <div class="flex gap-3">
                <Button type="submit" :disabled="processing"
                    >Save changes</Button
                >
                <Button variant="outline" as-child>
                    <Link :href="show(company.id)">Cancel</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
