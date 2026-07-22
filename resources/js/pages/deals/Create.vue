<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import DealController from '@/actions/App/Http/Controllers/DealController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { create, index } from '@/routes/deals';

type Option = { value: string; label: string };
type Person = { id: number; name: string };
type CompanyOption = { id: number; name: string };
type ContactOption = {
    id: number;
    first_name: string;
    last_name: string;
    company_id: number;
};

const props = defineProps<{
    companies: CompanyOption[];
    contacts: ContactOption[];
    selectedCompanyId: number | null;
    selectedPrimaryContactId: number | null;
    stages: Option[];
    salespeople: Person[];
}>();

const fieldClass =
    'border-input bg-transparent dark:bg-input/30 h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]';
const textareaClass =
    'border-input bg-transparent dark:bg-input/30 min-h-24 w-full rounded-md border px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]';

function onCompanyChange(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    router.get(
        create.url({ query: { company_id: value || undefined } }),
        {},
        { preserveState: true, replace: true },
    );
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Deals', href: index() },
            { title: 'New', href: create() },
        ],
    },
});
</script>

<template>
    <Head title="New deal" />

    <div class="flex flex-col gap-6 p-4">
        <Heading title="New deal" description="Create an opportunity under a company" />

        <Form
            v-bind="DealController.store.form()"
            class="mx-auto grid w-full max-w-xl gap-4"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Deal name</Label>
                <Input id="name" name="name" required />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="company_id">Company</Label>
                <select
                    id="company_id"
                    name="company_id"
                    :class="fieldClass"
                    :value="selectedCompanyId ?? ''"
                    required
                    @change="onCompanyChange"
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

            <div class="grid gap-2">
                <Label for="primary_contact_id">Primary contact</Label>
                <select
                    id="primary_contact_id"
                    name="primary_contact_id"
                    :class="fieldClass"
                    :value="selectedPrimaryContactId ?? ''"
                    :disabled="contacts.length === 0"
                >
                    <option value="">None</option>
                    <option v-for="contact in contacts" :key="contact.id" :value="contact.id">
                        {{ contact.first_name }} {{ contact.last_name }}
                    </option>
                </select>
                <InputError :message="errors.primary_contact_id" />
            </div>

            <div v-if="contacts.length" class="grid gap-2">
                <Label>Additional contacts</Label>
                <div class="space-y-2 rounded-md border p-3">
                    <label
                        v-for="contact in contacts"
                        :key="contact.id"
                        class="flex items-center gap-2 text-sm"
                    >
                        <input
                            type="checkbox"
                            name="contact_ids[]"
                            :value="contact.id"
                            class="size-4 rounded border"
                            :checked="contact.id === selectedPrimaryContactId"
                        />
                        {{ contact.first_name }} {{ contact.last_name }}
                    </label>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="stage">Stage</Label>
                    <select id="stage" name="stage" :class="fieldClass" required>
                        <option
                            v-for="stage in stages"
                            :key="stage.value"
                            :value="stage.value"
                            :selected="stage.value === 'lead'"
                        >
                            {{ stage.label }}
                        </option>
                    </select>
                    <InputError :message="errors.stage" />
                </div>
                <div class="grid gap-2">
                    <Label for="amount">Amount</Label>
                    <Input id="amount" name="amount" type="number" step="0.01" min="0" />
                    <InputError :message="errors.amount" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="expected_close_date">Expected close</Label>
                    <Input id="expected_close_date" name="expected_close_date" type="date" />
                </div>
                <div v-if="salespeople.length" class="grid gap-2">
                    <Label for="owner_id">Owner</Label>
                    <select id="owner_id" name="owner_id" :class="fieldClass">
                        <option value="">Assign to me</option>
                        <option
                            v-for="person in salespeople"
                            :key="person.id"
                            :value="person.id"
                        >
                            {{ person.name }}
                        </option>
                    </select>
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="notes">Notes</Label>
                <textarea id="notes" name="notes" :class="textareaClass" />
            </div>

            <div class="flex gap-3">
                <Button type="submit" :disabled="processing">Create deal</Button>
                <Button variant="outline" as-child>
                    <Link :href="index()">Cancel</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
