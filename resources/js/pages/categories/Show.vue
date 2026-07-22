<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { useTenantRoute } from '@/composables/useTenantAction';
import { dashboard } from '@/routes';
import {
    destroy as legacyDestroy,
    edit as legacyEdit,
    index as legacyIndex,
} from '@/routes/categories';
import {
    destroy as orgDestroy,
    edit as orgEdit,
} from '@/routes/org/categories';
import { show as orgShowProduct } from '@/routes/org/products';
import { show as legacyShowProduct } from '@/routes/products';

const destroy = useTenantRoute(legacyDestroy, orgDestroy);
const edit = useTenantRoute(legacyEdit, orgEdit);
const showProduct = useTenantRoute(legacyShowProduct, orgShowProduct);

type Product = { id: number; name: string; sku: string };

type Category = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    sort_order: number;
    products: Product[];
};

const props = defineProps<{
    category: Category;
    canManage: boolean;
}>();

function deleteCategory(): void {
    if (confirm('Delete this category?')) {
        router.delete(destroy.url(props.category.id));
    }
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Categories', href: legacyIndex() },
            { title: 'Category', href: legacyIndex() },
        ],
    },
});
</script>

<template>
    <Head :title="category.name" />

    <div class="flex flex-col gap-6 p-4">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                :title="category.name"
                :description="category.description ?? undefined"
            />
            <Button v-if="canManage" variant="outline" as-child>
                <Link :href="edit(category.id)">
                    <Pencil class="size-4" />
                    Edit
                </Link>
            </Button>
        </div>

        <section class="space-y-3 rounded-xl border p-4">
            <h2 class="font-medium">Products</h2>
            <ul class="divide-y text-sm">
                <li
                    v-for="product in category.products"
                    :key="product.id"
                    class="flex justify-between gap-3 py-2"
                >
                    <Link
                        :href="showProduct(product.id)"
                        class="hover:underline"
                    >
                        {{ product.name }}
                    </Link>
                    <span class="text-muted-foreground">{{ product.sku }}</span>
                </li>
                <li
                    v-if="category.products.length === 0"
                    class="py-4 text-muted-foreground"
                >
                    No products in this category.
                </li>
            </ul>
        </section>

        <Button
            v-if="canManage"
            variant="destructive"
            class="w-fit"
            @click="deleteCategory"
        >
            Delete category
        </Button>
    </div>
</template>
