<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTenantRoute } from '@/composables/useTenantAction';
import { dashboard } from '@/routes';
import {
    create as legacyCreate,
    index as legacyIndex,
    store as legacyStore,
} from '@/routes/categories';
import { index as orgIndex, store as orgStore } from '@/routes/org/categories';

const index = useTenantRoute(legacyIndex, orgIndex);
const store = useTenantRoute(legacyStore, orgStore);

const textareaClass =
    'border-input bg-transparent dark:bg-input/30 min-h-24 w-full rounded-md border px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Categories', href: legacyIndex() },
            { title: 'New', href: legacyCreate() },
        ],
    },
});
</script>

<template>
    <Head title="New category" />

    <div class="flex flex-col gap-6 p-4">
        <Heading title="New category" />

        <Form
            v-bind="store.form()"
            class="mx-auto grid w-full max-w-xl gap-4"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" name="name" required />
                <InputError :message="errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="slug">Slug (optional)</Label>
                <Input
                    id="slug"
                    name="slug"
                    placeholder="auto-generated from name"
                />
                <InputError :message="errors.slug" />
            </div>
            <div class="grid gap-2">
                <Label for="sort_order">Sort order</Label>
                <Input
                    id="sort_order"
                    name="sort_order"
                    type="number"
                    min="0"
                    default-value="0"
                />
            </div>
            <div class="grid gap-2">
                <Label for="description">Description</Label>
                <textarea
                    id="description"
                    name="description"
                    :class="textareaClass"
                />
            </div>
            <div class="flex gap-3">
                <Button type="submit" :disabled="processing"
                    >Create category</Button
                >
                <Button variant="outline" as-child>
                    <Link :href="index()">Cancel</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
