<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { store as storeForProduct } from '@/routes/org/products/offerings';
import { store as storeForVendor } from '@/routes/org/vendors/offerings';
import type { Tenant } from '@/types';

type Option = { id: number; name: string; sku?: string };
type SelectOption = { value: string; label: string };

const props = defineProps<{
    context: 'product' | 'vendor';
    organizationProductId?: number;
    product?: { id: number; name: string; sku: string };
    vendor?: { id: number; name: string };
    vendors: Option[];
    products: Option[];
    units: SelectOption[];
    returnUrl: string;
}>();

const slug = (usePage().props.tenant as Tenant | null | undefined)?.organization
    ?.slug;

const storeForm = computed(() => {
    if (!slug) {
        return { action: props.returnUrl, method: 'post' as const };
    }

    if (props.context === 'product' && props.organizationProductId) {
        return storeForProduct.form([slug, props.organizationProductId]);
    }

    if (props.context === 'vendor' && props.vendor) {
        return storeForVendor.form([slug, props.vendor.id]);
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
            { title: 'Vendor offering', href: dashboard() },
        ],
    },
});
</script>

<template>
    <Head title="Add vendor offering" />

    <div class="mx-auto flex max-w-2xl flex-col gap-6 p-4">
        <Heading
            title="Add vendor offering"
            description="Describe what a vendor sells for a shared Product Master. Offerings are parent-account shared."
        />

        <div
            class="space-y-2 rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm text-sky-950 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-100"
        >
            <p>Vendor SKU does not replace the internal product SKU.</p>
            <p>Vendor offerings are shared across organizations.</p>
            <p>
                Organization-specific vendor pricing and preferred sources are
                configured on each organization’s product page.
            </p>
        </div>

        <Form
            v-bind="storeForm"
            class="space-y-4 rounded-xl border p-4"
            v-slot="{ errors, processing }"
        >
            <input
                v-if="context === 'product' && product"
                type="hidden"
                name="product_id"
                :value="product.id"
            />
            <input
                v-if="context === 'vendor' && vendor"
                type="hidden"
                name="vendor_id"
                :value="vendor.id"
            />

            <div
                v-if="context === 'product' && product"
                class="space-y-1 text-sm"
            >
                <p class="font-medium">Internal Product Master</p>
                <p>
                    {{ product.name }}
                    <span class="text-muted-foreground"
                        >({{ product.sku }})</span
                    >
                </p>
            </div>

            <div
                v-if="context === 'vendor' && vendor"
                class="space-y-1 text-sm"
            >
                <p class="font-medium">Vendor</p>
                <p>{{ vendor.name }}</p>
            </div>

            <div v-if="context === 'product'" class="grid gap-2">
                <Label for="vendor_id">Vendor</Label>
                <select
                    id="vendor_id"
                    name="vendor_id"
                    required
                    :class="fieldClass"
                >
                    <option value="">Select vendor</option>
                    <option
                        v-for="option in vendors"
                        :key="option.id"
                        :value="option.id"
                    >
                        {{ option.name }}
                    </option>
                </select>
                <InputError :message="errors.vendor_id" />
            </div>

            <div v-if="context === 'vendor'" class="grid gap-2">
                <Label for="product_id">Product Master</Label>
                <select
                    id="product_id"
                    name="product_id"
                    required
                    :class="fieldClass"
                >
                    <option value="">Select product</option>
                    <option
                        v-for="option in products"
                        :key="option.id"
                        :value="option.id"
                    >
                        {{ option.name }} ({{ option.sku }})
                    </option>
                </select>
                <InputError :message="errors.product_id" />
            </div>

            <div class="grid gap-2">
                <Label for="vendor_sku">Vendor SKU / item number</Label>
                <Input id="vendor_sku" name="vendor_sku" required />
                <p class="text-xs text-muted-foreground">
                    Identifies this supplier listing. Does not change the
                    internal Halftone Brain SKU.
                </p>
                <InputError :message="errors.vendor_sku" />
            </div>

            <div class="grid gap-2">
                <Label for="vendor_description">Vendor description</Label>
                <textarea
                    id="vendor_description"
                    name="vendor_description"
                    :class="textareaClass"
                />
                <InputError :message="errors.vendor_description" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="manufacturer">Manufacturer</Label>
                    <Input id="manufacturer" name="manufacturer" />
                    <InputError :message="errors.manufacturer" />
                </div>
                <div class="grid gap-2">
                    <Label for="manufacturer_part_number"
                        >Manufacturer part number</Label
                    >
                    <Input
                        id="manufacturer_part_number"
                        name="manufacturer_part_number"
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
                    placeholder="https://"
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
                        value="1"
                    />
                    <p class="text-xs text-muted-foreground">
                        Units per vendor package (max six decimal places).
                    </p>
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
                    />
                    <p class="text-xs text-muted-foreground">
                        Optional. Expressed in the offering purchase UOM.
                    </p>
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
                    />
                    <InputError :message="errors.lead_time_days" />
                </div>
            </div>

            <div class="flex gap-2">
                <Button type="submit" :disabled="processing">
                    Create offering
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="returnUrl">Cancel</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
