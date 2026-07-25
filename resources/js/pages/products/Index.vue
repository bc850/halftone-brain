<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTenantRoute } from '@/composables/useTenantAction';
import { dashboard } from '@/routes';
import {
    addExisting as orgAddExisting,
    create as orgCreate,
    index as orgIndex,
    show as orgShow,
} from '@/routes/org/products';
import {
    create as legacyCreate,
    index as legacyIndex,
    show as legacyShow,
} from '@/routes/products';
import type { Tenant } from '@/types';

const create = useTenantRoute(legacyCreate, orgCreate);
const index = useTenantRoute(legacyIndex, orgIndex);
const show = useTenantRoute(legacyShow, orgShow);
const organizationSlug = (usePage().props.tenant as Tenant | null | undefined)
    ?.organization?.slug;

type Option = { value: string; label: string };

type CatalogProduct = {
    id: number;
    display_name: string;
    is_available: boolean;
    is_sellable: boolean;
    is_purchasable: boolean;
    inventory_tracking_mode: string;
    inventory_tracking_mode_label: string;
    lead_time_days: number | null;
    pricing_method: string;
    unit_selling_price: string | null;
    material_cost?: string;
    unit_cost?: string;
    product: {
        id: number;
        name: string;
        sku: string;
        product_family: string;
        item_kind: string;
        item_kind_label: string;
        unit_of_measure: string;
        is_active: boolean;
    } | null;
};

const props = defineProps<{
    products: { data: CatalogProduct[] };
    filters: {
        search: string;
        product_family: string | null;
        is_available: boolean | null;
        item_kind: string | null;
        is_sellable: boolean | null;
        is_purchasable: boolean | null;
        inventory_tracking_mode: string | null;
    };
    families: Option[];
    itemKinds: Option[];
    inventoryModes: Option[];
    canCreate: boolean;
    canAssociate: boolean;
    canViewCost: boolean;
}>();

const fieldClass =
    'border-input bg-transparent dark:bg-input/30 h-9 rounded-md border px-3 text-sm outline-none';

function booleanFilterValue(value: boolean | null): string {
    if (value === null) {
        return '';
    }

    return value ? '1' : '0';
}

