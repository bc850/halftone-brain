<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTenantRoute } from '@/composables/useTenantAction';
import { dashboard } from '@/routes';
import { show as orgShow, updateMaster } from '@/routes/org/products';
import { index as legacyIndex, show as legacyShow } from '@/routes/products';
import type { Tenant } from '@/types';

const show = useTenantRoute(legacyShow, orgShow);

type Option = { id: number; name: string };
type SelectOption = { value: string; label: string };

type OrganizationProduct = {
    id: number;
    display_name: string;
    product: {
        id: number;
        name: string;
        sku: string;
        product_family: string;
        item_kind: string;
        unit_of_measure: string;
        description: string | null;
        is_active: boolean;
        vendor_sku?: string | null;
        notes?: string | null;
        category?: { id: number; name: string } | null;
    } | null;
};

const props = defineProps<{
    product: OrganizationProduct;
    associatedOrganizationCount: number;
    categories: Option[];
    units: SelectOption[];
    families: SelectOption[];
    itemKinds: SelectOption[];
}>();

const slug = (usePage().props.tenant as Tenant | null | undefined)?.organization
    ?.slug;

const master = props.product.product;

const fieldClass =
    'border-input bg-transparent dark:bg-input/30 h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs outline-none';
const textareaClass =
    'border-input bg-transparent dark:bg-input/30 min-h-24 w-full rounded-md border px-3 py-2 text-sm shadow-xs outline-none';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Products', href: legacyIndex() },
            { title: 'Edit master', href: legacyIndex() },
        ],
    },
});
</script>

<template>
    <Head :title="`Edit master · ${product.display_name}`" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Edit shared product information"
            :description="product.display_name"
        />

        <div class="mx-auto w-full max-w-3xl space-y-4">
            <p
                class="rounded-lg border bg-muted/40 p-4 text-sm text-muted-foreground"
            >
                This product master is shared across organizations in your
                parent account. Changes here apply everywhere this master is
                used, including Pelican Signs and Brim Drinkware.
            </p>

            <p
                v-if="associatedOrganizationCount > 1"
                class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100"
            >
                This master is associated with
                {{ associatedOrganizationCount }} organizations. Editing shared
                master fields does not change organization-specific settings
                (availability, sellable/purchasable flags, units, or pricing)
                for other organizations.
            </p>
        </div>

        <Form
            v-if="slug"
            v-bind="updateMaster.form([slug, product.id])"
            class="mx-auto grid w-full max-w-3xl gap-8"
            v-slot="{ errors, processing }"
        >
            <section class="grid gap-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="name">Name</Label>
                        <Input
                            id="name"
                            name="name"
                            :default-value="master?.name ?? ''"
                            required
                        />
                        <InputError :message="errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="sku">SKU</Label>
                        <Input
                            id="sku"
                            name="sku"
                            :default-value="master?.sku ?? ''"
                            required
                        />
                        <InputError :message="errors.sku" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="item_kind">Item kind</Label>
                        <select
                            id="item_kind"
                            name="item_kind"
                            :class="fieldClass"
                            :value="master?.item_kind ?? 'product'"
                            required
                        >
                            <option
                                v-for="kind in itemKinds"
                                :key="kind.value"
                                :value="kind.value"
                            >
                                {{ kind.label }}
                            </option>
                        </select>
                        <InputError :message="errors.item_kind" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="product_family">Product family</Label>
                        <select
                            id="product_family"
                            name="product_family"
                            :class="fieldClass"
                            :value="master?.product_family ?? ''"
                            required
                        >
                            <option
                                v-for="family in families"
                                :key="family.value"
                                :value="family.value"
                            >
                                {{ family.label }}
                            </option>
                        </select>
                        <InputError :message="errors.product_family" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="unit_of_measure">Unit of measure</Label>
                        <select
                            id="unit_of_measure"
                            name="unit_of_measure"
                            :class="fieldClass"
                            :value="master?.unit_of_measure ?? ''"
                            required
                        >
                            <option
                                v-for="unit in units"
                                :key="unit.value"
                                :value="unit.value"
                            >
                                {{ unit.label }}
                            </option>
                        </select>
                        <InputError :message="errors.unit_of_measure" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="product_category_id">Category</Label>
                        <select
                            id="product_category_id"
                            name="product_category_id"
                            :class="fieldClass"
                            :value="master?.category?.id ?? ''"
                        >
                            <option value="">None</option>
                            <option
                                v-for="category in categories"
                                :key="category.id"
                                :value="category.id"
                            >
                                {{ category.name }}
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="vendor_sku">Vendor SKU</Label>
                        <Input
                            id="vendor_sku"
                            name="vendor_sku"
                            :default-value="master?.vendor_sku ?? ''"
                        />
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="description">Description</Label>
                        <textarea
                            id="description"
                            name="description"
                            :class="textareaClass"
                            :value="master?.description ?? ''"
                        />
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="notes">Shared notes</Label>
                        <textarea
                            id="notes"
                            name="notes"
                            :class="textareaClass"
                            :value="master?.notes ?? ''"
                        />
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            name="is_active"
                            value="1"
                            :checked="master?.is_active ?? true"
                        />
                        Master is active
                    </label>
                </div>
            </section>

            <div class="flex gap-3">
                <Button type="submit" :disabled="processing">
                    Save changes
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="show(product.id)">Cancel</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
