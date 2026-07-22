<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import ContactController from '@/actions/App/Http/Controllers/ContactController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { create, index } from '@/routes/contacts';

type CompanyOption = { id: number; name: string };

defineProps<{
    companies: CompanyOption[];
    selectedCompanyId: number | null;
}>();

const fieldClass =
    'border-input bg-transparent dark:bg-input/30 h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]';
const textareaClass =
    'border-input bg-transparent dark:bg-input/30 min-h-24 w-full rounded-md border px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Contacts', href: index() },
            { title: 'New', href: create() },
        ],
    },
});
</script>

<template>
    <Head title="New contact" />

    <div class="flex flex-col gap-6 p-4">
        <Heading title="New contact" description="Add a person to a company" />

        <Form
            v-bind="ContactController.store.form()"
            class="mx-auto grid w-full max-w-xl gap-4"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="company_id">Company</Label>
                <select
                    id="company_id"
                    name="company_id"
                    :class="fieldClass"
                    :value="selectedCompanyId ?? ''"
                    required
                >
                    <option value="">Select company</option>
                    <option
                        v-for="company in companies"
                        :key="company.id"
                        :value="company.id"
                    >
                        {{ company.name }}
                    </option>
                </select>
                <InputError :message="errors.company_id" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="first_name">First name</Label>
                    <Input id="first_name" name="first_name" required />
                    <InputError :message="errors.first_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="last_name">Last name</Label>
                    <Input id="last_name" name="last_name" required />
                    <InputError :message="errors.last_name" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="title">Title</Label>
                <Input id="title" name="title" />
                <InputError :message="errors.title" />
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
                    <InputError :message="errors.phone" />
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_primary" value="1" class="size-4 rounded border" />
                Primary contact
            </label>

            <div class="grid gap-2">
                <Label for="notes">Notes</Label>
                <textarea id="notes" name="notes" :class="textareaClass" />
            </div>

            <div class="flex gap-3">
                <Button type="submit" :disabled="processing">Create contact</Button>
                <Button variant="outline" as-child>
                    <Link :href="index()">Cancel</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
