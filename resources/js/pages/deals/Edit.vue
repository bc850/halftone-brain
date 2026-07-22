<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import DealController from '@/actions/App/Http/Controllers/DealController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { edit, index, show } from '@/routes/deals';

type Option = { value: string; label: string };
type Person = { id: number; name: string };
type CompanyOption = { id: number; name: string };
type ContactOption = {
    id: number;
    first_name: string;
    last_name: string;
    company_id: number;
};

type Deal = {
    id: number;
    name: string;
    company_id: number;
    primary_contact_id: number | null;
    owner_id: number;
    stage: string;
    amount: string | null;
    expected_close_date: string | null;
    notes: string | null;
    contact_ids: number[];
};

const props = defineProps<{
    deal: Deal;
    companies: CompanyOption[];
    contacts: ContactOption[];
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
        edit.url(props.deal.id, { query: { company_id: value || undefined } }),
        {},
        { preserveState: true, replace: true },
    );
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Deals', href: index() },
            { title: 'Edit', href: index() },
        ],
    },
});
</script>

<template>
    <Head :title="`Edit ${deal.name}`" />

    <div class="flex flex-col gap-6 p-4">
        <Heading :title="`Edit ${deal.name}`" description="Update opportunity details" />

        <Form
            v-bind="DealController.update.form(deal.id)"
            class="mx-auto grid w-full max-w-xl gap-4"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Deal name</Label>
                <Input id="name" name="name" :default-value="deal.name" required />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="company_id">Company</Label>
                <select
                    id="company_id"
                    name="company_id"
                    :class="fieldClass"
                    :value="deal.company_id"
                    required
                    @change="onCompanyChange"
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

            <div class="grid gap-2">
                <Label for="primary_contact_id">Primary contact</Label>
                <select
                    id="primary_contact_id"
                    name="primary_contact_id"
                    :class="fieldClass"
                    :value="deal.primary_contact_id ?? ''"
                >
                    <option value="">None</option>
                    <option v-for="contact in contacts" :key="contact.id" :value="contact.id">
                        {{ contact.first_name }} {{ contact.last_name }}
                    </option>
                </select>
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
                            :checked="deal.contact_ids.includes(contact.id)"
                        />
                        {{ contact.first_name }} {{ contact.last_name }}
                    </label>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="stage">Stage</Label>
                    <select id="stage" name="stage" :class="fieldClass" :value="deal.stage" required>
                        <option
                            v-for="stage in stages"
                            :key="stage.value"
                            :value="stage.value"
                        >
                            {{ stage.label }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label for="amount">Amount</Label>
                    <Input
                        id="amount"
                        name="amount"
                        type="number"
                        step="0.01"
                        min="0"
                        :default-value="deal.amount ?? ''"
                    />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="expected_close_date">Expected close</Label>
                    <Input
                        id="expected_close_date"
                        name="expected_close_date"
                        type="date"
                        :default-value="deal.expected_close_date?.slice(0, 10) ?? ''"
                    />
                </div>
                <div v-if="salespeople.length" class="grid gap-2">
                    <Label for="owner_id">Owner</Label>
                    <select
                        id="owner_id"
                        name="owner_id"
                        :class="fieldClass"
                        :value="deal.owner_id"
                    >
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
                <textarea id="notes" name="notes" :class="textareaClass">{{
                    deal.notes ?? ''
                }}</textarea>
            </div>

            <div class="flex gap-3">
                <Button type="submit" :disabled="processing">Save changes</Button>
                <Button variant="outline" as-child>
                    <Link :href="show(deal.id)">Cancel</Link>
                </Button>
            </div>
        </Form>
    </div>
</template>
