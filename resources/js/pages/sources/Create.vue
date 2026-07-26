<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { store as storeSource } from '@/routes/org/products/sources';
import type { Tenant } from '@/types';

type OfferingOption = {
    id: number;
    vendor_sku: string;
    vendor_description: string | null;
    purchase_uom: string;
    purchase_uom_label: string;
    package_quantity: string;
    vendor: { id: number; name: string } | null;
};

const props = defineProps<{
    organizationProduct: {
        id: number;
        name: string;
        sku: string;
        currency_code: string;
        purchase_unit_of_measure: string | null;
        is_purchasable: boolean;
    };
    offerings: OfferingOption[];
    returnUrl: string;
}>();

const slug = (usePage().props.tenant as Tenant | null | undefined)?.organization
    ?.slug;

const storeForm = computed(() => {
    if (!slug) {
        return { action: props.returnUrl, method: 'post' as const };
    }

    return storeSource.form([slug, props.organizationProduct.id]);
});

const fieldClass =
    'border-input bg-transparent dark:bg-input/30 h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs outline-none';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Vendor source', href: dashboard() },
        ],
    },
});
</script>

<template>
    <Head title="Add vendor source" />

    <div class="mx-auto flex max-w-2xl flex-col gap-6 p-4">
        <Heading
            title="Add vendor source"
            description="Attach a shared vendor offering to this organization product and optionally set an organization package price."
        />

        <div
            class="space-y-2 rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm text-sky-950 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-100"
        >
            <p>
                Shared vendor offering definitions are parent-account shared.
                Package prices are organization-specific.
            </p>
            <p>
                Selecting a preferred source later updates this organization’s
                effective material cost. It does not order material or change
                inventory.
            </p>
        </div>

        <div class="text-sm text-muted-foreground">
            <p>
                Product:
                <span class="font-medium text-foreground">{{
                    organizationProduct.name
                }}</span>
            </p>
            <p>
                Internal SKU:
                <span class="font-mono text-foreground">{{
                    organizationProduct.sku
                }}</span>
            </p>
        </div>

        <Form
            v-bind="storeForm"
            class="space-y-4"
            v-slot="{ errors, processing }"
        >
            <div class="space-y-2">
                <Label for="vendor_product_offering_id">Vendor offering</Label>
                <select
                    id="vendor_product_offering_id"
                    name="vendor_product_offering_id"
                    required
                    class="h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none dark:bg-input/30"
                >
                    <option value="">Select offering…</option>
                    <option
                        v-for="offering in offerings"
                        :key="offering.id"
                        :value="offering.id"
                    >
                        {{ offering.vendor?.name ?? 'Vendor' }} —
                        {{ offering.vendor_sku }} ({{
                            offering.package_quantity
                        }}
                        {{ offering.purchase_uom_label }})
                    </option>
                </select>
                <InputError :message="errors.vendor_product_offering_id" />
            </div>

            <div class="space-y-2">
                <Label for="package_price"
                    >Organization package price (optional)</Label
                >
                <Input
                    id="package_price"
                    name="package_price"
                    type="text"
                    inputmode="decimal"
                    placeholder="800.0000"
                    :class="fieldClass"
                />
                <p class="text-xs text-muted-foreground">
                    Price for one vendor package in
                    {{ organizationProduct.currency_code }}. Leave blank to
                    attach without a price.
                </p>
                <InputError :message="errors.package_price" />
            </div>

            <div class="space-y-2">
                <Label for="note">Note (optional)</Label>
                <Input id="note" name="note" type="text" :class="fieldClass" />
                <InputError :message="errors.note" />
            </div>

            <div class="flex gap-2">
                <Button type="submit" :disabled="processing || !slug"
                    >Attach source</Button
                >
                <Button variant="outline" as-child>
                    <Link :href="returnUrl">Cancel</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
