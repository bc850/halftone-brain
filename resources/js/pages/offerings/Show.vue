<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import {
    discontinue as discontinueForProduct,
    edit as editForProduct,
    reactivate as reactivateForProduct,
} from '@/routes/org/products/offerings';
import {
    discontinue as discontinueForVendor,
    edit as editForVendor,
    reactivate as reactivateForVendor,
} from '@/routes/org/vendors/offerings';
import type { Tenant } from '@/types';

type Offering = {
    id: number;
    vendor_sku: string;
    vendor_description: string | null;
    manufacturer: string | null;
    manufacturer_part_number: string | null;
    product_url: string | null;
    purchase_uom_label: string;
    package_quantity: string;
    minimum_order_quantity: string | null;
    lead_time_days: number | null;
    status: string;
    status_label: string;
    discontinued_at: string | null;
    product: { id: number; name: string; sku: string } | null;
    vendor: { id: number; name: string } | null;
};

const props = defineProps<{
    offering: Offering;
    canManage: boolean;
    context: 'product' | 'vendor';
    organizationProductId?: number;
    vendorId?: number;
    returnUrl: string;
}>();

const slug = (usePage().props.tenant as Tenant | null | undefined)?.organization
    ?.slug;

const editUrl = computed(() => {
    if (!slug) {
        return props.returnUrl;
    }

    if (props.context === 'product' && props.organizationProductId) {
        return editForProduct.url([
            slug,
            props.organizationProductId,
            props.offering.id,
        ]);
    }

    if (props.context === 'vendor' && props.vendorId) {
        return editForVendor.url([slug, props.vendorId, props.offering.id]);
    }

    return props.returnUrl;
});

function discontinueOffering(): void {
    if (!slug || !confirm('Discontinue this vendor offering?')) {
        return;
    }

    if (props.context === 'product' && props.organizationProductId) {
        router.post(
            discontinueForProduct.url([
                slug,
                props.organizationProductId,
                props.offering.id,
            ]),
        );

        return;
    }

    if (props.context === 'vendor' && props.vendorId) {
        router.post(
            discontinueForVendor.url([slug, props.vendorId, props.offering.id]),
        );
    }
}

function reactivateOffering(): void {
    if (!slug || !confirm('Reactivate this vendor offering?')) {
        return;
    }

    if (props.context === 'product' && props.organizationProductId) {
        router.post(
            reactivateForProduct.url([
                slug,
                props.organizationProductId,
                props.offering.id,
            ]),
        );

        return;
    }

    if (props.context === 'vendor' && props.vendorId) {
        router.post(
            reactivateForVendor.url([slug, props.vendorId, props.offering.id]),
        );
    }
}

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
    <Head :title="`Offering ${offering.vendor_sku}`" />

    <div class="mx-auto flex max-w-2xl flex-col gap-6 p-4">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <div class="space-y-2">
                <Heading
                    :title="offering.vendor_sku"
                    :description="offering.vendor?.name ?? 'Vendor offering'"
                />
                <Badge
                    :variant="
                        offering.status === 'active'
                            ? 'secondary'
                            : 'destructive'
                    "
                >
                    {{ offering.status_label }}
                </Badge>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button v-if="canManage" variant="outline" as-child>
                    <Link :href="editUrl">Edit</Link>
                </Button>
                <Button
                    v-if="canManage && offering.status === 'active'"
                    variant="outline"
                    @click="discontinueOffering"
                >
                    Discontinue
                </Button>
                <Button
                    v-if="canManage && offering.status === 'discontinued'"
                    variant="outline"
                    @click="reactivateOffering"
                >
                    Reactivate
                </Button>
            </div>
        </div>

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
            <p>
                Organization-specific vendor pricing and preferred sources will
                be configured separately in a later checkpoint.
            </p>
        </div>

        <section class="space-y-2 rounded-xl border p-4 text-sm">
            <div class="flex justify-between gap-4">
                <span class="text-muted-foreground">Internal product</span>
                <span>
                    {{ offering.product?.name ?? '—' }}
                    <span class="text-muted-foreground"
                        >({{ offering.product?.sku ?? '—' }})</span
                    >
                </span>
            </div>
            <div class="flex justify-between gap-4">
                <span class="text-muted-foreground">Vendor</span>
                <span>{{ offering.vendor?.name ?? '—' }}</span>
            </div>
            <div class="flex justify-between gap-4">
                <span class="text-muted-foreground">Vendor SKU</span>
                <span>{{ offering.vendor_sku }}</span>
            </div>
            <div class="flex justify-between gap-4">
                <span class="text-muted-foreground">Manufacturer</span>
                <span>{{ offering.manufacturer ?? '—' }}</span>
            </div>
            <div class="flex justify-between gap-4">
                <span class="text-muted-foreground">MPN</span>
                <span>{{ offering.manufacturer_part_number ?? '—' }}</span>
            </div>
            <div class="flex justify-between gap-4">
                <span class="text-muted-foreground">Purchase UOM</span>
                <span>{{ offering.purchase_uom_label }}</span>
            </div>
            <div class="flex justify-between gap-4">
                <span class="text-muted-foreground">Package quantity</span>
                <span>{{ offering.package_quantity }}</span>
            </div>
            <div class="flex justify-between gap-4">
                <span class="text-muted-foreground">MOQ</span>
                <span>{{ offering.minimum_order_quantity ?? '—' }}</span>
            </div>
            <div class="flex justify-between gap-4">
                <span class="text-muted-foreground">Lead time (days)</span>
                <span>{{ offering.lead_time_days ?? '—' }}</span>
            </div>
            <div v-if="offering.product_url" class="flex justify-between gap-4">
                <span class="text-muted-foreground">Product URL</span>
                <a
                    :href="offering.product_url"
                    class="text-right break-all hover:underline"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    {{ offering.product_url }}
                </a>
            </div>
            <p
                v-if="offering.vendor_description"
                class="pt-2 text-muted-foreground"
            >
                {{ offering.vendor_description }}
            </p>
        </section>

        <Button variant="outline" as-child class="w-fit">
            <Link :href="returnUrl">Back</Link>
        </Button>
    </div>
</template>
