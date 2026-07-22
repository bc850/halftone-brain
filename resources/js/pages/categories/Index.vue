<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTenantRoute } from '@/composables/useTenantAction';
import { dashboard } from '@/routes';
import {
    create as legacyCreate,
    index as legacyIndex,
    show as legacyShow,
} from '@/routes/categories';
import {
    create as orgCreate,
    index as orgIndex,
    show as orgShow,
} from '@/routes/org/categories';

const create = useTenantRoute(legacyCreate, orgCreate);
const index = useTenantRoute(legacyIndex, orgIndex);
const show = useTenantRoute(legacyShow, orgShow);

type Category = {
    id: number;
    name: string;
    slug: string;
    products_count: number;
    sort_order: number;
};

defineProps<{
    categories: { data: Category[] };
    filters: { search: string };
    canManage: boolean;
}>();

function onSearch(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    router.get(
        index.url({ query: { search: value || undefined } }),
        {},
        { preserveState: true, replace: true },
    );
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Categories', href: legacyIndex() },
        ],
    },
});
</script>

<template>
    <Head title="Categories" />

    <div class="flex flex-col gap-6 p-4">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <Heading
                title="Categories"
                description="Organize catalog products"
            />
            <Button v-if="canManage" as-child>
                <Link :href="create()">
                    <Plus class="size-4" />
                    New category
                </Link>
            </Button>
        </div>

        <Input
            :default-value="filters.search"
            placeholder="Search categories..."
            class="max-w-sm"
            @change="onSearch"
        />

        <div class="overflow-hidden rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Slug</th>
                        <th class="px-4 py-3 font-medium">Products</th>
                        <th class="px-4 py-3 font-medium">Sort</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="category in categories.data"
                        :key="category.id"
                        class="border-t hover:bg-muted/30"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="show(category.id)"
                                class="font-medium hover:underline"
                            >
                                {{ category.name }}
                            </Link>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ category.slug }}
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ category.products_count }}
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ category.sort_order }}
                        </td>
                    </tr>
                    <tr v-if="categories.data.length === 0">
                        <td
                            colspan="4"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            No categories yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
