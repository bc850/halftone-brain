<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
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
import components from '@/routes/org/products/components';
import { index as legacyIndex, show as legacyShow } from '@/routes/products';
import type { Tenant } from '@/types';

const show = useTenantRoute(legacyShow, orgShow);

type SelectOption = { value: string; label: string };

type ProductComponent = {
    id: number;
    component_organization_product_id: number;
    quantity: string;
    usage_uom: string;
    usage_uom_label: string;
    waste_percent: string;
    waste_basis_points: number;
    sort_order: number;
    waste_adjusted_quantity?: string;
    converted_purchase_quantity?: string;
    purchase_unit_of_measure_label?: string;
    estimated_component_cost?: string;
    estimate_error?: string;
    is_active: boolean;
    material: {
        id: number;
        display_name: string | null;
        sku: string | null;
        purchase_cost?: string | null;
        purchase_unit_of_measure_label?: string | null;
    } | null;
};

type ComponentCandidate = {
    id: number;
    display_name: string;
    sku: string | null;
    purchase_unit_of_measure: string | null;
    purchase_unit_of_measure_label: string | null;
    eligible: boolean;
    disabled_reason: string | null;
};

type OrganizationProduct = {
    id: number;
    display_name: string;
    pricing_version: number;
    components_version: number;
    material_cost_source: 'manual' | 'components';
    estimate_stale: boolean;
    material_cost?: string;
    estimated_material_cost?: string | null;
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
    components: ProductComponent[];
};

const props = defineProps<{
    product: OrganizationProduct;
    canManageComponents: boolean;
    canViewCost: boolean;
    componentCandidates: ComponentCandidate[];
    units: SelectOption[];
    overheadModes: SelectOption[];
    pricingMethods: SelectOption[];
}>();

const slug = (usePage().props.tenant as Tenant | null | undefined)?.organization
    ?.slug;

const fieldClass =
    'border-input bg-transparent dark:bg-input/30 h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs outline-none';

const materialIsFromComponents = computed(
    () => props.product.material_cost_source === 'components',
);

const materialCost = ref(
    props.product.estimated_material_cost ?? props.product.material_cost ?? '0',
);
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
    material_cost: string;
    material_source: string;
    below_minimum: boolean;
    warnings: string[];
} | null>(null);
const previewError = ref<string | null>(null);

const editingComponentId = ref<number | null>(null);
const newComponentId = ref('');
const newQuantity = ref('1');
const newUsageUom = ref('');
const newWastePercent = ref('0');
const editQuantity = ref('');
const editUsageUom = ref('');
const editWastePercent = ref('0');

const displayedMaterialCost = computed(() => {
    if (preview.value?.material_cost) {
        return preview.value.material_cost;
    }

    return (
        props.product.estimated_material_cost ??
        props.product.material_cost ??
        materialCost.value
    );
});

const newWasteBasisPoints = computed(() =>
    percentToBasisPoints(newWastePercent.value),
);

const editWasteBasisPoints = computed(() =>
    percentToBasisPoints(editWastePercent.value),
);

function percentToBasisPoints(percent: string): number {
    const value = Number(percent);

    if (!Number.isFinite(value) || value < 0) {
        return 0;
    }

    return Math.min(10000, Math.round(value * 100));
}

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

    const submittedMaterialCost = materialIsFromComponents.value
        ? (props.product.estimated_material_cost ??
          props.product.material_cost ??
          materialCost.value)
        : materialCost.value;

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
                organization_product_id: props.product.id,
                pricing_version: props.product.pricing_version,
                components_version: props.product.components_version,
                material_cost: submittedMaterialCost,
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
            material_cost?: string;
            material_source?: string;
            below_minimum?: boolean;
            warnings?: string[];
            message?: string;
            errors?: Record<string, string[]>;
        };

        if (!response.ok) {
            preview.value = null;
            previewError.value =
                payload.errors?.pricing?.[0] ??
                payload.errors?.components_version?.[0] ??
                payload.errors?.pricing_version?.[0] ??
                payload.message ??
                'Unable to preview pricing.';

            return;
        }

        preview.value = {
            unit_cost: payload.unit_cost ?? '0.0000',
            unit_selling_price: payload.unit_selling_price ?? '0.00',
            material_cost: payload.material_cost ?? submittedMaterialCost,
            material_source:
                payload.material_source ?? props.product.material_cost_source,
            below_minimum: Boolean(payload.below_minimum),
            warnings: payload.warnings ?? [],
        };

        if (materialIsFromComponents.value && payload.material_cost) {
            materialCost.value = payload.material_cost;
        }
    } catch {
        preview.value = null;
        previewError.value = 'Unable to preview pricing.';
    }
}

function startEditComponent(component: ProductComponent): void {
    editingComponentId.value = component.id;
    editQuantity.value = component.quantity;
    editUsageUom.value = component.usage_uom;
    editWastePercent.value = component.waste_percent;
}

