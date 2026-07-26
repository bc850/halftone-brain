<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes';
import {
    archive,
    editMaster,
    editPricing,
    editSettings,
    show as showProduct,
} from '@/routes/org/products';
import {
    create as createOffering,
    show as showOffering,
} from '@/routes/org/products/offerings';
import {
    clearPreferred,
    create as createSource,
    prefer,
    show as showSource,
} from '@/routes/org/products/sources';
import { index as legacyIndex } from '@/routes/products';
import type { Tenant } from '@/types';

type UnitConversion = {
    id: number;
    from_unit_label: string;
    to_unit_label: string;
    preview: string;
    derived_reciprocal: string | null;
    is_active: boolean;
};

type ProductComponent = {
    id: number;
    quantity: string;
    usage_uom: string;
    usage_uom_label: string;
    waste_percent: string;
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
    pricing_method: string;
    pricing_version: number;
    components_version: number;
    preferred_source_id: number | null;
    material_cost_source: 'manual' | 'components';
    estimate_stale: boolean;
    unit_selling_price: string | null;
    unit_setup_incomplete: boolean;
    unit_setup_warning: string | null;
    unit_conversions: UnitConversion[];
    components: ProductComponent[];
    product: {
        id: number;
        name: string;
        sku: string;
        product_family: string;
        item_kind: string;
        item_kind_label: string;
        unit_of_measure: string;
        description: string | null;
        is_active: boolean;
        notes?: string | null;
        category?: { id: number; name: string } | null;
    } | null;
    purchase_cost?: string | null;
    material_cost?: string;
    estimated_material_cost?: string | null;
    labor_cost?: string;
    overhead_mode?: string;
    overhead_amount?: string;
    overhead_rate_percent?: string;
    markup_percent?: string;
    target_margin_percent?: string;
    fixed_price?: string | null;
    minimum_price?: string | null;
    allow_price_override?: boolean;
    unit_cost?: string | null;
    below_minimum?: boolean;
    pricing_warnings?: string[];
};

type VendorOffering = {
    id: number;
    vendor_sku: string;
    manufacturer: string | null;
    manufacturer_part_number: string | null;
    purchase_uom_label: string;
    package_quantity: string;
    minimum_order_quantity: string | null;
    lead_time_days: number | null;
    status: string;
    status_label: string;
    vendor: { id: number; name: string } | null;
};

type VendorSource = {
    id: number;
    price_version: number;
    is_active: boolean;
    is_preferred: boolean;
    current_package_price?: string | null;
    effective_purchase_unit_cost?: string | null;
    last_price_update_at?: string | null;
    offering: {
        vendor_sku: string;
        vendor_description: string | null;
        purchase_uom_label: string;
        package_quantity: string;
        vendor: { id: number; name: string } | null;
    } | null;
};

const props = defineProps<{
    product: OrganizationProduct;
    vendorOfferings: VendorOffering[];
    vendorSources: VendorSource[];
    offeringFilters: {
        offering_search: string;
        offering_status: string;
    };
    canUpdateMaster: boolean;
    canUpdateSettings: boolean;
    canManageConversions: boolean;
    canManageComponents: boolean;
    canManageOfferings: boolean;
    canManageSources: boolean;
    canManageSourcePricing: boolean;
    canClearPreferredSource: boolean;
    canUpdatePricing: boolean;
    canUpdatePurchaseCost: boolean;
    canArchive: boolean;
    canViewCost: boolean;
}>();

const slug = (usePage().props.tenant as Tenant | null | undefined)?.organization
    ?.slug;

const offeringSearch = ref(props.offeringFilters.offering_search ?? '');
const offeringStatus = ref(props.offeringFilters.offering_status ?? '');

function applyOfferingFilters(): void {
    if (!slug) {
        return;
    }

    router.get(
        showProduct.url([slug, props.product.id]),
        {
            offering_search: offeringSearch.value || undefined,
            offering_status: offeringStatus.value || undefined,
        },
        { preserveState: true, replace: true },
    );
}

const showsEstimatedMaterials = computed(() => {
    const kind = props.product.product?.item_kind;

    return (
        props.product.is_sellable && (kind === 'product' || kind === 'service')
    );
});

