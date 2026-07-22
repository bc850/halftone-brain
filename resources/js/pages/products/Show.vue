<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import ProductController from '@/actions/App/Http/Controllers/ProductController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { show as showCategory } from '@/routes/categories';
import { edit, index, show as showProduct } from '@/routes/products';
import { show as showVendor } from '@/routes/vendors';

type Related = {
    id: number;
    name: string;
    sku: string;
    list_price: string | null;
};

type Product = {
    id: number;
    name: string;
    sku: string;
    vendor_sku?: string | null;
    unit_of_measure: string;
    true_cost?: string;
    markup_percent?: string;
    list_price: string | null;
    suggested_sell_price: string;
    description: string | null;
    notes?: string | null;
    is_active: boolean;
    vendor?: { id: number; name: string } | null;
    category?: { id: number; name: string } | null;
    related_products?: Related[];
};

const props = defineProps<{
    product: Product;
    canManage: boolean;
    canViewCost: boolean;
}>();

function deleteProduct(): void {
    if (confirm('Delete this product?')) {
        router.delete(ProductController.destroy.url(props.product.id));
    }
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Products', href: index() },
            { title: 'Product', href: index() },
        ],
    },
});
</script>

<template>
    <Head :title="product.name" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <Heading
                :title="product.name"
                :description="product.is_active ? product.sku : `${product.sku} · Inactive`"
            />
            <Button v-if="canManage" variant="outline" as-child>
                <Link :href="edit(product.id)">
                    <Pencil class="size-4" />
                    Edit
                </Link>
            </Button>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="space-y-2 rounded-xl border p-4 text-sm">
                <h2 class="font-medium">Catalog</h2>
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground">Vendor</span>
                    <Link
                        v-if="product.vendor"
                        :href="showVendor(product.vendor.id)"
                        class="hover:underline"
                    >
                        {{ product.vendor.name }}
                    </Link>
                    <span v-else>—</span>
                </div>
                <div v-if="canViewCost" class="flex justify-between gap-4">
                    <span class="text-muted-foreground">Vendor SKU</span>
                    <span>{{ product.vendor_sku ?? '—' }}</span>
                </div>
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground">Category</span>
                    <Link
                        v-if="product.category"
                        :href="showCategory(product.category.id)"
                        class="hover:underline"
                    >
                        {{ product.category.name }}
                    </Link>
                    <span v-else>—</span>
                </div>
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground">Unit</span>
                    <span class="capitalize">{{ product.unit_of_measure.replaceAll('_', ' ') }}</span>
                </div>
                <p v-if="product.description" class="pt-2 text-muted-foreground">
                    {{ product.description }}
                </p>
            </section>

            <section class="space-y-2 rounded-xl border p-4 text-sm">
                <h2 class="font-medium">Pricing</h2>
                <template v-if="canViewCost">
                    <div class="flex justify-between gap-4">
                        <span class="text-muted-foreground">True cost</span>
                        <span>${{ product.true_cost }}</span>
                    </div>
                    <div class="flex justify-between gap-4">
                        <span class="text-muted-foreground">Markup</span>
                        <span>{{ product.markup_percent }}%</span>
                    </div>
                </template>
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground">Suggested sell</span>
                    <span class="font-medium">${{ product.suggested_sell_price }}</span>
                </div>
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground">List price</span>
                    <span>
                        {{ product.list_price ? `$${product.list_price}` : '—' }}
                    </span>
                </div>
                <p v-if="canViewCost && product.notes" class="pt-2 text-muted-foreground">
                    {{ product.notes }}
                </p>
            </section>

            <section
                v-if="product.related_products"
                class="space-y-3 rounded-xl border p-4 lg:col-span-2"
            >
                <h2 class="font-medium">Related products</h2>
                <ul class="divide-y text-sm">
                    <li
                        v-for="related in product.related_products"
                        :key="related.id"
                        class="flex justify-between gap-3 py-2"
                    >
                        <Link :href="showProduct(related.id)" class="hover:underline">
                            {{ related.name }}
                        </Link>
                        <span class="text-muted-foreground">{{ related.sku }}</span>
                    </li>
                    <li
                        v-if="product.related_products.length === 0"
                        class="py-4 text-muted-foreground"
                    >
                        No related products.
                    </li>
                </ul>
            </section>
        </div>

        <Button
            v-if="canManage"
            variant="destructive"
            class="w-fit"
            @click="deleteProduct"
        >
            Delete product
        </Button>
    </div>
</template>
