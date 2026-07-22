<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import VendorController from '@/actions/App/Http/Controllers/VendorController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { show as showProduct } from '@/routes/products';
import { edit, index } from '@/routes/vendors';

type Product = {
    id: number;
    name: string;
    sku: string;
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
    products: Product[];
};

const props = defineProps<{
    vendor: Vendor;
    canManage: boolean;
    canViewDetails: boolean;
}>();

function deleteVendor(): void {
    if (confirm('Delete this vendor?')) {
        router.delete(VendorController.destroy.url(props.vendor.id));
    }
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Vendors', href: index() },
            { title: 'Vendor', href: index() },
        ],
    },
});
</script>

<template>
    <Head :title="vendor.name" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <Heading
                :title="vendor.name"
                :description="vendor.is_active ? 'Active vendor' : 'Inactive vendor'"
            />
            <Button v-if="canManage" variant="outline" as-child>
                <Link :href="edit(vendor.id)">
                    <Pencil class="size-4" />
                    Edit
                </Link>
            </Button>
        </div>

        <section v-if="canViewDetails" class="max-w-xl space-y-2 rounded-xl border p-4 text-sm">
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
            <p v-if="vendor.notes" class="pt-2 text-muted-foreground">{{ vendor.notes }}</p>
        </section>

        <section class="space-y-3 rounded-xl border p-4">
            <h2 class="font-medium">Products</h2>
            <ul class="divide-y text-sm">
                <li
                    v-for="product in vendor.products"
                    :key="product.id"
                    class="flex justify-between gap-3 py-2"
                >
                    <Link :href="showProduct(product.id)" class="hover:underline">
                        {{ product.name }}
                    </Link>
                    <span class="text-muted-foreground">{{ product.sku }}</span>
                </li>
                <li v-if="vendor.products.length === 0" class="py-4 text-muted-foreground">
                    No products linked.
                </li>
            </ul>
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
