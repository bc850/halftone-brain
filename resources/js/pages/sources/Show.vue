<script setup lang="ts">
import { Form, Head, Link, router, usePage } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import {
    activate,
    deactivate,
    prefer,
    updatePrice,
} from '@/routes/org/products/sources';
import type { Tenant } from '@/types';

type Source = {
    id: number;
    currency_code: string;
    price_version: number;
    is_active: boolean;
    is_preferred: boolean;
    current_package_price?: string | null;
    effective_purchase_unit_cost?: string | null;
    last_price_update_at?: string | null;
    offering: {
        id: number;
        vendor_sku: string;
        vendor_description: string | null;
        purchase_uom_label: string;
        package_quantity: string;
        status_label: string;
        vendor: { id: number; name: string } | null;
    } | null;
};

type PriceEvent = {
    id: number;
    package_price: string;
    effective_purchase_unit_cost: string;
    currency_code: string;
    note: string | null;
    recorded_at: string | null;
};

const props = defineProps<{
    organizationProduct: {
        id: number;
        preferred_source_id: number | null;
        purchase_unit_of_measure: string | null;
        is_purchasable: boolean;
    };
    source: Source;
    priceEvents: PriceEvent[];
    canUpdatePrice: boolean;
    canActivate: boolean;
    canDeactivate: boolean;
    canSelectPreferred: boolean;
    canClearPreferred: boolean;
    canViewCost: boolean;
    returnUrl: string;
}>();

const {
    organizationProduct,
    source,
    priceEvents,
    canUpdatePrice,
    canActivate,
    canDeactivate,
    canSelectPreferred,
    canViewCost,
    returnUrl,
} = props;

const slug = (usePage().props.tenant as Tenant | null | undefined)?.organization
    ?.slug;

const fieldClass =
    'border-input bg-transparent dark:bg-input/30 h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs outline-none';

function postAction(url: string): void {
    router.post(url);
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Vendor source', href: dashboard() },
        ],
    },
});
</script>

<template>
    <Head title="Vendor source" />

    <div class="mx-auto flex max-w-3xl flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Organization vendor source"
                description="Organization-specific packaging price for a shared vendor offering."
            />
            <Button variant="outline" as-child>
                <Link :href="returnUrl">Back to product</Link>
            </Button>
        </div>

        <div
            class="space-y-2 rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm text-sky-950 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-100"
        >
            <p>
                Shared vendor offering:
                {{ source.offering?.vendor?.name }} /
                {{ source.offering?.vendor_sku }}
            </p>
            <p>
                Selecting a preferred source updates this organization’s
                effective material cost. It does not order material or change
                inventory.
            </p>
        </div>

        <div class="grid gap-3 text-sm sm:grid-cols-2">
            <div>
                <p class="text-muted-foreground">Vendor</p>
                <p class="font-medium">
                    {{ source.offering?.vendor?.name ?? '—' }}
                </p>
            </div>
            <div>
                <p class="text-muted-foreground">Vendor SKU</p>
                <p class="font-mono">
                    {{ source.offering?.vendor_sku ?? '—' }}
                </p>
            </div>
            <div>
                <p class="text-muted-foreground">Package</p>
                <p>
                    {{ source.offering?.package_quantity }}
                    {{ source.offering?.purchase_uom_label }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Badge :variant="source.is_active ? 'default' : 'secondary'">
                    {{ source.is_active ? 'Active' : 'Inactive' }}
                </Badge>
                <Badge v-if="source.is_preferred" variant="outline"
                    >Preferred</Badge
                >
            </div>
            <template v-if="canViewCost">
                <div>
                    <p class="text-muted-foreground">Package price</p>
                    <p class="font-medium">
                        {{
                            source.current_package_price
                                ? `$${source.current_package_price}`
                                : '—'
                        }}
                    </p>
                </div>
                <div>
                    <p class="text-muted-foreground">
                        Effective cost / purchase UOM
                    </p>
                    <p class="font-medium">
                        {{
                            source.effective_purchase_unit_cost
                                ? `$${source.effective_purchase_unit_cost}`
                                : '—'
                        }}
                    </p>
                </div>
                <div>
                    <p class="text-muted-foreground">Price version</p>
                    <p>{{ source.price_version }}</p>
                </div>
                <div>
                    <p class="text-muted-foreground">Last price update</p>
                    <p>{{ source.last_price_update_at ?? '—' }}</p>
                </div>
            </template>
        </div>

        <div class="flex flex-wrap gap-2">
            <Button
                v-if="
                    canSelectPreferred &&
                    source.is_active &&
                    !source.is_preferred &&
                    slug
                "
                type="button"
                @click="
                    postAction(
                        prefer.url([slug, organizationProduct.id, source.id]),
                    )
                "
            >
                Select as preferred
            </Button>
            <Button
                v-if="canActivate && !source.is_active && slug"
                type="button"
                variant="secondary"
                @click="
                    postAction(
                        activate.url([slug, organizationProduct.id, source.id]),
                    )
                "
            >
                Reactivate
            </Button>
            <Button
                v-if="
                    canDeactivate &&
                    source.is_active &&
                    !source.is_preferred &&
                    slug
                "
                type="button"
                variant="outline"
                @click="
                    postAction(
                        deactivate.url([
                            slug,
                            organizationProduct.id,
                            source.id,
                        ]),
                    )
                "
            >
                Deactivate
            </Button>
        </div>

        <Form
            v-if="canUpdatePrice && slug"
            v-bind="updatePrice.form([slug, organizationProduct.id, source.id])"
            class="space-y-4 rounded-lg border p-4"
            v-slot="{ errors, processing }"
        >
            <h2 class="font-medium">Set / update package price</h2>
            <input
                type="hidden"
                name="expected_price_version"
                :value="source.price_version"
            />
            <div class="space-y-2">
                <Label for="package_price">Package price</Label>
                <Input
                    id="package_price"
                    name="package_price"
                    type="text"
                    inputmode="decimal"
                    :default-value="source.current_package_price ?? ''"
                    required
                    :class="fieldClass"
                />
                <InputError :message="errors.package_price" />
                <InputError :message="errors.expected_price_version" />
            </div>
            <div class="space-y-2">
                <Label for="note">Reason / note (optional)</Label>
                <Input id="note" name="note" type="text" :class="fieldClass" />
                <InputError :message="errors.note" />
            </div>
            <Button type="submit" :disabled="processing">Save price</Button>
        </Form>

        <div v-if="canViewCost" class="space-y-3">
            <h2 class="font-medium">Price history</h2>
            <p
                v-if="priceEvents.length === 0"
                class="text-sm text-muted-foreground"
            >
                No price events yet.
            </p>
            <div
                v-for="event in priceEvents"
                :key="event.id"
                class="rounded-md border px-3 py-2 text-sm"
            >
                <p>
                    Package ${{ event.package_price }} → effective ${{
                        event.effective_purchase_unit_cost
                    }}
                    / purchase UOM
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ event.recorded_at ?? '—' }}
                    <span v-if="event.note"> · {{ event.note }}</span>
                </p>
            </div>
        </div>
    </div>
</template>