function refresh(
    overrides: Record<string, string | number | boolean | undefined> = {},
): void {
    router.get(
        index.url({
            query: {
                search: props.filters.search || undefined,
                product_family: props.filters.product_family || undefined,
                is_available:
                    props.filters.is_available === null
                        ? undefined
                        : props.filters.is_available
                          ? '1'
                          : '0',
                item_kind: props.filters.item_kind || undefined,
                is_sellable:
                    props.filters.is_sellable === null
                        ? undefined
                        : props.filters.is_sellable
                          ? '1'
                          : '0',
                is_purchasable:
                    props.filters.is_purchasable === null
                        ? undefined
                        : props.filters.is_purchasable
                          ? '1'
                          : '0',
                inventory_tracking_mode:
                    props.filters.inventory_tracking_mode || undefined,
                ...overrides,
            },
        }),
        {},
        { preserveState: true, replace: true },
    );
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
                title="Organization catalog"
                :description="
                    canViewCost
                        ? 'Products available to this organization with costs and pricing'
                        : 'Products available to this organization'
                "
            />
            <div class="flex flex-wrap gap-2">
                <Button v-if="canAssociate" variant="outline" as-child>
                    <Link
                        :href="
                            organizationSlug
                                ? orgAddExisting.url(organizationSlug)
                                : '#'
                        "
                    >
                        Add existing
                    </Link>
                </Button>
                <Button v-if="canCreate" as-child>
                    <Link :href="create()">
                        <Plus class="size-4" />
                        New product
                    </Link>
                </Button>
            </div>
        </div>

        <div
            class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center"
        >
            <Input
                :default-value="filters.search"
                placeholder="Search name or SKU..."
                class="max-w-sm"
                @change="
                    refresh({
                        search:
                            ($event.target as HTMLInputElement).value ||
                            undefined,
                    })
                "
            />
            <select
                :class="fieldClass"
                :value="filters.product_family ?? ''"
                @change="
                    refresh({
                        product_family:
                            ($event.target as HTMLSelectElement).value ||
                            undefined,
                    })
                "
            >
                <option value="">All families</option>
                <option
                    v-for="family in families"
                    :key="family.value"
                    :value="family.value"
                >
                    {{ family.label }}
                </option>
            </select>
            <select
                :class="fieldClass"
                :value="filters.item_kind ?? ''"
                @change="
                    refresh({
                        item_kind:
                            ($event.target as HTMLSelectElement).value ||
                            undefined,
                    })
                "
            >
                <option value="">All kinds</option>
                <option
                    v-for="kind in itemKinds"
                    :key="kind.value"
                    :value="kind.value"
                >
                    {{ kind.label }}
                </option>
            </select>
            <select
                :class="fieldClass"
                :value="booleanFilterValue(filters.is_available)"
                @change="
                    refresh({
                        is_available:
                            ($event.target as HTMLSelectElement).value === ''
                                ? undefined
                                : ($event.target as HTMLSelectElement).value ===
                                  '1',
                    })
                "
            >
                <option value="">Any availability</option>
                <option value="1">Available</option>
                <option value="0">Unavailable</option>
            </select>
            <select
                :class="fieldClass"
                :value="booleanFilterValue(filters.is_sellable)"
                @change="
                    refresh({
                        is_sellable:
                            ($event.target as HTMLSelectElement).value === ''
                                ? undefined
                                : ($event.target as HTMLSelectElement).value ===
                                  '1',
                    })
                "
            >
                <option value="">Any sellable</option>
                <option value="1">Sellable</option>
                <option value="0">Not sellable</option>
            </select>
            <select
                :class="fieldClass"
                :value="booleanFilterValue(filters.is_purchasable)"
                @change="
                    refresh({
                        is_purchasable:
                            ($event.target as HTMLSelectElement).value === ''
                                ? undefined
                                : ($event.target as HTMLSelectElement).value ===
                                  '1',
                    })
                "
            >
                <option value="">Any purchasable</option>
                <option value="1">Purchasable</option>
                <option value="0">Not purchasable</option>
            </select>
            <select
                :class="fieldClass"
                :value="filters.inventory_tracking_mode ?? ''"
                @change="
                    refresh({
                        inventory_tracking_mode:
                            ($event.target as HTMLSelectElement).value ||
                            undefined,
                    })
                "
            >
                <option value="">Any inventory mode</option>
                <option
                    v-for="mode in inventoryModes"
                    :key="mode.value"
                    :value="mode.value"
                >
                    {{ mode.label }}
                </option>
            </select>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-muted/50">
                    <tr>
                        <th class="px-3 py-2 font-medium">Name</th>
                        <th class="px-3 py-2 font-medium">Classification</th>
                        <th class="px-3 py-2 font-medium">SKU</th>
                        <th class="px-3 py-2 font-medium">Family</th>
                        <th class="px-3 py-2 font-medium">Unit</th>
                        <th class="px-3 py-2 font-medium">Selling price</th>
                        <th class="px-3 py-2 font-medium">Lead time</th>
                        <th v-if="canViewCost" class="px-3 py-2 font-medium">
                            Pricing method
                        </th>
                        <th v-if="canViewCost" class="px-3 py-2 font-medium">
                            Unit cost
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in products.data"
                        :key="item.id"
                        class="border-b hover:bg-muted/30"
                    >
                        <td class="px-3 py-2">
                            <Link
                                class="font-medium text-primary underline-offset-2 hover:underline"
                                :href="show(item.id)"
                            >
                                {{ item.display_name }}
                            </Link>
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex flex-wrap gap-1">
                                <Badge variant="secondary">
                                    {{ item.product?.item_kind_label }}
                                </Badge>
                                <Badge
                                    v-if="item.is_sellable"
                                    variant="outline"
                                >
                                    Sellable
                                </Badge>
                                <Badge
                                    v-if="item.is_purchasable"
                                    variant="outline"
                                >
                                    Purchasable
                                </Badge>
                                <Badge
                                    v-if="
                                        item.inventory_tracking_mode ===
                                        'periodic_external'
                                    "
                                    variant="outline"
                                >
                                    Periodic external
                                </Badge>
                                <Badge
                                    v-if="!item.is_available"
                                    variant="destructive"
                                >
                                    Archived
                                </Badge>
                                <Badge
                                    v-if="
                                        item.product && !item.product.is_active
                                    "
                                    variant="destructive"
                                >
                                    Master inactive
                                </Badge>
                            </div>
                        </td>
                        <td class="px-3 py-2">{{ item.product?.sku }}</td>
                        <td class="px-3 py-2 capitalize">
                            {{ item.product?.product_family }}
                        </td>
                        <td class="px-3 py-2">
                            {{ item.product?.unit_of_measure }}
                        </td>
                        <td class="px-3 py-2">
                            {{ item.unit_selling_price ?? '—' }}
                        </td>
                        <td class="px-3 py-2">
                            {{ item.lead_time_days ?? '—' }}
                        </td>
                        <td v-if="canViewCost" class="px-3 py-2">
                            {{ item.pricing_method }}
                        </td>
                        <td v-if="canViewCost" class="px-3 py-2">
                            {{ item.unit_cost ?? '—' }}
                        </td>
                    </tr>
                    <tr v-if="products.data.length === 0">
                        <td
                            class="px-3 py-8 text-center text-muted-foreground"
                            :colspan="canViewCost ? 9 : 7"
                        >
                            No organization products yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
