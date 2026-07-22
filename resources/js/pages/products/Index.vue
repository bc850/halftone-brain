<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTenantRoute } from '@/composables/useTenantAction';
import { dashboard } from '@/routes';
import {
    create as orgCreate,
    index as orgIndex,
    show as orgShow,
} from '@/routes/org/products';
import {
    create as legacyCreate,
    index as legacyIndex,
    show as legacyShow,
} from '@/routes/products';

const create = useTenantRoute(legacyCreate, orgCreate);
const index = useTenantRoute(legacyIndex, orgIndex);
const show = useTenantRoute(legacyShow, orgShow);

type Option = { id: number; name: string };

type Product = {
    id: number;
    name: string;
    sku: string;
    true_cost?: string;
    markup_percent?: string;
    list_price: string | null;
    suggested_sell_price: string;
    is_active: boolean;
    vendor?: Option | null;
    category?: Option | null;
};

const props = defineProps<{
    products: { data: Product[] };
    filters: {
        search: string;
        category_id: number | null;
        vendor_id: number | null;
    };
    categories: Option[];
    vendors: Option[];
    canManage: boolean;
    canViewCost: boolean;
}>();

const fieldClass =
    'border-input bg-transparent dark:bg-input/30 h-9 rounded-md border px-3 text-sm outline-none';

function refresh(
    overrides: Record<string, string | number | undefined> = {},
): void {
    router.get(
        index.url({
            query: {
                search: props.filters.search || undefined,
                category_id: props.filters.category_id || undefined,
                vendor_id: props.filters.vendor_id || undefined,
                ...overrides,
            },
        }),
        {},
        { preserveState: true, replace: true },
    );
}

function onSearch(event: Event): void {
    refresh({ search: (event.target as HTMLInputElement).value || undefined });
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
    <Head title="Products" />

    <div class="flex flex-col gap-6 p-4">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <Heading
                title="Products"
                :description="
                    canViewCost
                        ? 'Catalog with true cost, markup, and suggested sell price'
                        : 'Product catalog with list and suggested pricing'
                "
            />
            <Button v-if="canManage" as-child>
                <Link :href="create()">
                    <Plus class="size-4" />
                    New product
                </Link>
            </Button>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <Input
                :default-value="filters.search"
                placeholder="Search SKU or name..."
                class="max-w-sm"
                @change="onSearch"
            />
            <select
                :class="fieldClass"
                :value="filters.category_id ?? ''"
                @change="
                    refresh({
                        category_id:
                            ($event.target as HTMLSelectElement).value ||
                            undefined,
                    })
                "
            >
                <option value="">All categories</option>
                <option
                    v-for="category in categories"
                    :key="category.id"
                    :value="category.id"
                >
                    {{ category.name }}
                </option>
            </select>
            <select
                :class="fieldClass"
                :value="filters.vendor_id ?? ''"
                @change="
                    refresh({
                        vendor_id:
                            ($event.target as HTMLSelectElement).value ||
                            undefined,
                    })
                "
            >
                <option value="">All vendors</option>
                <option
                    v-for="vendor in vendors"
                    :key="vendor.id"
                    :value="vendor.id"
                >
                    {{ vendor.name }}
                </option>
            </select>
        </div>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full min-w-[720px] text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">Product</th>
                        <th class="px-4 py-3 font-medium">SKU</th>
                        <th class="px-4 py-3 font-medium">Vendor</th>
                        <th v-if="canViewCost" class="px-4 py-3 font-medium">
                            True cost
                        </th>
                        <th v-if="canViewCost" class="px-4 py-3 font-medium">
                            Markup
                        </th>
                        <th class="px-4 py-3 font-medium">Suggested</th>
                        <th class="px-4 py-3 font-medium">List</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="product in products.data"
                        :key="product.id"
                        class="border-t hover:bg-muted/30"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="show(product.id)"
                                class="font-medium hover:underline"
                            >
                                {{ product.name }}
                            </Link>
                            <div class="text-xs text-muted-foreground">
                                {{ product.category?.name ?? 'Uncategorized' }}
                            </div>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ product.sku }}
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ product.vendor?.name ?? '—' }}
                        </td>
                        <td
                            v-if="canViewCost"
                            class="px-4 py-3 text-muted-foreground"
                        >
                            ${{ product.true_cost }}
                        </td>
                        <td
                            v-if="canViewCost"
                            class="px-4 py-3 text-muted-foreground"
                        >
                            {{ product.markup_percent }}%
                        </td>
                        <td class="px-4 py-3 font-medium">
                            ${{ product.suggested_sell_price }}
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{
                                product.list_price
                                    ? `$${product.list_price}`
                                    : '—'
                            }}
                        </td>
                    </tr>
                    <tr v-if="products.data.length === 0">
                        <td
                            :colspan="canViewCost ? 7 : 5"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            No products yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
