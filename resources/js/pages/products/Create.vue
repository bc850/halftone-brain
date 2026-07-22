<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ProductController from '@/actions/App/Http/Controllers/ProductController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { create, index } from '@/routes/products';

type Option = { id: number; name: string };
type Unit = { value: string; label: string };
type RelatedOption = { id: number; name: string; sku: string };

defineProps<{
    vendors: Option[];
    categories: Option[];
    units: Unit[];
    relatedOptions: RelatedOption[];
}>();

const trueCost = ref('0');
const markupPercent = ref('50');

const suggested = computed(() => {
    const cost = Number(trueCost.value) || 0;
    const markup = Number(markupPercent.value) || 0;
    return (cost * (1 + markup / 100)).toFixed(2);
});

const fieldClass =
    'border-input bg-transparent dark:bg-input/30 h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]';
const textareaClass =
    'border-input bg-transparent dark:bg-input/30 min-h-24 w-full rounded-md border px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Products', href: index() },
            { title: 'New', href: create() },
        ],
    },
});
</script>

<template>
    <Head title="New product" />

    <div class="flex flex-col gap-6 p-4">
        <Heading title="New product" description="Fixed true cost + markup sets suggested sell" />

        <Form
            v-bind="ProductController.store.form()"
            class="mx-auto grid w-full max-w-3xl gap-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="name">Name</Label>
                    <Input id="name" name="name" required />
                    <InputError :message="errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="sku">Product SKU</Label>
                    <Input id="sku" name="sku" required />
                    <InputError :message="errors.sku" />
                </div>
                <div class="grid gap-2">
                    <Label for="vendor_sku">Vendor SKU</Label>
                    <Input id="vendor_sku" name="vendor_sku" />
                </div>
                <div class="grid gap-2">
                    <Label for="vendor_id">Vendor</Label>
                    <select id="vendor_id" name="vendor_id" :class="fieldClass">
                        <option value="">None</option>
                        <option v-for="vendor in vendors" :key="vendor.id" :value="vendor.id">
                            {{ vendor.name }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label for="product_category_id">Category</Label>
                    <select id="product_category_id" name="product_category_id" :class="fieldClass">
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
                    <Label for="unit_of_measure">Unit of measure</Label>
                    <select id="unit_of_measure" name="unit_of_measure" :class="fieldClass" required>
                        <option v-for="unit in units" :key="unit.value" :value="unit.value">
                            {{ unit.label }}
                        </option>
                    </select>
                </div>
            </div>

            <div class="grid gap-4 rounded-xl border p-4 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="true_cost">True cost</Label>
                    <Input
                        id="true_cost"
                        name="true_cost"
                        type="number"
                        step="0.0001"
                        min="0"
                        required
                        :model-value="trueCost"
                        @update:model-value="trueCost = String($event)"
                    />
                    <InputError :message="errors.true_cost" />
                </div>
                <div class="grid gap-2">
                    <Label for="markup_percent">Markup %</Label>
                    <Input
                        id="markup_percent"
                        name="markup_percent"
                        type="number"
                        step="0.01"
                        min="0"
                        required
                        :model-value="markupPercent"
                        @update:model-value="markupPercent = String($event)"
                    />
                    <InputError :message="errors.markup_percent" />
                </div>
                <div class="grid gap-2">
                    <Label for="list_price">List price (optional)</Label>
                    <Input
                        id="list_price"
                        name="list_price"
                        type="number"
                        step="0.01"
                        min="0"
                        :placeholder="suggested"
                    />
                    <p class="text-xs text-muted-foreground">
                        Suggested from cost + markup: ${{ suggested }}
                    </p>
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="description">Description</Label>
                <textarea id="description" name="description" :class="textareaClass" />
            </div>
            <div class="grid gap-2">
                <Label for="notes">Notes</Label>
                <textarea id="notes" name="notes" :class="textareaClass" />
            </div>

            <div v-if="relatedOptions.length" class="grid gap-2">
                <Label>Related products</Label>
                <div class="max-h-48 space-y-2 overflow-y-auto rounded-md border p-3">
                    <label
                        v-for="option in relatedOptions"
                        :key="option.id"
                        class="flex items-center gap-2 text-sm"
                    >
                        <input
                            type="checkbox"
                            name="related_product_ids[]"
                            :value="option.id"
                            class="size-4 rounded border"
                        />
                        {{ option.name }}
                        <span class="text-muted-foreground">({{ option.sku }})</span>
                    </label>
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1" class="size-4 rounded border" checked />
                Active
            </label>

            <div class="flex gap-3">
                <Button type="submit" :disabled="processing">Create product</Button>
                <Button variant="outline" as-child>
                    <Link :href="index()">Cancel</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