const canOpenPricing = computed(
    () => props.canManageComponents || props.canUpdatePricing,
);

function formatUnit(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return value.replaceAll('_', ' ');
}

function archiveProduct(): void {
    if (!slug) {
        return;
    }

    if (confirm('Archive this product for this organization?')) {
        router.post(archive.url([slug, props.product.id]));
    }
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Products', href: legacyIndex() },
        ],
    },
});
</script>

<template>
    <Head :title="product.display_name" />

    <div class="flex flex-col gap-6 p-4">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <div class="space-y-3">
                <Heading
                    :title="product.display_name"
                    :description="product.product?.sku"
                />
                <div class="flex flex-wrap gap-1">
                    <Badge variant="secondary">
                        {{ product.product?.item_kind_label }}
                    </Badge>
                    <Badge v-if="product.is_sellable" variant="outline">
                        Sellable
                    </Badge>
                    <Badge v-if="product.is_purchasable" variant="outline">
                        Purchasable
                    </Badge>
                    <Badge
                        v-if="
                            product.inventory_tracking_mode ===
                            'periodic_external'
                        "
                        variant="outline"
                    >
                        Periodic external
                    </Badge>
                    <Badge v-if="!product.is_available" variant="destructive">
                        Archived
                    </Badge>
                    <Badge
                        v-if="product.product && !product.product.is_active"
                        variant="destructive"
                    >
                        Master inactive
                    </Badge>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="canUpdateMaster && slug"
                    variant="outline"
                    as-child
                >
                    <Link :href="editMaster.url([slug, product.id])">
                        Edit master
                    </Link>
                </Button>
                <Button
                    v-if="canUpdateSettings && slug"
                    variant="outline"
                    as-child
                >
                    <Link :href="editSettings.url([slug, product.id])">
                        Edit settings
                    </Link>
                </Button>
                <Button
                    v-if="canUpdatePricing && product.is_sellable && slug"
                    variant="outline"
                    as-child
                >
                    <Link :href="editPricing.url([slug, product.id])">
                        Edit pricing
                    </Link>
                </Button>
            </div>
        </div>

        <p
            v-if="product.unit_setup_warning"
            class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100"
        >
            {{ product.unit_setup_warning }}
        </p>

        <p
            v-if="product.estimate_stale"
            class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100"
        >
            Material estimate changed. Review and save pricing.
        </p>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="space-y-2 rounded-xl border p-4 text-sm">
                <h2 class="font-medium">Shared product master</h2>
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground">Name</span>
                    <span>{{ product.product?.name ?? '—' }}</span>
                </div>
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground">SKU</span>
                    <span>{{ product.product?.sku ?? '—' }}</span>
                </div>
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground">Kind</span>
                    <span>{{ product.product?.item_kind_label ?? '—' }}</span>
                </div>
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground">Family</span>
                    <span class="capitalize">{{
                        product.product?.product_family ?? '—'
                    }}</span>
                </div>
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground">Master unit</span>
                    <span class="capitalize">{{
                        formatUnit(product.product?.unit_of_measure)
                    }}</span>
                </div>
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground">Master active</span>
                    <span>{{ product.product?.is_active ? 'Yes' : 'No' }}</span>
                </div>
                <div
                    v-if="product.product?.category"
                    class="flex justify-between gap-4"
                >
                    <span class="text-muted-foreground">Category</span>
                    <span>{{ product.product.category.name }}</span>
                </div>
                <p
                    v-if="product.product?.description"
                    class="pt-2 text-muted-foreground"
                >
                    {{ product.product.description }}
                </p>
            </section>

            <section class="space-y-2 rounded-xl border p-4 text-sm">
                <h2 class="font-medium">
                    Organization availability and purchasing
                </h2>
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground">Available</span>
                    <span>{{ product.is_available ? 'Yes' : 'No' }}</span>
                </div>
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground">Sellable</span>
                    <span>{{ product.is_sellable ? 'Yes' : 'No' }}</span>
                </div>
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground">Purchasable</span>
                    <span>{{ product.is_purchasable ? 'Yes' : 'No' }}</span>
                </div>
                <div
                    v-if="canViewCost && product.is_purchasable"
                    class="flex justify-between gap-4"
                >
                    <span class="text-muted-foreground">Purchase cost</span>
                    <span>{{
                        product.purchase_cost
                            ? `$${product.purchase_cost}`
                            : '—'
                    }}</span>
                </div>
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground"
                        >Inventory tracking</span
                    >
                    <span>{{ product.inventory_tracking_mode_label }}</span>
                </div>
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground">Lead time</span>
                    <span>{{ product.lead_time_days ?? '—' }}</span>
                </div>
                <p
                    v-if="product.organization_notes"
                    class="pt-2 text-muted-foreground"
                >
                    {{ product.organization_notes }}
                </p>
            </section>

            <section
                class="space-y-2 rounded-xl border p-4 text-sm lg:col-span-2"
            >
                <div class="flex items-center justify-between gap-4">
                    <h2 class="font-medium">Units and conversions</h2>
                    <Button
                        v-if="canManageConversions && slug"
                        variant="outline"
                        size="sm"
                        as-child
                    >
                        <Link :href="editSettings.url([slug, product.id])">
                            Manage units
                        </Link>
                    </Button>
                </div>
                <div class="grid gap-2 sm:grid-cols-3">
                    <div
                        class="flex justify-between gap-4 sm:flex-col sm:gap-1"
                    >
                        <span class="text-muted-foreground">Purchase unit</span>
                        <span class="capitalize">{{
                            formatUnit(product.purchase_unit_of_measure)
                        }}</span>
                    </div>
                    <div
                        class="flex justify-between gap-4 sm:flex-col sm:gap-1"
                    >
                        <span class="text-muted-foreground">Stock unit</span>
                        <span class="capitalize">{{
                            formatUnit(product.stock_unit_of_measure)
                        }}</span>
                    </div>
                    <div
                        class="flex justify-between gap-4 sm:flex-col sm:gap-1"
                    >
                        <span class="text-muted-foreground">Usage unit</span>
                        <span class="capitalize">{{
                            formatUnit(product.usage_unit_of_measure)
                        }}</span>
                    </div>
                </div>
                <div
                    v-if="product.unit_conversions.length === 0"
                    class="pt-2 text-muted-foreground"
                >
                    No unit conversions configured.
                </div>
                <ul v-else class="space-y-2 pt-2">
                    <li
                        v-for="conversion in product.unit_conversions"
                        :key="conversion.id"
                        class="rounded-lg border p-3"
                        :class="{ 'opacity-60': !conversion.is_active }"
                    >
                        <p>{{ conversion.preview }}</p>
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
                    </li>
                </ul>
            </section>

            <section
                v-if="showsEstimatedMaterials"
                class="space-y-3 rounded-xl border p-4 text-sm lg:col-span-2"
            >
                <div class="flex items-center justify-between gap-4">
                    <h2 class="font-medium">Estimated materials</h2>
                    <Button
                        v-if="canOpenPricing && slug"
                        variant="outline"
                        size="sm"
                        as-child
                    >
                        <Link :href="editPricing.url([slug, product.id])">
                            Edit pricing
                        </Link>
                    </Button>
                </div>
                <div class="grid gap-2 sm:grid-cols-2">
                    <div class="flex justify-between gap-4">
                        <span class="text-muted-foreground"
                            >Components version</span
                        >
                        <span>{{ product.components_version }}</span>
                    </div>
                    <div class="flex justify-between gap-4">
                        <span class="text-muted-foreground"
                            >Material cost source</span
                        >
                        <span class="capitalize">{{
                            product.material_cost_source
                        }}</span>
                    </div>
                    <div
                        v-if="canViewCost && product.estimated_material_cost"
                        class="flex justify-between gap-4 sm:col-span-2"
                    >
                        <span class="text-muted-foreground"
                            >Estimated material cost</span
                        >
                        <span>${{ product.estimated_material_cost }}</span>
                    </div>
                </div>
                <p class="text-muted-foreground">
                    Estimated usage for costing. This does not reduce inventory
                    or change QuickBooks quantities.
                </p>
                <div
                    v-if="product.components.length === 0"
                    class="text-muted-foreground"
                >
                    No materials configured yet.
                </div>
                <ul v-else class="space-y-2">
                    <li
                        v-for="component in product.components"
                        :key="component.id"
                        class="rounded-lg border p-3"
                        :class="{ 'opacity-60': !component.is_active }"
                    >
                        <div
                            class="flex flex-wrap items-start justify-between gap-2"
                        >
                            <div class="space-y-1">
                                <p class="font-medium">
                                    {{
                                        component.material?.display_name ??
                                        'Material'
                                    }}
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
                                        v-if="
                                            component.converted_purchase_quantity
                                        "
                                    >
                                        ·
                                        {{
                                            component.converted_purchase_quantity
                                        }}
                                        {{
                                            component.purchase_unit_of_measure_label
                                        }}
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
                                    component.is_active
                                        ? 'outline'
                                        : 'destructive'
                                "
                            >
                                {{
                                    component.is_active ? 'Active' : 'Inactive'
                                }}
                            </Badge>
                        </div>
                    </li>
                </ul>
            </section>

            <section
                v-if="product.is_sellable"
                class="space-y-2 rounded-xl border p-4 text-sm lg:col-span-2"
            >
                <div class="flex items-center justify-between gap-4">
                    <h2 class="font-medium">Selling price and costing</h2>
                    <Button
                        v-if="canUpdatePricing && slug"
                        variant="outline"
                        size="sm"
                        as-child
                    >
                        <Link :href="editPricing.url([slug, product.id])">
                            Edit pricing
                        </Link>
                    </Button>
                </div>
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground">Selling price</span>
                    <span class="font-medium">
                        {{
                            product.unit_selling_price
                                ? `$${product.unit_selling_price}`
                                : '—'
                        }}
                    </span>
                </div>
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground"
                        >Components version</span
                    >
                    <span>{{ product.components_version }}</span>
                </div>
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground"
                        >Material cost source</span
                    >
                    <span class="capitalize">{{
                        product.material_cost_source
                    }}</span>
                </div>
                <template v-if="canViewCost">
                    <div class="flex justify-between gap-4">
                        <span class="text-muted-foreground"
                            >Pricing method</span
                        >
                        <span class="capitalize">{{
                            product.pricing_method.replaceAll('_', ' ')
                        }}</span>
                    </div>
                    <div class="flex justify-between gap-4">
                        <span class="text-muted-foreground">Unit cost</span>
                        <span>{{
                            product.unit_cost ? `$${product.unit_cost}` : '—'
                        }}</span>
                    </div>
                    <div class="flex justify-between gap-4">
                        <span class="text-muted-foreground">Material cost</span>
                        <span>${{ product.material_cost }}</span>
                    </div>
                    <div
                        v-if="product.estimated_material_cost"
                        class="flex justify-between gap-4"
                    >
                        <span class="text-muted-foreground"
                            >Estimated material cost</span
                        >
                        <span>${{ product.estimated_material_cost }}</span>
                    </div>
                    <div class="flex justify-between gap-4">
                        <span class="text-muted-foreground">Labor cost</span>
                        <span>${{ product.labor_cost }}</span>
                    </div>
                    <div class="flex justify-between gap-4">
                        <span class="text-muted-foreground">Overhead</span>
                        <span class="capitalize">{{
                            product.overhead_mode?.replaceAll('_', ' ') ?? '—'
                        }}</span>
                    </div>
                    <div
                        v-if="product.markup_percent"
                        class="flex justify-between gap-4"
                    >
                        <span class="text-muted-foreground">Markup</span>
                        <span>{{ product.markup_percent }}%</span>
                    </div>
                    <div
                        v-if="product.target_margin_percent"
                        class="flex justify-between gap-4"
                    >
                        <span class="text-muted-foreground">Target margin</span>
                        <span>{{ product.target_margin_percent }}%</span>
                    </div>
                    <div
                        v-if="product.fixed_price"
                        class="flex justify-between gap-4"
                    >
                        <span class="text-muted-foreground">Fixed price</span>
                        <span>${{ product.fixed_price }}</span>
                    </div>
                    <div
                        v-if="product.minimum_price"
                        class="flex justify-between gap-4"
                    >
                        <span class="text-muted-foreground">Minimum price</span>
                        <span>${{ product.minimum_price }}</span>
                    </div>
                    <p
                        v-if="product.below_minimum"
                        class="pt-2 text-destructive"
                    >
                        Price is below minimum.
                    </p>
                    <p
                        v-for="warning in product.pricing_warnings ?? []"
                        :key="warning"
                        class="pt-1 text-amber-700"
                    >
                        {{ warning }}
                    </p>
                </template>
            </section>
        </div>

        <section class="space-y-3 rounded-xl border p-4">
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
            >
                <div class="space-y-1">
                    <h2 class="font-medium">Vendor offerings</h2>
                    <p class="text-sm text-muted-foreground">
                        The Product Master and internal SKU
                        <span v-if="product.product">
                            ({{ product.product.sku }})</span
                        >
                        are shared. Vendor offerings identify supplier-specific
                        listings and are shared across organizations.
                        Organization-specific package prices and preferred
                        sources are configured in Vendor sources below.
                    </p>
                </div>
                <Button
                    v-if="canManageOfferings && slug"
                    variant="outline"
                    as-child
                >
                    <Link :href="createOffering.url([slug, product.id])">
                        Add offering
                    </Link>
                </Button>
            </div>

            <form
                class="flex flex-col gap-2 sm:flex-row"
                @submit.prevent="applyOfferingFilters"
            >
                <Input
                    v-model="offeringSearch"
                    placeholder="Search vendor SKU, manufacturer, vendor…"
                    class="sm:max-w-sm"
                />
                <select
                    v-model="offeringStatus"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm dark:bg-input/30"
                >
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="discontinued">Discontinued</option>
                </select>
                <Button type="submit" variant="outline">Filter</Button>
            </form>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-muted-foreground">
                        <tr class="border-b">
                            <th class="py-2 pr-3 font-medium">Vendor</th>
                            <th class="py-2 pr-3 font-medium">Vendor SKU</th>
                            <th class="py-2 pr-3 font-medium">Manufacturer</th>
                            <th class="py-2 pr-3 font-medium">UOM / Package</th>
                            <th class="py-2 pr-3 font-medium">MOQ</th>
                            <th class="py-2 pr-3 font-medium">Lead time</th>
                            <th class="py-2 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="offering in vendorOfferings"
                            :key="offering.id"
                            class="border-b last:border-0"
                        >
                            <td class="py-2 pr-3">
                                <Link
                                    v-if="slug"
                                    :href="
                                        showOffering.url([
                                            slug,
                                            product.id,
                                            offering.id,
                                        ])
                                    "
                                    class="hover:underline"
                                >
                                    {{ offering.vendor?.name ?? '—' }}
                                </Link>
                                <span v-else>{{
                                    offering.vendor?.name ?? '—'
                                }}</span>
                            </td>
                            <td class="py-2 pr-3">{{ offering.vendor_sku }}</td>
                            <td class="py-2 pr-3">
                                {{ offering.manufacturer ?? '—' }}
                                <span
                                    v-if="offering.manufacturer_part_number"
                                    class="text-muted-foreground"
                                >
                                    / {{ offering.manufacturer_part_number }}
                                </span>
                            </td>
                            <td class="py-2 pr-3">
                                {{ offering.package_quantity }}
                                {{ offering.purchase_uom_label }}
                            </td>
                            <td class="py-2 pr-3">
                                {{ offering.minimum_order_quantity ?? '—' }}
                            </td>
                            <td class="py-2 pr-3">
                                {{ offering.lead_time_days ?? '—' }}
                            </td>
                            <td class="py-2">
                                <Badge
                                    :variant="
                                        offering.status === 'active'
                                            ? 'secondary'
                                            : 'destructive'
                                    "
                                >
                                    {{ offering.status_label }}
                                </Badge>
                            </td>
                        </tr>
                        <tr v-if="vendorOfferings.length === 0">
                            <td colspan="7" class="py-4 text-muted-foreground">
                                No vendor offerings yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="space-y-3 rounded-xl border p-4">
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
            >
                <div class="space-y-1">
                    <h2 class="font-medium">Vendor sources</h2>
                    <p class="text-sm text-muted-foreground">
                        Organization-specific links to shared vendor offerings,
                        with this organization’s package price and preferred
                        effective cost. Selecting a preferred source updates
                        this organization’s effective material cost. It does not
                        order material or change inventory.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="canManageSources && slug"
                        variant="outline"
                        as-child
                    >
                        <Link :href="createSource.url([slug, product.id])">
                            Add source
                        </Link>
                    </Button>
                    <Button
                        v-if="
                            canClearPreferredSource &&
                            product.preferred_source_id &&
                            slug
                        "
                        variant="secondary"
                        type="button"
                        @click="
                            router.post(clearPreferred.url([slug, product.id]))
                        "
                    >
                        Clear preferred
                    </Button>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-muted-foreground">
                        <tr class="border-b">
                            <th class="py-2 pr-3 font-medium">Vendor</th>
                            <th class="py-2 pr-3 font-medium">Vendor SKU</th>
                            <th class="py-2 pr-3 font-medium">Package</th>
                            <th
                                v-if="canViewCost"
                                class="py-2 pr-3 font-medium"
                            >
                                Package price
                            </th>
                            <th
                                v-if="canViewCost"
                                class="py-2 pr-3 font-medium"
                            >
                                Effective / UOM
                            </th>
                            <th class="py-2 pr-3 font-medium">Status</th>
                            <th class="py-2 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="source in vendorSources"
                            :key="source.id"
                            class="border-b last:border-0"
                        >
                            <td class="py-2 pr-3">
                                <Link
                                    v-if="slug"
                                    :href="
                                        showSource.url([
                                            slug,
                                            product.id,
                                            source.id,
                                        ])
                                    "
                                    class="hover:underline"
                                >
                                    {{ source.offering?.vendor?.name ?? '—' }}
                                </Link>
                            </td>
                            <td class="py-2 pr-3 font-mono">
                                {{ source.offering?.vendor_sku ?? '—' }}
                            </td>
                            <td class="py-2 pr-3">
                                {{ source.offering?.package_quantity }}
                                {{ source.offering?.purchase_uom_label }}
                            </td>
                            <td v-if="canViewCost" class="py-2 pr-3">
                                {{
                                    source.current_package_price
                                        ? `$${source.current_package_price}`
                                        : '—'
                                }}
                            </td>
                            <td v-if="canViewCost" class="py-2 pr-3">
                                {{
                                    source.effective_purchase_unit_cost
                                        ? `$${source.effective_purchase_unit_cost}`
                                        : '—'
                                }}
                            </td>
                            <td class="py-2 pr-3">
                                <div class="flex flex-wrap gap-1">
                                    <Badge
                                        :variant="
                                            source.is_active
                                                ? 'secondary'
                                                : 'outline'
                                        "
                                    >
                                        {{
                                            source.is_active
                                                ? 'Active'
                                                : 'Inactive'
                                        }}
                                    </Badge>
                                    <Badge
                                        v-if="source.is_preferred"
                                        variant="default"
                                        >Preferred</Badge
                                    >
                                </div>
                            </td>
                            <td class="py-2">
                                <Button
                                    v-if="
                                        canManageSourcePricing &&
                                        source.is_active &&
                                        !source.is_preferred &&
                                        slug
                                    "
                                    size="sm"
                                    variant="outline"
                                    type="button"
                                    @click="
                                        router.post(
                                            prefer.url([
                                                slug,
                                                product.id,
                                                source.id,
                                            ]),
                                        )
                                    "
                                >
                                    Prefer
                                </Button>
                            </td>
                        </tr>
                        <tr v-if="vendorSources.length === 0">
                            <td
                                :colspan="canViewCost ? 7 : 5"
                                class="py-4 text-muted-foreground"
                            >
                                No organization vendor sources yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <Button
            v-if="canArchive"
            variant="destructive"
            class="w-fit"
            @click="archiveProduct"
        >
            Archive product
        </Button>
    </div>
</template>
