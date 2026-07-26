<script setup lang="ts">
import { Form, Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTenantRoute } from '@/composables/useTenantAction';
import { dashboard } from '@/routes';
import {
    show as orgShow,
    updatePurchaseCost,
    updateSettings,
} from '@/routes/org/products';
import conversions from '@/routes/org/products/conversions';
import { index as legacyIndex, show as legacyShow } from '@/routes/products';
import type { Tenant } from '@/types';

const show = useTenantRoute(legacyShow, orgShow);

type SelectOption = { value: string; label: string };

type UnitConversion = {
    id: number;
    from_unit: string;
    from_unit_label: string;
    to_unit: string;
    to_unit_label: string;
    numerator: number;
    denominator: number;
    is_active: boolean;
    preview: string;
    derived_reciprocal: string | null;
};

type OrganizationProduct = {
    id: number;
    display_name: string;
    is_available: boolean;
    is_sellable: boolean;
    is_purchasable: boolean;
    inventory_tracking_mode: string;
    inventory_tracking_mode_label: string;
    purchase_unit_of_measure: string | null;
    stock_unit_of_measure: string | null;
    usage_unit_of_measure: string | null;
    lead_time_days: number | null;
    organization_notes: string | null;
    unit_setup_incomplete: boolean;
    unit_setup_warning: string | null;
    unit_conversions: UnitConversion[];
    purchase_cost?: string | null;
};

const props = defineProps<{
    product: OrganizationProduct;
    units: SelectOption[];
    inventoryModes: SelectOption[];
    itemKind: string;
    canManageConversions: boolean;
    canUpdatePurchaseCost: boolean;
    hasPreferredSource?: boolean;
}>();

const slug = (usePage().props.tenant as Tenant | null | undefined)?.organization
    ?.slug;

const fieldClass =
    'border-input bg-transparent dark:bg-input/30 h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs outline-none';
const textareaClass =
    'border-input bg-transparent dark:bg-input/30 min-h-24 w-full rounded-md border px-3 py-2 text-sm shadow-xs outline-none';

const isSellable = ref(props.product.is_sellable);
const isPurchasable = ref(props.product.is_purchasable);
const isAvailable = ref(props.product.is_available);
const inventoryTrackingMode = ref(props.product.inventory_tracking_mode);
const purchaseUnit = ref(props.product.purchase_unit_of_measure ?? '');
const stockUnit = ref(props.product.stock_unit_of_measure ?? '');
const usageUnit = ref(props.product.usage_unit_of_measure ?? '');

const editingConversionId = ref<number | null>(null);
const conversionFromUnit = ref('');
const conversionToUnit = ref('');
const conversionNumerator = ref('1');
const conversionDenominator = ref('1');
const conversionPreview = ref<{
    preview: string;
    derived_reciprocal: string | null;
} | null>(null);
const conversionPreviewError = ref<string | null>(null);

const conversionFormTitle = computed(() =>
    editingConversionId.value === null
        ? 'Add unit conversion'
        : 'Edit unit conversion',
);

const purchaseUnitLabel = computed(() => {
    const value = props.product.purchase_unit_of_measure;

    if (!value) {
        return null;
    }

    return (
        props.units.find((unit) => unit.value === value)?.label ??
        value.replaceAll('_', ' ')
    );
});

const showPurchaseCostSection = computed(
    () => props.product.is_purchasable && props.canUpdatePurchaseCost,
);

watch(isPurchasable, (purchasable) => {
    if (!purchasable) {
        inventoryTrackingMode.value = 'none';
    }
});

function resetConversionForm(): void {
    editingConversionId.value = null;
    conversionFromUnit.value = '';
    conversionToUnit.value = '';
    conversionNumerator.value = '1';
    conversionDenominator.value = '1';
    conversionPreview.value = null;
    conversionPreviewError.value = null;
}

function startEditConversion(conversion: UnitConversion): void {
    editingConversionId.value = conversion.id;
    conversionFromUnit.value = conversion.from_unit;
    conversionToUnit.value = conversion.to_unit;
    conversionNumerator.value = String(conversion.numerator);
    conversionDenominator.value = String(conversion.denominator);
    void refreshConversionPreview();
}

