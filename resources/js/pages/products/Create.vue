<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { onMounted, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTenantRoute } from '@/composables/useTenantAction';
import { dashboard } from '@/routes';
import {
    index as orgIndex,
    pricingPreview as orgPricingPreview,
    store as orgStore,
} from '@/routes/org/products';
import {
    create as legacyCreate,
    index as legacyIndex,
    store as legacyStore,
} from '@/routes/products';
import type { Tenant } from '@/types';

const index = useTenantRoute(legacyIndex, orgIndex);
const store = useTenantRoute(legacyStore, orgStore);

type Option = { id: number; name: string };
type SelectOption = { value: string; label: string };

defineProps<{
    vendors: Option[];
    categories: Option[];
    units: SelectOption[];
    families: SelectOption[];
    itemKinds: SelectOption[];
    inventoryModes: SelectOption[];
    overheadModes: SelectOption[];
    pricingMethods: SelectOption[];
}>();

const fieldClass =
    'border-input bg-transparent dark:bg-input/30 h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs outline-none';
const textareaClass =
    'border-input bg-transparent dark:bg-input/30 min-h-24 w-full rounded-md border px-3 py-2 text-sm shadow-xs outline-none';

const kindDefaults: Record<
    string,
    {
        is_sellable: boolean;
        is_purchasable: boolean;
        inventory_tracking_mode: string;
    }
> = {
    product: {
        is_sellable: true,
        is_purchasable: false,
        inventory_tracking_mode: 'none',
    },
    material: {
        is_sellable: false,
        is_purchasable: true,
        inventory_tracking_mode: 'periodic_external',
    },
    service: {
        is_sellable: true,
        is_purchasable: false,
        inventory_tracking_mode: 'none',
    },
};

const itemKind = ref('product');
const isSellable = ref(true);
const isPurchasable = ref(false);
const inventoryTrackingMode = ref('none');
const purchaseUnit = ref('');
const stockUnit = ref('');
const usageUnit = ref('');

const materialCost = ref('40');
const laborCost = ref('30');
const overheadMode = ref('fixed');
const overheadAmount = ref('10');
const overheadRatePercent = ref('0');
const pricingMethod = ref('markup');
const markupPercent = ref('50');
const targetMarginPercent = ref('0');
const fixedPrice = ref('');
const minimumPrice = ref('');
const preview = ref<{
    unit_cost: string;
    unit_selling_price: string;
    below_minimum: boolean;
    warnings: string[];
} | null>(null);
const previewError = ref<string | null>(null);

const slug = (usePage().props.tenant as Tenant | null | undefined)?.organization
    ?.slug;

function applyKindDefaults(kind: string): void {
    const defaults = kindDefaults[kind] ?? kindDefaults.product;
    isSellable.value = defaults.is_sellable;
    isPurchasable.value = defaults.is_purchasable;
    inventoryTrackingMode.value = defaults.inventory_tracking_mode;
}

watch(itemKind, (kind) => {
    applyKindDefaults(kind);
});

watch(isPurchasable, (purchasable) => {
    if (!purchasable) {
        inventoryTrackingMode.value = 'none';
    }
});

function csrfToken(): string {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

async function refreshPreview(): Promise<void> {
    if (!slug || !isSellable.value) {
        preview.value = null;
        previewError.value = null;

        return;
    }

    previewError.value = null;

    try {
        const response = await fetch(orgPricingPreview.url(slug), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                material_cost: materialCost.value,
                labor_cost: laborCost.value,
                overhead_mode: overheadMode.value,
                overhead_amount: overheadAmount.value || null,
                overhead_rate_percent: overheadRatePercent.value || null,
                pricing_method: pricingMethod.value,
                markup_percent: markupPercent.value || null,
                target_margin_percent: targetMarginPercent.value || null,
                fixed_price: fixedPrice.value || null,
                minimum_price: minimumPrice.value || null,
                quantity: '1',
            }),
        });

        const payload = (await response.json()) as {
            unit_cost?: string;
            unit_selling_price?: string;
            below_minimum?: boolean;
            warnings?: string[];
            message?: string;
            errors?: Record<string, string[]>;
        };

        if (!response.ok) {
            preview.value = null;
            previewError.value =
                payload.errors?.pricing?.[0] ??
                payload.message ??
                'Unable to preview pricing.';

            return;
        }

        preview.value = {
            unit_cost: payload.unit_cost ?? '0.0000',
            unit_selling_price: payload.unit_selling_price ?? '0.00',
            below_minimum: Boolean(payload.below_minimum),
            warnings: payload.warnings ?? [],
        };
    } catch {
        preview.value = null;
        previewError.value = 'Unable to preview pricing.';
    }
}

watch(
    [
        isSellable,
        materialCost,
        laborCost,
        overheadMode,
        overheadAmount,
        overheadRatePercent,
        pricingMethod,
        markupPercent,
        targetMarginPercent,
        fixedPrice,
        minimumPrice,
    ],
    () => {
        void refreshPreview();
    },
);

