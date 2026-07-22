<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTenantRoute } from '@/composables/useTenantAction';
import { dashboard } from '@/routes';
import {
    create as legacyCreate,
    index as legacyIndex,
    show as legacyShow,
} from '@/routes/contacts';
import { create as legacyCreateDeal } from '@/routes/deals';
import {
    create as orgCreate,
    index as orgIndex,
    show as orgShow,
} from '@/routes/org/contacts';
import { create as orgCreateDeal } from '@/routes/org/deals';

const create = useTenantRoute(legacyCreate, orgCreate);
const index = useTenantRoute(legacyIndex, orgIndex);
const show = useTenantRoute(legacyShow, orgShow);
const createDeal = useTenantRoute(legacyCreateDeal, orgCreateDeal);

type Contact = {
    id: number;
    first_name: string;
    last_name: string;
    email: string | null;
    phone: string | null;
    title: string | null;
    company?: { id: number; name: string } | null;
};

type PaginatedContacts = {
    data: Contact[];
};

defineProps<{
    contacts: PaginatedContacts;
    filters: { search: string };
}>();

function onSearch(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    router.get(
        index.url({ query: { search: value || undefined } }),
        {},
        { preserveState: true, replace: true },
    );
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Contacts', href: legacyIndex() },
        ],
    },
});
</script>

<template>
    <Head title="Contacts" />

    <div class="flex flex-col gap-6 p-4">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <Heading
                title="Contacts"
                description="People at your customer companies"
            />
            <Button as-child>
                <Link :href="create()">
                    <Plus class="size-4" />
                    New contact
                </Link>
            </Button>
        </div>

        <Input
            :default-value="filters.search"
            placeholder="Search contacts..."
            class="max-w-sm"
            @change="onSearch"
        />

        <div class="overflow-hidden rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Company</th>
                        <th class="px-4 py-3 font-medium">Email</th>
                        <th class="px-4 py-3 font-medium">Phone</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="contact in contacts.data"
                        :key="contact.id"
                        class="border-t hover:bg-muted/30"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="show(contact.id)"
                                class="font-medium hover:underline"
                            >
                                {{ contact.first_name }} {{ contact.last_name }}
                            </Link>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ contact.company?.name ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ contact.email ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ contact.phone ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Button
                                v-if="contact.company"
                                variant="outline"
                                size="sm"
                                as-child
                            >
                                <Link
                                    :href="
                                        createDeal({
                                            query: {
                                                company_id: contact.company.id,
                                                contact_id: contact.id,
                                            },
                                        })
                                    "
                                >
                                    <Plus class="size-3.5" />
                                    Deal
                                </Link>
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="contacts.data.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            No contacts yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