function csrfToken(): string {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

async function refreshConversionPreview(): Promise<void> {
    if (
        !slug ||
        !conversionFromUnit.value ||
        !conversionToUnit.value ||
        conversionFromUnit.value === conversionToUnit.value
    ) {
        conversionPreview.value = null;
        conversionPreviewError.value = null;

        return;
    }

    conversionPreviewError.value = null;

    try {
        const response = await fetch(
            conversions.preview.url([slug, props.product.id]),
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    from_unit: conversionFromUnit.value,
                    to_unit: conversionToUnit.value,
                    numerator: Number(conversionNumerator.value),
                    denominator: Number(conversionDenominator.value),
                }),
            },
        );

        const payload = (await response.json()) as {
            preview?: string;
            derived_reciprocal?: string | null;
            message?: string;
            errors?: Record<string, string[]>;
        };

        if (!response.ok) {
            conversionPreview.value = null;
            conversionPreviewError.value =
                payload.errors?.from_unit?.[0] ??
                payload.errors?.to_unit?.[0] ??
                payload.errors?.numerator?.[0] ??
                payload.errors?.denominator?.[0] ??
                payload.message ??
                'Unable to preview conversion.';

            return;
        }

        conversionPreview.value = {
            preview: payload.preview ?? '',
            derived_reciprocal: payload.derived_reciprocal ?? null,
        };
    } catch {
        conversionPreview.value = null;
        conversionPreviewError.value = 'Unable to preview conversion.';
    }
}

watch(
    [
        conversionFromUnit,
        conversionToUnit,
        conversionNumerator,
        conversionDenominator,
    ],
    () => {
        void refreshConversionPreview();
    },
);

function toggleConversionActive(conversion: UnitConversion): void {
    if (!slug) {
        return;
    }

    const route = conversion.is_active
        ? conversions.deactivate
        : conversions.reactivate;

    router.post(route.url([slug, props.product.id, conversion.id]));
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Products', href: legacyIndex() },
            { title: 'Edit settings', href: legacyIndex() },
        ],
    },
});
</script>