onMounted(() => {
    applyKindDefaults(itemKind.value);
    void refreshPreview();
});

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Products', href: legacyIndex() },
            { title: 'New', href: legacyCreate() },
        ],
    },
});
</script>

<template>
    <Head title="New organization product" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="New product for this organization"
            description="Creates a shared product master and organization pricing in one step"
        />

        <Form
            v-bind="store.form()"
            class="mx-auto grid w-full max-w-3xl gap-8"
            v-slot="{ errors, processing }"
        >
            <section class="grid gap-4">
                <h2 class="text-lg font-semibold">
                    Shared product information
                </h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="name">Name</Label>
                        <Input id="name" name="name" required />
                        <InputError :message="errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="sku">SKU</Label>
                        <Input id="sku" name="sku" required />
                        <InputError :message="errors.sku" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="item_kind">Item kind</Label>
                        <select
                            id="item_kind"
                            name="item_kind"
                            :class="fieldClass"
                            v-model="itemKind"
                            required
                        >
                            <option
                                v-for="kind in itemKinds"
                                :key="kind.value"
                                :value="kind.value"
                            >
                                {{ kind.label }}
                            </option>
                        </select>
                        <InputError :message="errors.item_kind" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="product_family">Product family</Label>
                        <select
                            id="product_family"
                            name="product_family"
                            :class="fieldClass"
                            required
                        >
                            <option
                                v-for="family in families"
                                :key="family.value"
                                :value="family.value"
                            >
                                {{ family.label }}
                            </option>
                        </select>
                        <InputError :message="errors.product_family" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="unit_of_measure">Unit of measure</Label>
                        <select
                            id="unit_of_measure"
                            name="unit_of_measure"
                            :class="fieldClass"
                            required
                        >
                            <option
                                v-for="unit in units"
                                :key="unit.value"
                                :value="unit.value"
                            >
                                {{ unit.label }}
                            </option>
                        </select>
                        <InputError :message="errors.unit_of_measure" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="vendor_id">Vendor</Label>
                        <select
                            id="vendor_id"
                            name="vendor_id"
                            :class="fieldClass"
                        >
                            <option value="">None</option>
                            <option
                                v-for="vendor in vendors"
                                :key="vendor.id"
                                :value="vendor.id"
                            >
                                {{ vendor.name }}
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="product_category_id">Category</Label>
                        <select
                            id="product_category_id"
                            name="product_category_id"
                            :class="fieldClass"
                        >
                            <option value="">None</option>
                            <option
                                v-for="category in categories"
                                :key="category.id"
                                :value="category.id"
                            >
                                {{ category.name }}
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="vendor_sku">Vendor SKU</Label>
                        <Input id="vendor_sku" name="vendor_sku" />
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="description">Description</Label>
                        <textarea
                            id="description"
                            name="description"
                            :class="textareaClass"
                        />
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="notes">Shared notes</Label>
                        <textarea
                            id="notes"
                            name="notes"
                            :class="textareaClass"
                        />
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            name="is_active"
                            value="1"
                            checked
                        />
                        Master is active
                    </label>
                </div>
            </section>

            <section class="grid gap-4">
                <h2 class="text-lg font-semibold">
                    Organization availability / settings
                </h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="display_name">Display name override</Label>
                        <Input id="display_name" name="display_name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="lead_time_days">Lead time (days)</Label>
                        <Input
                            id="lead_time_days"
                            name="lead_time_days"
                            type="number"
                            min="0"
                        />
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="organization_notes"
                            >Organization notes</Label
                        >
                        <textarea
                            id="organization_notes"
                            name="organization_notes"
                            :class="textareaClass"
                        />
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            name="is_available"
                            value="1"
                            checked
                        />
                        Available in this organization
                    </label>
                </div>
            </section>

            <section class="grid gap-4">
                <h2 class="text-lg font-semibold">Classification</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <input
                        type="hidden"
                        name="is_sellable"
                        :value="isSellable ? '1' : '0'"
                    />
                    <input
                        type="hidden"
                        name="is_purchasable"
                        :value="isPurchasable ? '1' : '0'"
                    />
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" value="1" v-model="isSellable" />
                        Sellable in this organization
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            value="1"
                            v-model="isPurchasable"
                        />
                        Purchasable in this organization
                    </label>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="inventory_tracking_mode"
                            >Inventory tracking</Label
                        >
                        <select
                            id="inventory_tracking_mode"
                            name="inventory_tracking_mode"
                            :class="fieldClass"
                            v-model="inventoryTrackingMode"
                            :disabled="!isPurchasable || itemKind === 'service'"
                        >
                            <option
                                v-for="mode in inventoryModes"
                                :key="mode.value"
                                :value="mode.value"
                            >
                                {{ mode.label }}
                            </option>
                        </select>
                        <InputError :message="errors.inventory_tracking_mode" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="purchase_unit_of_measure"
                            >Purchase unit</Label
                        >
                        <select
                            id="purchase_unit_of_measure"
                            name="purchase_unit_of_measure"
                            :class="fieldClass"
                            v-model="purchaseUnit"
                        >
                            <option value="">Same as master</option>
                            <option
                                v-for="unit in units"
                                :key="unit.value"
                                :value="unit.value"
                            >
                                {{ unit.label }}
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="stock_unit_of_measure">Stock unit</Label>
                        <select
                            id="stock_unit_of_measure"
                            name="stock_unit_of_measure"
                            :class="fieldClass"
                            v-model="stockUnit"
                        >
                            <option value="">Same as master</option>
                            <option
                                v-for="unit in units"
                                :key="unit.value"
                                :value="unit.value"
                            >
                                {{ unit.label }}
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="usage_unit_of_measure">Usage unit</Label>
                        <select
                            id="usage_unit_of_measure"
                            name="usage_unit_of_measure"
                            :class="fieldClass"
                            v-model="usageUnit"
                        >
                            <option value="">Same as master</option>
                            <option
                                v-for="unit in units"
                                :key="unit.value"
                                :value="unit.value"
                            >
                                {{ unit.label }}
                            </option>
                        </select>
                    </div>
                </div>
            </section>

            <template v-if="isSellable">
                <section class="grid gap-4">
                    <h2 class="text-lg font-semibold">Cost breakdown</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="material_cost">Material cost</Label>
                            <Input
                                id="material_cost"
                                name="material_cost"
                                v-model="materialCost"
                                required
                            />
                            <InputError :message="errors.material_cost" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="labor_cost">Labor cost</Label>
                            <Input
                                id="labor_cost"
                                name="labor_cost"
                                v-model="laborCost"
                                required
                            />
                            <InputError :message="errors.labor_cost" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="overhead_mode">Overhead</Label>
                            <select
                                id="overhead_mode"
                                name="overhead_mode"
                                :class="fieldClass"
                                v-model="overheadMode"
                                required
                            >
                                <option
                                    v-for="mode in overheadModes"
                                    :key="mode.value"
                                    :value="mode.value"
                                >
                                    {{ mode.label }}
                                </option>
                            </select>
                        </div>
                        <div class="grid gap-2">
                            <Label for="overhead_amount">Overhead amount</Label>
                            <Input
                                id="overhead_amount"
                                name="overhead_amount"
                                v-model="overheadAmount"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="overhead_rate_percent"
                                >Overhead rate percent</Label
                            >
                            <Input
                                id="overhead_rate_percent"
                                name="overhead_rate_percent"
                                v-model="overheadRatePercent"
                            />
                        </div>
                    </div>
                </section>

                <section class="grid gap-4">
                    <h2 class="text-lg font-semibold">Pricing method</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="pricing_method">Method</Label>
                            <select
                                id="pricing_method"
                                name="pricing_method"
                                :class="fieldClass"
                                v-model="pricingMethod"
                                required
                            >
                                <option
                                    v-for="method in pricingMethods"
                                    :key="method.value"
                                    :value="method.value"
                                >
                                    {{ method.label }}
                                </option>
                            </select>
                        </div>
                        <div class="grid gap-2">
                            <Label for="markup_percent"
                                >Markup on cost (%)</Label
                            >
                            <Input
                                id="markup_percent"
                                name="markup_percent"
                                v-model="markupPercent"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="target_margin_percent"
                                >Target margin on selling price (%)</Label
                            >
                            <Input
                                id="target_margin_percent"
                                name="target_margin_percent"
                                v-model="targetMarginPercent"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="fixed_price">Fixed selling price</Label>
                            <Input
                                id="fixed_price"
                                name="fixed_price"
                                v-model="fixedPrice"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="minimum_price"
                                >Minimum selling price</Label
                            >
                            <Input
                                id="minimum_price"
                                name="minimum_price"
                                v-model="minimumPrice"
                            />
                        </div>
                        <label
                            class="flex items-center gap-2 text-sm sm:col-span-2"
                        >
                            <input
                                type="checkbox"
                                name="allow_price_override"
                                value="1"
                            />
                            Allow price override on quotes
                        </label>
                    </div>
                </section>

                <section class="grid gap-2 rounded-lg border bg-muted/40 p-4">
                    <h2 class="font-semibold">Calculated pricing preview</h2>
                    <p v-if="previewError" class="text-sm text-destructive">
                        {{ previewError }}
                    </p>
                    <template v-else-if="preview">
                        <p class="text-sm">
                            Unit cost: ${{ preview.unit_cost }}
                        </p>
                        <p class="text-sm">
                            Unit selling price: ${{
                                preview.unit_selling_price
                            }}
                        </p>
                        <p
                            v-if="preview.below_minimum"
                            class="text-sm text-destructive"
                        >
                            Price is below minimum.
                        </p>
                        <p
                            v-for="warning in preview.warnings"
                            :key="warning"
                            class="text-sm text-amber-700"
                        >
                            {{ warning }}
                        </p>
                    </template>
                    <InputError :message="errors.pricing" />
                    <InputError :message="errors.minimum_price" />
                </section>
            </template>

            <div class="flex gap-3">
                <Button type="submit" :disabled="processing"
                    >Save product</Button
                >
                <Button variant="outline" as-child>
                    <Link :href="index()">Cancel</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
