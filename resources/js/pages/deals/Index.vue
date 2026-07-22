<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import DealController from '@/actions/App/Http/Controllers/DealController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes';
import { create, index, show } from '@/routes/deals';

type DealCard = {
    id: number;
    name: string;
    stage: string;
    amount: string | null;
    company?: { id: number; name: string } | null;
    owner?: { id: number; name: string } | null;
    primary_contact?: { id: number; first_name: string; last_name: string } | null;
    can_update: boolean;
    can_delete: boolean;
};

type Column = {
    stage: string;
    label: string;
    deals: DealCard[];
};

type StageOption = { value: string; label: string };

defineProps<{
    columns: Column[];
    filters: { search: string };
    stages: StageOption[];
    canCreate: boolean;
}>();

const fieldClass =
    'border-input bg-transparent dark:bg-input/30 h-8 w-full rounded-md border px-2 text-xs outline-none';

function onSearch(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    router.get(
        index.url({ query: { search: value || undefined } }),
        {},
        { preserveState: true, replace: true },
    );
}

function moveDeal(dealId: number, stage: string): void {
    router.patch(
        DealController.updateStage.url(dealId),
        { stage },
        { preserveScroll: true },
    );
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Deals', href: index() },
        ],
    },
});
</script>

<template>
    <Head title="Deals" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <Heading title="Deals" description="Pipeline board for opportunities" />
            <Button v-if="canCreate" as-child>
                <Link :href="create()">
                    <Plus class="size-4" />
                    New deal
                </Link>
            </Button>
        </div>

        <Input
            :default-value="filters.search"
            placeholder="Search deals..."
            class="max-w-sm"
            @change="onSearch"
        />

        <div class="flex gap-4 overflow-x-auto pb-2">
            <section
                v-for="column in columns"
                :key="column.stage"
                class="flex w-72 shrink-0 flex-col gap-3 rounded-xl border bg-muted/20 p-3"
            >
                <header class="flex items-center justify-between gap-2 px-1">
                    <h2 class="text-sm font-medium">{{ column.label }}</h2>
                    <span class="text-xs text-muted-foreground">{{ column.deals.length }}</span>
                </header>

                <div class="flex flex-col gap-2">
                    <article
                        v-for="deal in column.deals"
                        :key="deal.id"
                        class="rounded-lg border bg-background p-3 shadow-xs"
                    >
                        <Link
                            :href="show(deal.id)"
                            class="block text-sm font-medium hover:underline"
                        >
                            {{ deal.name }}
                        </Link>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ deal.company?.name ?? '—' }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ deal.amount ? `$${deal.amount}` : 'No amount' }}
                        </p>
                        <select
                            v-if="deal.can_update"
                            :class="fieldClass"
                            class="mt-3"
                            :value="deal.stage"
                            @change="
                                moveDeal(
                                    deal.id,
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option
                                v-for="stage in stages"
                                :key="stage.value"
                                :value="stage.value"
                            >
                                {{ stage.label }}
                            </option>
                        </select>
                    </article>

                    <p
                        v-if="column.deals.length === 0"
                        class="px-1 py-6 text-center text-xs text-muted-foreground"
                    >
                        Empty
                    </p>
                </div>
            </section>
        </div>
    </div>
</template>