<template>
    <Head :title="`Edit settings · ${product.display_name}`" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Edit organization settings"
            :description="product.display_name"
        />

        <Form
            v-if="slug"
            v-bind="updateSettings.form([slug, product.id])"
            class="mx-auto grid w-full max-w-3xl gap-8"
            v-slot="{ errors, processing }"
        >
            <section class="grid gap-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="display_name">Display name override</Label>
                        <Input
                            id="display_name"
                            name="display_name"
                            :default-value="product.display_name"
                        />
                        <InputError :message="errors.display_name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="lead_time_days">Lead time (days)</Label>
                        <Input
                            id="lead_time_days"
                            name="lead_time_days"
                            type="number"
                            min="0"
                            :default-value="product.lead_time_days ?? ''"
                        />
                        <InputError :message="errors.lead_time_days" />
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="notes">Organization notes</Label>
                        <textarea
                            id="notes"
                            name="notes"
                            :class="textareaClass"
                            :value="product.organization_notes ?? ''"
                        />
                        <InputError :message="errors.notes" />
                    </div>
                    <input
                        type="hidden"
                        name="is_available"
                        :value="isAvailable ? '1' : '0'"
                    />
                    <label class="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            value="1"
                            v-model="isAvailable"
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
                        <p class="text-sm text-muted-foreground">
                            Periodic external tracks inventory outside Halftone
                            Brain with no automatic deduction from usage.
                        </p>
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
                            <option value="">Not set</option>
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
                            <option value="">Not set</option>
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
                            <option value="">Not set</option>
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

                <p
                    v-if="product.unit_setup_warning"
                    class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100"
                >
                    {{ product.unit_setup_warning }}
                </p>
            </section>

            <div class="flex gap-3">
                <Button type="submit" :disabled="processing">
                    Save settings
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="show(product.id)">Cancel</Link>
                </Button>
            </div>
        </Form>

        <section
            v-if="
                product.is_purchasable &&
                hasPreferredSource &&
                !canUpdatePurchaseCost
            "
            class="mx-auto grid w-full max-w-3xl gap-2 rounded-xl border border-dashed p-4"
        >
            <h2 class="text-lg font-semibold">Purchase cost</h2>
            <p class="text-sm text-muted-foreground">
                A preferred vendor source is selected. Update the preferred
                source package price or clear the preferred source on the
                product page before editing purchase cost directly.
            </p>
        </section>

        <section
            v-if="showPurchaseCostSection && slug"
            class="mx-auto grid w-full max-w-3xl gap-4 rounded-xl border border-dashed p-4"
        >
            <div class="space-y-1">
                <h2 class="text-lg font-semibold">Purchase cost</h2>
                <p class="text-sm text-muted-foreground">
                    Separate from selling price settings. Used when this item is
                    purchased as a material.
                </p>
            </div>

            <p
                v-if="!product.purchase_unit_of_measure"
                class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100"
            >
                Purchase unit of measure is required when setting purchase cost.
                Set the purchase unit above, save settings, then return here.
            </p>

            <Form
                v-bind="updatePurchaseCost.form([slug, product.id])"
                class="grid gap-4"
                v-slot="{
                    errors: purchaseCostErrors,
                    processing: savingPurchaseCost,
                }"
            >
                <div class="grid gap-2">
                    <Label for="purchase_cost">
                        Purchase cost per
                        {{ purchaseUnitLabel ?? 'purchase unit' }}
                    </Label>
                    <Input
                        id="purchase_cost"
                        name="purchase_cost"
                        :default-value="product.purchase_cost ?? ''"
                        placeholder="Leave blank to clear"
                    />
                    <p class="text-sm text-muted-foreground">
                        Leave blank to clear the purchase cost.
                    </p>
                    <InputError :message="purchaseCostErrors.purchase_cost" />
                </div>
                <Button type="submit" :disabled="savingPurchaseCost">
                    Save purchase cost
                </Button>
            </Form>
        </section>

        <section
            v-if="canManageConversions && slug"
            class="mx-auto grid w-full max-w-3xl gap-4"
        >
            <h2 class="text-lg font-semibold">Unit conversions</h2>

            <div
                v-if="product.unit_conversions.length === 0"
                class="rounded-lg border p-4 text-sm text-muted-foreground"
            >
                No unit conversions yet.
            </div>

            <div
                v-for="conversion in product.unit_conversions"
                :key="conversion.id"
                class="flex flex-col gap-3 rounded-lg border p-4 sm:flex-row sm:items-center sm:justify-between"
                :class="{ 'opacity-60': !conversion.is_active }"
            >
                <div class="space-y-1 text-sm">
                    <p class="font-medium">{{ conversion.preview }}</p>
                    <p
                        v-if="conversion.derived_reciprocal"
                        class="text-muted-foreground"
                    >
                        {{ conversion.derived_reciprocal }}
                    </p>
                    <p
                        v-if="!conversion.is_active"
                        class="text-muted-foreground"
                    >
                        Inactive
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="startEditConversion(conversion)"
                    >
                        Edit
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="toggleConversionActive(conversion)"
                    >
                        {{ conversion.is_active ? 'Deactivate' : 'Reactivate' }}
                    </Button>
                </div>
            </div>

            <Form
                v-bind="
                    editingConversionId === null
                        ? conversions.store.form([slug, product.id])
                        : conversions.update.form([
                              slug,
                              product.id,
                              editingConversionId,
                          ])
                "
                class="grid gap-4 rounded-lg border p-4"
                v-slot="{
                    errors: conversionErrors,
                    processing: savingConversion,
                }"
            >
                <h3 class="font-medium">{{ conversionFormTitle }}</h3>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="from_unit">From unit</Label>
                        <select
                            id="from_unit"
                            name="from_unit"
                            :class="fieldClass"
                            v-model="conversionFromUnit"
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
                        <InputError :message="conversionErrors.from_unit" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="to_unit">To unit</Label>
                        <select
                            id="to_unit"
                            name="to_unit"
                            :class="fieldClass"
                            v-model="conversionToUnit"
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
                        <InputError :message="conversionErrors.to_unit" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="numerator">Numerator</Label>
                        <Input
                            id="numerator"
                            name="numerator"
                            type="number"
                            min="1"
                            v-model="conversionNumerator"
                            required
                        />
                        <InputError :message="conversionErrors.numerator" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="denominator">Denominator</Label>
                        <Input
                            id="denominator"
                            name="denominator"
                            type="number"
                            min="1"
                            v-model="conversionDenominator"
                            required
                        />
                        <InputError :message="conversionErrors.denominator" />
                    </div>
                </div>

                <div
                    v-if="conversionPreview || conversionPreviewError"
                    class="rounded-lg bg-muted/40 p-3 text-sm"
                >
                    <p v-if="conversionPreviewError" class="text-destructive">
                        {{ conversionPreviewError }}
                    </p>
                    <template v-else-if="conversionPreview">
                        <p>{{ conversionPreview.preview }}</p>
                        <p
                            v-if="conversionPreview.derived_reciprocal"
                            class="text-muted-foreground"
                        >
                            {{ conversionPreview.derived_reciprocal }}
                        </p>
                    </template>
                </div>

                <div class="flex flex-wrap gap-2">
                    <Button type="submit" :disabled="savingConversion">
                        {{
                            editingConversionId === null
                                ? 'Add conversion'
                                : 'Save conversion'
                        }}
                    </Button>
                    <Button
                        v-if="editingConversionId !== null"
                        type="button"
                        variant="outline"
                        @click="resetConversionForm"
                    >
                        Cancel edit
                    </Button>
                </div>
            </Form>
        </section>
    </div>
</template>
