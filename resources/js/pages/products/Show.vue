<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import {
    archive,
    editMaster,
    editPricing,
    editSettings,
} from '@/routes/org/products';
import { index as legacyIndex } from '@/routes/products';
import type { Tenant } from '@/types';

type OrganizationProduct = {
    id: number;
    display_name: string;
    is_available: boolean;
    lead_time_days: number | null;
    organization_notes: string | null;
    pricing_method: string;
    pricing_version: number;
    unit_selling_price: string | null;
    product: {
        id: number;
        name: string;
        sku: string;
        product_family: string;
        unit_of_measure: string;
        description: string | null;
        is_active: boolean;
        vendor_sku?: string | null;
        notes?: string | null;
        vendor?: { id: number; name: string } | null;
        category?: { id: number; name: string } | null;
    } | null;
    material_cost?: string;
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

const props = defineProps<{
    product: OrganizationProduct;
    canUpdateMaster: boolean;
    canUpdateSettings: boolean;
    canUpdatePricing: boolean;
    canArchive: boolean;
    canViewCost: boolean;
}>();

const slug = (usePage().props.tenant as Tenant | null | undefined)?.organization
    ?.slug;

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
            <Heading
                :title="product.display_name"
                :description="product.product?.sku"
            />
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
                    v-if="canUpdatePricing && slug"
                    variant="outline"
                    as-child
                >
                    <Link :href="editPricing.url([slug, product.id])">
                        Edit pricing
                    </Link>
                </Button>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="space-y-2 rounded-xl border p-4 text-sm">
                <h2 class="font-medium">Catalog</h2>
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground">SKU</span>
                    <span>{{ product.product?.sku ?? '—' }}</span>
                </div>
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground">Family</span>
                    <span class="capitalize">{{
                        product.product?.product_family ?? '—'
                    }}</span>
                </div>
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground">Available</span>
                    <span>{{ product.is_available ? 'Yes' : 'No' }}</span>
                </div>
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground">Unit</span>
                    <span class="capitalize">{{
                        product.product?.unit_of_measure?.replaceAll(
                            '_',
                            ' ',
                        ) ?? '—'
                    }}</span>
                </div>
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground">Lead time</span>
                    <span>{{ product.lead_time_days ?? '—' }}</span>
                </div>
                <p
                    v-if="product.product?.description"
                    class="pt-2 text-muted-foreground"
                >
                    {{ product.product.description }}
                </p>
            </section>

            <section class="space-y-2 rounded-xl border p-4 text-sm">
                <h2 class="font-medium">Pricing</h2>
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
