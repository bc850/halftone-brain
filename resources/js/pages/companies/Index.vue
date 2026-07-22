<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes';
import { create, index, show } from '@/routes/companies';

type Company = {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    owner?: { id: number; name: string } | null;
    contacts_count: number;
    deals_count: number;
};

type PaginatedCompanies = {
    data: Company[];
    links: { url: string | null; label: string; active: boolean }[];
};

defineProps<{
    companies: PaginatedCompanies;
    filters: { search: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Companies', href: index() },
        ],
    },
});

function onSearch(event: Event) {
    const value = (event.target as HTMLInputElement).value;
    router.get(
        index.url({ query: { search: value || undefined } }),
        {},
        { preserveState: true, replace: true },
    );
}
</script>

<template>
    <Head title="Companies" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <Heading
                title="Companies"
                description="Manage customer companies and accounts"
            />
            <Button as-child>
                <Link :href="create()">
                    <Plus class="size-4" />
                    New company
                </Link>
            </Button>
        </div>

        <Input
            :default-value="filters.search"
            placeholder="Search companies..."
            class="max-w-sm"
            @change="onSearch"
        />

        <div class="overflow-hidden rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Owner</th>
                        <th class="px-4 py-3 font-medium">Contacts</th>
                        <th class="px-4 py-3 font-medium">Deals</th>
                        <th class="px-4 py-3 font-medium">Phone</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="company in companies.data"
                        :key="company.id"
                        class="border-t hover:bg-muted/30"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="show(company.id)"
                                class="font-medium text-foreground hover:underline"
                            >
                                {{ company.name }}
                            </Link>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ company.owner?.name ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ company.contacts_count }}
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ company.deals_count }}
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ company.phone ?? '—' }}
                        </td>
                    </tr>
                    <tr v-if="companies.data.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            No companies yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
