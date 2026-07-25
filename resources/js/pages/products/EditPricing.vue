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
    pricingPreview as orgPricingPreview,
    show as orgShow,
    updatePricing,
} from '@/routes/org/products';
import { index as legacyIndex, show as legacyShow } from '@/routes/products';
import type { Tenant } from '@/types';

const show = useTenantRoute(legacyShow, orgShow);

type SelectOption = { value: string; label: string };

type OrganizationProduct = {
    id: number;
    display_name: string;
    pricing_version: number;
    material_cost?: string;
    labor_cost?: string;
    overhead_mode?: string;
    overhead_amount?: string;
    overhead_rate_percent?: string;
    pricing_method?: string;
    markup_percent?: string;
    target_margin_percent?: string;
    fixed_price?: string | null;
    minimum_price?: string | null;
    allow_price_override?: boolean;
};

const props = defineProps<{
    product: OrganizationProduct;
    overheadModes: SelectOption[];
    pricingMethods: SelectOption[];
}>();

const slug = (usePage().props.tenant as Tenant | null | undefined)?.organization
    ?.slug;

const fieldClass =
    'border-input bg-transparent dark:bg-input/30 h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs outline-none';

const materialCost = ref(props.product.material_cost ?? '0');
const laborCost = ref(props.product.labor_cost ?? '0');
const overheadMode = ref(props.product.overhead_mode ?? 'none');
const overheadAmount = ref(props.product.overhead_amount ?? '');
const overheadRatePercent = ref(props.product.overhead_rate_percent ?? '');
const pricingMethod = ref(props.product.pricing_method ?? 'markup');
const markupPercent = ref(props.product.markup_percent ?? '');
const targetMarginPercent = ref(props.product.target_margin_percent ?? '');
const fixedPrice = ref(props.product.fixed_price ?? '');
const minimumPrice = ref(props.product.minimum_price ?? '');
const preview = ref<{
    unit_cost: string;
    unit_selling_price: string;
    below_minimum: boolean;
    warnings: string[];
} | null>(null);
const previewError = ref<string | null>(null);

function csrfToken(): string {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

async function refreshPreview(): Promise<void> {
    if (!slug) {
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
    void refreshPreview();
});

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Products', href: legacyIndex() },
            { title: 'Edit pricing', href: legacyIndex() },
        ],
    },
});
</script>

<template>
    <Head :title="`Edit pricing · ${product.display_name}`" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Edit organization pricing"
            :description="product.display_name"
        />

        <Form
            v-if="slug"
            v-bind="updatePricing.form([slug, product.id])"
            class="mx-auto grid w-full max-w-3xl gap-8"
            v-slot="{ errors, processing }"
        >
            <input
                type="hidden"
                name="pricing_version"
                :value="product.pricing_version"
            />

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
                        <Label for="markup_percent">Markup on cost (%)</Label>
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
                        <Label for="minimum_price">Minimum selling price</Label>
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
                            :checked="product.allow_price_override"
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
                    <p class="text-sm">Unit cost: ${{ preview.unit_cost }}</p>
                    <p class="text-sm">
                        Unit selling price: ${{ preview.unit_selling_price }}
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

            <div class="flex gap-3">
                <Button type="submit" :disabled="processing">
                    Save pricing
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="show(product.id)">Cancel</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
