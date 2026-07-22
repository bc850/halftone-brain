<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import ProductCategoryController from '@/actions/App/Http/Controllers/ProductCategoryController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/categories';

type Category = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    sort_order: number;
};

defineProps<{
    category: Category;
}>();

const textareaClass =
    'border-input bg-transparent dark:bg-input/30 min-h-24 w-full rounded-md border px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Categories', href: index() },
            { title: 'Edit', href: index() },
        ],
    },
});
</script>

<template>
    <Head :title="`Edit ${category.name}`" />

    <div class="flex flex-col gap-6 p-4">
        <Heading :title="`Edit ${category.name}`" />

        <Form
            v-bind="ProductCategoryController.update.form(category.id)"
            class="mx-auto grid w-full max-w-xl gap-4"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" name="name" :default-value="category.name" required />
                <InputError :message="errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="slug">Slug</Label>
                <Input id="slug" name="slug" :default-value="category.slug" />
                <InputError :message="errors.slug" />
            </div>
            <div class="grid gap-2">
                <Label for="sort_order">Sort order</Label>
                <Input
                    id="sort_order"
                    name="sort_order"
                    type="number"
                    min="0"
                    :default-value="category.sort_order"
                />
            </div>
            <div class="grid gap-2">
                <Label for="description">Description</Label>
                <textarea id="description" name="description" :class="textareaClass">{{
                    category.description ?? ''
                }}</textarea>
            </div>
            <div class="flex gap-3">
                <Button type="submit" :disabled="processing">Save changes</Button>
                <Button variant="outline" as-child>
                    <Link :href="show(category.id)">Cancel</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
