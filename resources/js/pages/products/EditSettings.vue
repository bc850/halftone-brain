<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTenantRoute } from '@/composables/useTenantAction';
import { dashboard } from '@/routes';
import { show as orgShow, updateSettings } from '@/routes/org/products';
import { index as legacyIndex, show as legacyShow } from '@/routes/products';
import type { Tenant } from '@/types';

const show = useTenantRoute(legacyShow, orgShow);

type OrganizationProduct = {
    id: number;
    display_name: string;
    is_available: boolean;
    lead_time_days: number | null;
    organization_notes: string | null;
};

defineProps<{
    product: OrganizationProduct;
}>();

const slug = (usePage().props.tenant as Tenant | null | undefined)?.organization
    ?.slug;

const textareaClass =
    'border-input bg-transparent dark:bg-input/30 min-h-24 w-full rounded-md border px-3 py-2 text-sm shadow-xs outline-none';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Products', href: legacyIndex() },
            { title: 'Edit settings', href: legacyIndex() },
        ],
    },
});
</script>

<template>
    <Head :title="`Edit settings · ${product.display_name}`" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Edit organization settings"
            :description="product.display_name"
        />

        <Form
            v-if="slug"
            v-bind="updateSettings.form([slug, product.id])"
            class="mx-auto grid w-full max-w-3xl gap-8"
            v-slot="{ errors, processing }"
        >
            <section class="grid gap-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="display_name">Display name override</Label>
                        <Input
                            id="display_name"
                            name="display_name"
                            :default-value="product.display_name"
                        />
                        <InputError :message="errors.display_name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="lead_time_days">Lead time (days)</Label>
                        <Input
                            id="lead_time_days"
                            name="lead_time_days"
                            type="number"
                            min="0"
                            :default-value="product.lead_time_days ?? ''"
                        />
                        <InputError :message="errors.lead_time_days" />
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="notes">Organization notes</Label>
                        <textarea
                            id="notes"
                            name="notes"
                            :class="textareaClass"
                            :value="product.organization_notes ?? ''"
                        />
                        <InputError :message="errors.notes" />
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            name="is_available"
                            value="1"
                            :checked="product.is_available"
                        />
                        Available in this organization
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
