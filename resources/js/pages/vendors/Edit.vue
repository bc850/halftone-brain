<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTenantRoute } from '@/composables/useTenantAction';
import { dashboard } from '@/routes';
import { show as orgShow, update as orgUpdate } from '@/routes/org/vendors';
import {
    index as legacyIndex,
    show as legacyShow,
    update as legacyUpdate,
} from '@/routes/vendors';

const show = useTenantRoute(legacyShow, orgShow);
const update = useTenantRoute(legacyUpdate, orgUpdate);

type Vendor = {
    id: number;
    name: string;
    account_number: string | null;
    email: string | null;
    phone: string | null;
    website: string | null;
    notes: string | null;
    is_active: boolean;
};

defineProps<{
    vendor: Vendor;
}>();

const textareaClass =
    'border-input bg-transparent dark:bg-input/30 min-h-24 w-full rounded-md border px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Vendors', href: legacyIndex() },
            { title: 'Edit', href: legacyIndex() },
        ],
    },
});
</script>

<template>
    <Head :title="`Edit ${vendor.name}`" />

    <div class="flex flex-col gap-6 p-4">
        <Heading :title="`Edit ${vendor.name}`" />

        <Form
            v-bind="update.form(vendor.id)"
            class="mx-auto grid w-full max-w-xl gap-4"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    name="name"
                    :default-value="vendor.name"
                    required
                />
                <InputError :message="errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="account_number">Account number</Label>
                <Input
                    id="account_number"
                    name="account_number"
                    :default-value="vendor.account_number ?? ''"
                />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="email">Email</Label>
                    <Input
                        id="email"
                        name="email"
                        type="email"
                        :default-value="vendor.email ?? ''"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="phone">Phone</Label>
                    <Input
                        id="phone"
                        name="phone"
                        :default-value="vendor.phone ?? ''"
                    />
                </div>
            </div>
            <div class="grid gap-2">
                <Label for="website">Website</Label>
                <Input
                    id="website"
                    name="website"
                    type="url"
                    :default-value="vendor.website ?? ''"
                />
            </div>
            <div class="grid gap-2">
                <Label for="notes">Notes</Label>
                <textarea
                    id="notes"
                    name="notes"
                    :class="textareaClass"
                    :value="vendor.notes ?? ''"
                />
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    name="is_active"
                    value="1"
                    class="size-4 rounded border"
                    :checked="vendor.is_active"
                />
                Active
            </label>
            <div class="flex gap-3">
                <Button type="submit" :disabled="processing"
                    >Save changes</Button
                >
                <Button variant="outline" as-child>
                    <Link :href="show(vendor.id)">Cancel</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
