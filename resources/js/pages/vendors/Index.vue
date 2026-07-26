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
} from '@/routes/org/vendors';
import {
    create as legacyCreate,
    index as legacyIndex,
    show as legacyShow,
} from '@/routes/vendors';

const create = useTenantRoute(legacyCreate, orgCreate);
const index = useTenantRoute(legacyIndex, orgIndex);
const show = useTenantRoute(legacyShow, orgShow);

type Vendor = {
    id: number;
    name: string;
    account_number: string | null;
    email: string | null;
    phone: string | null;
    is_active: boolean;
    offerings_count: number;
};

defineProps<{
    vendors: { data: Vendor[] };
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
            { title: 'Vendors', href: legacyIndex() },
        ],
    },
});
</script>

<template>
    <Head title="Vendors" />

    <div class="flex flex-col gap-6 p-4">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <Heading
                title="Vendors"
                description="Suppliers for catalog products"
            />
            <Button v-if="canManage" as-child>
                <Link :href="create()">
                    <Plus class="size-4" />
                    New vendor
                </Link>
            </Button>
        </div>

        <Input
            :default-value="filters.search"
            placeholder="Search vendors..."
            class="max-w-sm"
            @change="onSearch"
        />

        <div class="overflow-hidden rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Account #</th>
                        <th class="px-4 py-3 font-medium">Offerings</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="vendor in vendors.data"
                        :key="vendor.id"
                        class="border-t hover:bg-muted/30"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="show(vendor.id)"
                                class="font-medium hover:underline"
                            >
                                {{ vendor.name }}
                            </Link>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ vendor.account_number ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ vendor.offerings_count }}
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ vendor.is_active ? 'Active' : 'Inactive' }}
                        </td>
                    </tr>
                    <tr v-if="vendors.data.length === 0">
                        <td
                            colspan="4"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            No vendors yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
