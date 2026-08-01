<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Activity } from '@lucide/vue';
import { reactive, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useOrganizationSlug } from '@/composables/useTenantAction';
import { dashboard } from '@/routes';
import outbox from '@/routes/org/integrations/outbox';
import outboxDeliveries from '@/routes/org/integrations/outbox/deliveries';
import outboxEvents from '@/routes/org/integrations/outbox/events';

type DeliveryRow = {
    id: number;
    outbox_id: number;
    business_event: string;
    quote_number: string | null;
    company_name: string | null;
    consumer_label: string;
    status: string;
    status_label: string;
    attempt_count: number;
    next_attempt_at: string | null;
    last_activity_at: string | null;
    problem_summary: string | null;
    organization: string | null;
    can_replay: boolean;
    can_abandon: boolean;
    lease_active: boolean;
    lease_expired: boolean;
};

const props = defineProps<{
    deliveries: {
        data: DeliveryRow[];
        meta: {
            current_page: number;
            last_page: number;
            per_page: number;
            total: number;
        };
    };
    filters: {
        status: string | null;
        consumer: string | null;
        event_type: string | null;
        correlation_id: string | null;
        quote_number: string | null;
        delivery_id: number | null;
        outbox_id: number | null;
        date_from: string | null;
        date_to: string | null;
        include_completed: boolean;
    };
    health: {
        waiting: number;
        processing: number;
        retrying: number;
        blocked_configuration: number;
        failed: number;
        dead: number;
        abandoned: number;
        succeeded: number;
        oldest_waiting_age_seconds: number | null;
        last_successful_delivery_at: string | null;
        active_lease_count: number;
        expired_lease_count: number;
    };
    statusOptions: { value: string; label: string }[];
    consumerOptions: { value: string; label: string }[];
    eventTypeOptions: { value: string; label: string }[];
    canReplay: boolean;
    canAbandon: boolean;
}>();

const slug = useOrganizationSlug();

const fieldClass =
    'border-input bg-transparent dark:bg-input/30 h-9 w-full rounded-md border px-3 py-1 text-sm outline-none';

const filters = reactive({
    status: props.filters.status ?? '',
    consumer: props.filters.consumer ?? '',
    event_type: props.filters.event_type ?? '',
    correlation_id: props.filters.correlation_id ?? '',
    quote_number: props.filters.quote_number ?? '',
    delivery_id: props.filters.delivery_id ?? '',
    outbox_id: props.filters.outbox_id ?? '',
    date_from: props.filters.date_from ?? '',
    date_to: props.filters.date_to ?? '',
    include_completed: props.filters.include_completed,
});

let filterTimer: ReturnType<typeof setTimeout> | undefined;

watch(
    filters,
    () => {
        clearTimeout(filterTimer);
        filterTimer = setTimeout(() => {
            router.get(
                outbox.index.url(slug),
                { ...filters },
                {
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                },
            );
        }, 250);
    },
    { deep: true },
);