function resetEditComponent(): void {
    editingComponentId.value = null;
    editQuantity.value = '';
    editUsageUom.value = '';
    editWastePercent.value = '0';
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

watch(
    () => [
        props.product.estimated_material_cost,
        props.product.material_cost,
        props.product.material_cost_source,
        props.product.components_version,
        props.product.pricing_version,
    ],
    () => {
        if (materialIsFromComponents.value) {
            materialCost.value =
                props.product.estimated_material_cost ??
                props.product.material_cost ??
                materialCost.value;
        }

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

        <p
            v-if="product.estimate_stale"
            class="mx-auto w-full max-w-3xl rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100"
        >
            Material estimate changed. Review and save pricing.
        </p>

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
            <input
                type="hidden"
                name="components_version"
                :value="product.components_version"
            />

            <section class="grid gap-4">
                <h2 class="text-lg font-semibold">Cost breakdown</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="material_cost">Material cost</Label>
                        <Input
                            v-if="!materialIsFromComponents"
                            id="material_cost"
                            name="material_cost"
                            v-model="materialCost"
                            required
                        />
                        <template v-else>
                            <Input
                                id="material_cost_display"
                                :model-value="displayedMaterialCost"
                                readonly
                                disabled
                            />
                            <input
                                type="hidden"
                                name="material_cost"
                                :value="displayedMaterialCost"
                            />
                            <p class="text-sm text-muted-foreground">
                                Calculated from estimated materials. Manual
                                edits are disabled while active components are
                                configured.
                            </p>
                        </template>
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
                        <p
                            v-if="pricingMethod === 'fixed'"
                            class="text-sm text-muted-foreground"
                        >
                            Fixed selling price stays editable. Preview still
                            reflects estimated unit cost and margin
                            implications.
                        </p>
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
                    <p v-if="canViewCost" class="text-sm">
                        Material cost: ${{ preview.material_cost }}
                        <span class="text-muted-foreground">
                            ({{ preview.material_source }})
                        </span>
                    </p>
                    <p v-if="canViewCost" class="text-sm">
                        Unit cost: ${{ preview.unit_cost }}
                    </p>
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
                <InputError :message="errors.pricing_version" />
                <InputError :message="errors.components_version" />
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

        <section
            v-if="canManageComponents && slug"
            class="mx-auto grid w-full max-w-3xl gap-4"
        >
            <div class="space-y-1">
                <h2 class="text-lg font-semibold">Estimated materials</h2>
                <p class="text-sm text-muted-foreground">
                    Estimated usage for costing. This does not reduce inventory
                    or change QuickBooks quantities.
                </p>
            </div>

            <div
                v-if="product.components.length === 0"
                class="rounded-lg border p-4 text-sm text-muted-foreground"
            >
                No materials configured yet.
            </div>

            <div
                v-for="component in product.components"
                :key="component.id"
                class="grid gap-3 rounded-lg border p-4"
                :class="{ 'opacity-60': !component.is_active }"
            >
                <div
                    class="flex flex-wrap items-start justify-between gap-2 text-sm"
                >
                    <div class="space-y-1">
                        <p class="font-medium">
                            {{ component.material?.display_name ?? 'Material' }}
                            <span
                                v-if="component.material?.sku"
                                class="text-muted-foreground"
                            >
                                · {{ component.material.sku }}
                            </span>
                        </p>
                        <p>
                            Qty {{ component.quantity }}
                            {{ component.usage_uom_label }} · Waste
                            {{ component.waste_percent }}%
                        </p>
                        <p
                            v-if="component.waste_adjusted_quantity"
                            class="text-muted-foreground"
                        >
                            Waste-adjusted
                            {{ component.waste_adjusted_quantity }}
                            {{ component.usage_uom_label }}
                            <template
                                v-if="component.converted_purchase_quantity"
                            >
                                ·
                                {{ component.converted_purchase_quantity }}
                                {{ component.purchase_unit_of_measure_label }}
                            </template>
                        </p>
                        <p
                            v-if="
                                canViewCost &&
                                component.estimated_component_cost
                            "
                            class="text-muted-foreground"
                        >
                            Estimated cost ${{
                                component.estimated_component_cost
                            }}
                        </p>
                        <p
                            v-if="component.estimate_error"
                            class="text-destructive"
                        >
                            {{ component.estimate_error }}
                        </p>
                    </div>
                    <Badge
                        :variant="
                            component.is_active ? 'outline' : 'destructive'
                        "
                    >
                        {{ component.is_active ? 'Active' : 'Inactive' }}
                    </Badge>
                </div>

                <div
                    v-if="editingComponentId === component.id"
                    class="grid gap-4 border-t pt-4"
                >
                    <Form
                        v-bind="
                            components.update.form([
                                slug,
                                product.id,
                                component.id,
                            ])
                        "
                        class="grid gap-4"
                        v-slot="{
                            errors: componentErrors,
                            processing: savingComponent,
                        }"
                    >
                        <input
                            type="hidden"
                            name="components_version"
                            :value="product.components_version"
                        />
                        <input
                            type="hidden"
                            name="waste_basis_points"
                            :value="editWasteBasisPoints"
                        />
                        <div class="grid gap-4 sm:grid-cols-3">
                            <div class="grid gap-2">
                                <Label :for="`edit_quantity_${component.id}`"
                                    >Quantity</Label
                                >
                                <Input
                                    :id="`edit_quantity_${component.id}`"
                                    name="quantity"
                                    v-model="editQuantity"
                                    required
                                />
                                <InputError
                                    :message="componentErrors.quantity"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label :for="`edit_usage_uom_${component.id}`"
                                    >Usage unit</Label
                                >
                                <select
                                    :id="`edit_usage_uom_${component.id}`"
                                    name="usage_uom"
                                    :class="fieldClass"
                                    v-model="editUsageUom"
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
                                <InputError
                                    :message="componentErrors.usage_uom"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label
                                    :for="`edit_waste_percent_${component.id}`"
                                    >Waste %</Label
                                >
                                <Input
                                    :id="`edit_waste_percent_${component.id}`"
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                    v-model="editWastePercent"
                                />
                                <InputError
                                    :message="
                                        componentErrors.waste_basis_points
                                    "
                                />
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                type="submit"
                                size="sm"
                                :disabled="savingComponent"
                            >
                                Save component
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="resetEditComponent"
                            >
                                Cancel
                            </Button>
                        </div>
                    </Form>
                </div>

                <div v-else class="flex flex-wrap gap-2">
                    <Button
                        v-if="component.is_active"
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="startEditComponent(component)"
                    >
                        Edit
                    </Button>
                    <Form
                        v-if="component.is_active"
                        v-bind="
                            components.deactivate.form([
                                slug,
                                product.id,
                                component.id,
                            ])
                        "
                    >
                        <input
                            type="hidden"
                            name="components_version"
                            :value="product.components_version"
                        />
                        <Button type="submit" variant="outline" size="sm">
                            Deactivate
                        </Button>
                    </Form>
                    <Form
                        v-else
                        v-bind="
                            components.reactivate.form([
                                slug,
                                product.id,
                                component.id,
                            ])
                        "
                    >
                        <input
                            type="hidden"
                            name="components_version"
                            :value="product.components_version"
                        />
                        <Button type="submit" variant="outline" size="sm">
                            Reactivate
                        </Button>
                    </Form>
                </div>
            </div>

            <Form
                v-bind="components.store.form([slug, product.id])"
                class="grid gap-4 rounded-lg border p-4"
                v-slot="{ errors: addErrors, processing: addingComponent }"
            >
                <h3 class="font-medium">Add material</h3>
                <input
                    type="hidden"
                    name="components_version"
                    :value="product.components_version"
                />
                <input
                    type="hidden"
                    name="waste_basis_points"
                    :value="newWasteBasisPoints"
                />
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="component_organization_product_id"
                            >Material</Label
                        >
                        <select
                            id="component_organization_product_id"
                            name="component_organization_product_id"
                            :class="fieldClass"
                            v-model="newComponentId"
                            required
                        >
                            <option value="">Select material...</option>
                            <option
                                v-for="candidate in componentCandidates"
                                :key="candidate.id"
                                :value="candidate.id"
                                :disabled="!candidate.eligible"
                            >
                                {{ candidate.display_name
                                }}{{ candidate.sku ? ` · ${candidate.sku}` : ''
                                }}{{
                                    candidate.disabled_reason
                                        ? ` (${candidate.disabled_reason})`
                                        : ''
                                }}
                            </option>
                        </select>
                        <InputError
                            :message="
                                addErrors.component_organization_product_id
                            "
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="quantity">Quantity</Label>
                        <Input
                            id="quantity"
                            name="quantity"
                            v-model="newQuantity"
                            required
                        />
                        <InputError :message="addErrors.quantity" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="usage_uom">Usage unit</Label>
                        <select
                            id="usage_uom"
                            name="usage_uom"
                            :class="fieldClass"
                            v-model="newUsageUom"
                            required
                        >
                            <option value="">Select unit...</option>
                            <option
                                v-for="unit in units"
                                :key="unit.value"
                                :value="unit.value"
                            >
                                {{ unit.label }}
                            </option>
                        </select>
                        <InputError :message="addErrors.usage_uom" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="waste_percent">Waste %</Label>
                        <Input
                            id="waste_percent"
                            type="number"
                            min="0"
                            max="100"
                            step="0.01"
                            v-model="newWastePercent"
                        />
                        <InputError :message="addErrors.waste_basis_points" />
                    </div>
                </div>
                <Button type="submit" :disabled="addingComponent">
                    Add material
                </Button>
            </Form>
        </section>
    </div>
</template>
