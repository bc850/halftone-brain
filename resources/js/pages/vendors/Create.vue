<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import VendorController from '@/actions/App/Http/Controllers/VendorController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { create, index } from '@/routes/vendors';

const textareaClass =
    'border-input bg-transparent dark:bg-input/30 min-h-24 w-full rounded-md border px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Vendors', href: index() },
            { title: 'New', href: create() },
        ],
    },
});
</script>

<template>
    <Head title="New vendor" />

    <div class="flex flex-col gap-6 p-4">
        <Heading title="New vendor" description="Add a supplier" />

        <Form
            v-bind="VendorController.store.form()"
            class="mx-auto grid w-full max-w-xl gap-4"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" name="name" required />
                <InputError :message="errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="account_number">Account number</Label>
                <Input id="account_number" name="account_number" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="email">Email</Label>
                    <Input id="email" name="email" type="email" />
                    <InputError :message="errors.email" />
                </div>
                <div class="grid gap-2">
                    <Label for="phone">Phone</Label>
                    <Input id="phone" name="phone" />
                </div>
            </div>
            <div class="grid gap-2">
                <Label for="website">Website</Label>
                <Input id="website" name="website" type="url" placeholder="https://" />
                <InputError :message="errors.website" />
            </div>
            <div class="grid gap-2">
                <Label for="notes">Notes</Label>
                <textarea id="notes" name="notes" :class="textareaClass" />
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1" class="size-4 rounded border" checked />
                Active
            </label>
            <div class="flex gap-3">
                <Button type="submit" :disabled="processing">Create vendor</Button>
                <Button variant="outline" as-child>
                    <Link :href="index()">Cancel</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