function formatAge(seconds: number | null): string {
    if (seconds === null) {
        return '—';
    }

    if (seconds < 60) {
        return `${seconds}s`;
    }

    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)}m`;
    }

    return `${Math.floor(seconds / 3600)}h`;
}

function formatWhen(value: string | null): string {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleString();
}

function statusTone(status: string): 'default' | 'destructive' | 'secondary' {
    if (status === 'succeeded') {
        return 'default';
    }

    if (
        status === 'failed' ||
        status === 'dead' ||
        status === 'blocked_configuration'
    ) {
        return 'destructive';
    }

    return 'secondary';
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Integration activity', href: '#' },
        ],
    },
});
</script>

<template>
    <Head title="Integration activity" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Integration activity"
                description="Track handoffs after a quote is accepted. Waiting items need a background worker; blocked items need configuration."
            />
            <Button variant="outline" as-child>
                <Link :href="outbox.health.url(slug)">Refresh summary</Link>
            </Button>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-lg border p-4">
                <p class="text-sm text-muted-foreground">Waiting</p>
                <p class="text-2xl font-semibold">{{ health.waiting }}</p>
            </div>
            <div class="rounded-lg border p-4">
                <p class="text-sm text-muted-foreground">Processing</p>
                <p class="text-2xl font-semibold">{{ health.processing }}</p>
            </div>
            <div class="rounded-lg border p-4">
                <p class="text-sm text-muted-foreground">Retrying</p>
                <p class="text-2xl font-semibold">{{ health.retrying }}</p>
            </div>
            <div class="rounded-lg border p-4">
                <p class="text-sm text-muted-foreground">
                    Blocked by configuration
                </p>
                <p class="text-2xl font-semibold">
                    {{ health.blocked_configuration }}
                </p>
            </div>
            <div class="rounded-lg border p-4">
                <p class="text-sm text-muted-foreground">Failed</p>
                <p class="text-2xl font-semibold">{{ health.failed }}</p>
            </div>
            <div class="rounded-lg border p-4">
                <p class="text-sm text-muted-foreground">Dead</p>
                <p class="text-2xl font-semibold">{{ health.dead }}</p>
            </div>
            <div class="rounded-lg border p-4">
                <p class="text-sm text-muted-foreground">Abandoned</p>
                <p class="text-2xl font-semibold">{{ health.abandoned }}</p>
            </div>
            <div class="rounded-lg border p-4">
                <p class="text-sm text-muted-foreground">Successful</p>
                <p class="text-2xl font-semibold">{{ health.succeeded }}</p>
            </div>
            <div class="rounded-lg border p-4 sm:col-span-2">
                <p class="text-sm text-muted-foreground">Oldest waiting age</p>
                <p class="text-2xl font-semibold">
                    {{ formatAge(health.oldest_waiting_age_seconds) }}
                </p>
            </div>
            <div class="rounded-lg border p-4 sm:col-span-2">
                <p class="text-sm text-muted-foreground">
                    Last successful delivery
                </p>
                <p class="text-lg font-semibold">
                    {{ formatWhen(health.last_successful_delivery_at) }}
                </p>
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <div>
                <Label for="status">Status</Label>
                <select
                    id="status"
                    v-model="filters.status"
                    :class="fieldClass"
                >
                    <option value="">Any open status</option>
                    <option
                        v-for="option in statusOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
            </div>
            <div>
                <Label for="consumer">Consumer</Label>
                <select
                    id="consumer"
                    v-model="filters.consumer"
                    :class="fieldClass"
                >
                    <option value="">Any</option>
                    <option
                        v-for="option in consumerOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
            </div>
            <div>
                <Label for="event_type">Event</Label>
                <select
                    id="event_type"
                    v-model="filters.event_type"
                    :class="fieldClass"
                >
                    <option value="">Any</option>
                    <option
                        v-for="option in eventTypeOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
            </div>
            <div>
                <Label for="quote_number">Quote number</Label>
                <Input
                    id="quote_number"
                    v-model="filters.quote_number"
                    placeholder="PEL-Q-…"
                />
            </div>
            <div>
                <Label for="correlation_id">Correlation ID</Label>
                <Input id="correlation_id" v-model="filters.correlation_id" />
            </div>
            <div>
                <Label for="delivery_id">Delivery ID</Label>
                <Input id="delivery_id" v-model="filters.delivery_id" />
            </div>
            <div>
                <Label for="outbox_id">Event ID</Label>
                <Input id="outbox_id" v-model="filters.outbox_id" />
            </div>
            <div class="flex items-end gap-2 pb-1">
                <input
                    id="include_completed"
                    v-model="filters.include_completed"
                    type="checkbox"
                    class="size-4"
                />
                <Label for="include_completed">Include completed</Label>
            </div>
            <div>
                <Label for="date_from">From</Label>
                <Input id="date_from" v-model="filters.date_from" type="date" />
            </div>
            <div>
                <Label for="date_to">To</Label>
                <Input id="date_to" v-model="filters.date_to" type="date" />
            </div>
        </div>

        <div
            v-if="deliveries.data.length === 0"
            class="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed p-12 text-center text-muted-foreground"
        >
            <Activity class="size-8 opacity-50" />
            <p class="text-lg font-medium text-foreground">
                No integration activity yet
            </p>
            <p class="max-w-md text-sm">
                When a quote is accepted, handoff work appears here. Nothing is
                waiting right now.
            </p>
        </div>

        <div v-else class="overflow-x-auto rounded-lg border">
            <table class="w-full min-w-[960px] text-left text-sm">
                <thead class="border-b bg-muted/40">
                    <tr>
                        <th class="px-3 py-2 font-medium">Business event</th>
                        <th class="px-3 py-2 font-medium">Quote</th>
                        <th class="px-3 py-2 font-medium">Customer</th>
                        <th class="px-3 py-2 font-medium">Consumer</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                        <th class="px-3 py-2 font-medium">Attempts</th>
                        <th class="px-3 py-2 font-medium">Next attempt</th>
                        <th class="px-3 py-2 font-medium">Last activity</th>
                        <th class="px-3 py-2 font-medium">Problem</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in deliveries.data"
                        :key="row.id"
                        class="border-b last:border-0"
                    >
                        <td class="px-3 py-2">
                            <Link
                                class="font-medium underline-offset-4 hover:underline"
                                :href="
                                    outboxDeliveries.show.url([slug, row.id])
                                "
                            >
                                {{ row.business_event }}
                            </Link>
                            <div class="text-xs text-muted-foreground">
                                <Link
                                    class="hover:underline"
                                    :href="
                                        outboxEvents.show.url([
                                            slug,
                                            row.outbox_id,
                                        ])
                                    "
                                >
                                    Event #{{ row.outbox_id }}
                                </Link>
                            </div>
                        </td>
                        <td class="px-3 py-2">
                            {{ row.quote_number ?? '—' }}
                        </td>
                        <td class="px-3 py-2">
                            {{ row.company_name ?? '—' }}
                        </td>
                        <td class="px-3 py-2">{{ row.consumer_label }}</td>
                        <td class="px-3 py-2">
                            <Badge :variant="statusTone(row.status)">
                                {{ row.status_label }}
                            </Badge>
                        </td>
                        <td class="px-3 py-2">{{ row.attempt_count }}</td>
                        <td class="px-3 py-2">
                            {{ formatWhen(row.next_attempt_at) }}
                        </td>
                        <td class="px-3 py-2">
                            {{ formatWhen(row.last_activity_at) }}
                        </td>
                        <td
                            class="max-w-[220px] truncate px-3 py-2 text-muted-foreground"
                        >
                            {{ row.problem_summary ?? '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p
            v-if="deliveries.meta.total > 0"
            class="text-sm text-muted-foreground"
        >
            Showing page {{ deliveries.meta.current_page }} of
            {{ deliveries.meta.last_page }} ({{ deliveries.meta.total }} total)
        </p>
    </div>
</template>
