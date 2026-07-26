<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTenantRoute } from '@/composables/useTenantAction';
import { dashboard } from '@/routes';
import {
    destroy as orgDestroy,
    edit as orgEdit,
    show as orgShowVendor,
} from '@/routes/org/vendors';
import {
    create as createOffering,
    show as showOffering,
} from '@/routes/org/vendors/offerings';
import {
    destroy as legacyDestroy,
    edit as legacyEdit,
    index as legacyIndex,
} from '@/routes/vendors';
import type { Tenant } from '@/types';

const destroy = useTenantRoute(legacyDestroy, orgDestroy);
const edit = useTenantRoute(legacyEdit, orgEdit);

type VendorOffering = {
    id: number;
    vendor_sku: string;
    purchase_uom_label: string;
    package_quantity: string;
    status: string;
    status_label: string;
    product: { id: number; name: string; sku: string } | null;
};

type Vendor = {
    id: number;
    name: string;
    account_number: string | null;
    email: string | null;
    phone: string | null;
    website: string | null;
    notes: string | null;
    is_active: boolean;
};

const props = defineProps<{
    vendor: Vendor;
    vendorOfferings: VendorOffering[];
    offeringFilters: {
        offering_search: string;
        offering_status: string;
    };
    canManage: boolean;
    canManageOfferings: boolean;
    canViewDetails: boolean;
}>();

const slug = (usePage().props.tenant as Tenant | null | undefined)?.organization
    ?.slug;

const offeringSearch = ref(props.offeringFilters.offering_search ?? '');
const offeringStatus = ref(props.offeringFilters.offering_status ?? '');

function deleteVendor(): void {
    if (confirm('Delete this vendor?')) {
        router.delete(destroy.url(props.vendor.id));
    }
}

function applyOfferingFilters(): void {
    if (!slug) {
        return;
    }

    router.get(
        orgShowVendor.url([slug, props.vendor.id]),
        {
            offering_search: offeringSearch.value || undefined,
            offering_status: offeringStatus.value || undefined,
        },
        { preserveState: true, replace: true },
    );
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Vendors', href: legacyIndex() },
            { title: 'Vendor', href: legacyIndex() },
        ],
    },
});
</script>

<template>
    <Head :title="vendor.name" />

    <div class="flex flex-col gap-6 p-4">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                :title="vendor.name"
                :description="
                    vendor.is_active ? 'Active vendor' : 'Inactive vendor'
                "
            />
            <Button v-if="canManage" variant="outline" as-child>
                <Link :href="edit(vendor.id)">
                    <Pencil class="size-4" />
                    Edit
                </Link>
            </Button>
        </div>

        <section
            v-if="canViewDetails"
            class="max-w-xl space-y-2 rounded-xl border p-4 text-sm"
        >
            <div class="flex justify-between gap-4">
                <span class="text-muted-foreground">Account #</span>
                <span>{{ vendor.account_number ?? '—' }}</span>
            </div>
            <div class="flex justify-between gap-4">
                <span class="text-muted-foreground">Email</span>
                <span>{{ vendor.email ?? '—' }}</span>
            </div>
            <div class="flex justify-between gap-4">
                <span class="text-muted-foreground">Phone</span>
                <span>{{ vendor.phone ?? '—' }}</span>
            </div>
            <div class="flex justify-between gap-4">
                <span class="text-muted-foreground">Website</span>
                <span>{{ vendor.website ?? '—' }}</span>
            </div>
            <p v-if="vendor.notes" class="pt-2 text-muted-foreground">
                {{ vendor.notes }}
            </p>
        </section>

        <section class="space-y-3 rounded-xl border p-4">
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
            >
                <div class="space-y-1">
                    <h2 class="font-medium">Vendor offerings</h2>
                    <p class="text-sm text-muted-foreground">
                        Offerings for this vendor are parent-account shared.
                        Organizations see the same definitions without
                        duplicated rows. Organization pricing is configured
                        separately later.
                    </p>
                </div>
                <Button
                    v-if="canManageOfferings && slug"
                    variant="outline"
                    as-child
                >
                    <Link :href="createOffering.url([slug, vendor.id])">
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
                    placeholder="Search internal SKU/name or vendor SKU…"
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
                            <th class="py-2 pr-3 font-medium">
                                Internal product
                            </th>
                            <th class="py-2 pr-3 font-medium">Vendor SKU</th>
                            <th class="py-2 pr-3 font-medium">Package / UOM</th>
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
                                            vendor.id,
                                            offering.id,
                                        ])
                                    "
                                    class="hover:underline"
                                >
                                    {{ offering.product?.name ?? '—' }}
                                </Link>
                                <span class="text-muted-foreground">
                                    ({{ offering.product?.sku ?? '—' }})
                                </span>
                            </td>
                            <td class="py-2 pr-3">{{ offering.vendor_sku }}</td>
                            <td class="py-2 pr-3">
                                {{ offering.package_quantity }}
                                {{ offering.purchase_uom_label }}
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
                            <td colspan="4" class="py-4 text-muted-foreground">
                                No offerings for this vendor.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <Button
            v-if="canManage"
            variant="destructive"
            class="w-fit"
            @click="deleteVendor"
        >
            Delete vendor
        </Button>
    </div>
</template>
