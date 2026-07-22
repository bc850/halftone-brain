<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import ContactController from '@/actions/App/Http/Controllers/ContactController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { edit, index, show } from '@/routes/contacts';

type CompanyOption = { id: number; name: string };

type Contact = {
    id: number;
    company_id: number;
    first_name: string;
    last_name: string;
    email: string | null;
    phone: string | null;
    title: string | null;
    is_primary: boolean;
    notes: string | null;
};

const props = defineProps<{
    contact: Contact;
    companies: CompanyOption[];
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
            { title: 'Edit', href: index() },
        ],
    },
});
</script>

<template>
    <Head :title="`Edit ${contact.first_name} ${contact.last_name}`" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            :title="`Edit ${contact.first_name} ${contact.last_name}`"
            description="Update contact details"
        />

        <Form
            v-bind="ContactController.update.form(contact.id)"
            class="mx-auto grid w-full max-w-xl gap-4"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="company_id">Company</Label>
                <select
                    id="company_id"
                    name="company_id"
                    :class="fieldClass"
                    :value="contact.company_id"
                    required
                >
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
                    <Input
                        id="first_name"
                        name="first_name"
                        :default-value="contact.first_name"
                        required
                    />
                    <InputError :message="errors.first_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="last_name">Last name</Label>
                    <Input
                        id="last_name"
                        name="last_name"
                        :default-value="contact.last_name"
                        required
                    />
                    <InputError :message="errors.last_name" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="title">Title</Label>
                <Input id="title" name="title" :default-value="contact.title ?? ''" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="email">Email</Label>
                    <Input
                        id="email"
                        name="email"
                        type="email"
                        :default-value="contact.email ?? ''"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="phone">Phone</Label>
                    <Input id="phone" name="phone" :default-value="contact.phone ?? ''" />
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    name="is_primary"
                    value="1"
                    class="size-4 rounded border"
                    :checked="contact.is_primary"
                />
                Primary contact
            </label>

            <div class="grid gap-2">
                <Label for="notes">Notes</Label>
                <textarea id="notes" name="notes" :class="textareaClass">{{
                    contact.notes ?? ''
                }}</textarea>
            </div>

            <div class="flex gap-3">
                <Button type="submit" :disabled="processing">Save changes</Button>
                <Button variant="outline" as-child>
                    <Link :href="show(contact.id)">Cancel</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
