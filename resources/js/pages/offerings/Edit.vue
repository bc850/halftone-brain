<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { update as updateForProduct } from '@/routes/org/products/offerings';
import { update as updateForVendor } from '@/routes/org/vendors/offerings';
import type { Tenant } from '@/types';

type SelectOption = { value: string; label: string };

type Offering = {
    id: number;
    vendor_sku: string;
    vendor_description: string | null;
    manufacturer: string | null;
    manufacturer_part_number: string | null;
    product_url: string | null;
    purchase_uom: string;
    package_quantity: string;
    minimum_order_quantity: string | null;
    lead_time_days: number | null;
    status: string;
    status_label: string;
    product: { id: number; name: string; sku: string } | null;
    vendor: { id: number; name: string } | null;
};

const props = defineProps<{
    offering: Offering;
    units: SelectOption[];
    context: 'product' | 'vendor';
    organizationProductId?: number;
    vendorId?: number;
    returnUrl: string;
}>();

const slug = (usePage().props.tenant as Tenant | null | undefined)?.organization
    ?.slug;

const updateForm = computed(() => {
    if (!slug) {
        return { action: props.returnUrl, method: 'post' as const };
    }

    if (props.context === 'product' && props.organizationProductId) {
        return updateForProduct.form([
            slug,
            props.organizationProductId,
            props.offering.id,
        ]);
    }

    if (props.context === 'vendor' && props.vendorId) {
        return updateForVendor.form([slug, props.vendorId, props.offering.id]);
    }

    return { action: props.returnUrl, method: 'post' as const };
});

const fieldClass =
    'border-input bg-transparent dark:bg-input/30 h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs outline-none';
const textareaClass =
    'border-input bg-transparent dark:bg-input/30 min-h-24 w-full rounded-md border px-3 py-2 text-sm shadow-xs outline-none';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Edit offering', href: dashboard() },
        ],
    },
});
</script>

<template>
    <Head title="Edit vendor offering" />

    <div class="mx-auto flex max-w-2xl flex-col gap-6 p-4">
        <Heading
            title="Edit vendor offering"
            :description="`Status: ${offering.status_label}`"
        />

        <div
            class="space-y-2 rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm text-sky-950 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-100"
        >
            <p>
                Vendor SKU does not replace the internal product SKU
                <span v-if="offering.product">
                    ({{ offering.product.sku }})</span
                >.
            </p>
            <p>Vendor offerings are shared across organizations.</p>
            <p v-if="offering.status === 'discontinued'">
                Saving these details will not reactivate a discontinued
                offering. Use Reactivate separately.
            </p>
        </div>

        <Form
            v-bind="updateForm"
            class="space-y-4 rounded-xl border p-4"
            v-slot="{ errors, processing }"
        >
            <div class="space-y-1 text-sm">
                <p>
                    <span class="text-muted-foreground">Internal SKU:</span>
                    {{ offering.product?.sku ?? '—' }}
                </p>
                <p>
                    <span class="text-muted-foreground">Vendor:</span>
                    {{ offering.vendor?.name ?? '—' }}
                </p>
            </div>

            <div class="grid gap-2">
                <Label for="vendor_sku">Vendor SKU / item number</Label>
                <Input
                    id="vendor_sku"
                    name="vendor_sku"
                    required
                    :default-value="offering.vendor_sku"
                />
                <InputError :message="errors.vendor_sku" />
            </div>

            <div class="grid gap-2">
                <Label for="vendor_description">Vendor description</Label>
                <textarea
                    id="vendor_description"
                    name="vendor_description"
                    :class="textareaClass"
                    :value="offering.vendor_description ?? ''"
                />
                <InputError :message="errors.vendor_description" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="manufacturer">Manufacturer</Label>
                    <Input
                        id="manufacturer"
                        name="manufacturer"
                        :default-value="offering.manufacturer ?? ''"
                    />
                    <InputError :message="errors.manufacturer" />
                </div>
                <div class="grid gap-2">
                    <Label for="manufacturer_part_number"
                        >Manufacturer part number</Label
                    >
                    <Input
                        id="manufacturer_part_number"
                        name="manufacturer_part_number"
                        :default-value="offering.manufacturer_part_number ?? ''"
                    />
                    <InputError :message="errors.manufacturer_part_number" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="product_url">Product URL</Label>
                <Input
                    id="product_url"
                    name="product_url"
                    type="url"
                    :default-value="offering.product_url ?? ''"
                />
                <InputError :message="errors.product_url" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="purchase_uom">Purchase UOM</Label>
                    <select
                        id="purchase_uom"
                        name="purchase_uom"
                        required
                        :class="fieldClass"
                    >
                        <option
                            v-for="unit in units"
                            :key="unit.value"
                            :value="unit.value"
                            :selected="unit.value === offering.purchase_uom"
                        >
                            {{ unit.label }}
                        </option>
                    </select>
                    <InputError :message="errors.purchase_uom" />
                </div>
                <div class="grid gap-2">
                    <Label for="package_quantity">Package quantity</Label>
                    <Input
                        id="package_quantity"
                        name="package_quantity"
                        required
                        :default-value="offering.package_quantity"
                    />
                    <InputError :message="errors.package_quantity" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="minimum_order_quantity"
                        >Minimum order quantity</Label
                    >
                    <Input
                        id="minimum_order_quantity"
                        name="minimum_order_quantity"
                        :default-value="offering.minimum_order_quantity ?? ''"
                    />
                    <InputError :message="errors.minimum_order_quantity" />
                </div>
                <div class="grid gap-2">
                    <Label for="lead_time_days">Lead time (days)</Label>
                    <Input
                        id="lead_time_days"
                        name="lead_time_days"
                        type="number"
                        min="0"
                        step="1"
                        :default-value="
                            offering.lead_time_days?.toString() ?? ''
                        "
                    />
                    <InputError :message="errors.lead_time_days" />
                </div>
            </div>

            <div class="flex gap-2">
                <Button type="submit" :disabled="processing">
                    Save changes
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="returnUrl">Cancel</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
