<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useOrganizationSlug } from '@/composables/useTenantAction';
import { dashboard } from '@/routes';
import outbox from '@/routes/org/integrations/outbox';
import outboxDeliveries from '@/routes/org/integrations/outbox/deliveries';

type ProjectedDelivery = {
    id: number;
    consumer_label: string;
    status: string;
    status_label: string;
    attempt_count: number;
    error: { code: string | null; message: string | null };
    can_replay: boolean;
    can_abandon: boolean;
};

defineProps<{
    event: {
        id: number;
        event_label: string;
        event_type: string;
        schema_version: number;
        aggregate_type: string;
        aggregate_id: number;
        status_label: string;
        correlation_id: string;
        available_at: string | null;
        locked_at: string | null;
        dispatched_at: string | null;
        created_at: string | null;
        error: { code: string | null; message: string | null };
    };
    payload_fields: {
        key: string;
        label: string;
        value: string | number | null;
    }[];
    business: {
        quote_id: number | null;
        quote_number: string | null;
        quote_revision_id: number | null;
        deal_id: number | null;
        company_name: string | null;
    };
    deliveries: ProjectedDelivery[];
    canReplay: boolean;
    canAbandon: boolean;
}>();

const slug = useOrganizationSlug();

function formatWhen(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Integration activity', href: '#' },
            { title: 'Event', href: '#' },
        ],
    },
});
</script>

<template>
    <Head :title="`Event #${event.id}`" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="event.event_label"
                :description="`Event #${event.id}`"
            />
            <Button variant="outline" as-child>
                <Link :href="outbox.index.url(slug)">Back to list</Link>
            </Button>
        </div>

        <Badge class="w-fit">{{ event.status_label }}</Badge>

        <section class="grid gap-4 rounded-lg border p-4 md:grid-cols-2">
            <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                <dt class="text-muted-foreground">Schema version</dt>
                <dd>{{ event.schema_version }}</dd>
                <dt class="text-muted-foreground">Aggregate</dt>
                <dd>{{ event.aggregate_type }} #{{ event.aggregate_id }}</dd>
                <dt class="text-muted-foreground">Correlation</dt>
                <dd class="break-all">{{ event.correlation_id }}</dd>
                <dt class="text-muted-foreground">Created</dt>
                <dd>{{ formatWhen(event.created_at) }}</dd>
                <dt class="text-muted-foreground">Prepared</dt>
                <dd>{{ formatWhen(event.dispatched_at) }}</dd>
                <dt class="text-muted-foreground">Problem</dt>
                <dd>{{ event.error.message ?? event.error.code ?? '—' }}</dd>
            </dl>
            <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                <dt class="text-muted-foreground">Quote</dt>
                <dd>{{ business.quote_number ?? '—' }}</dd>
                <dt class="text-muted-foreground">Customer</dt>
                <dd>{{ business.company_name ?? '—' }}</dd>
                <dt class="text-muted-foreground">Revision</dt>
                <dd>{{ business.quote_revision_id ?? '—' }}</dd>
                <dt class="text-muted-foreground">Deal</dt>
                <dd>{{ business.deal_id ?? '—' }}</dd>
            </dl>
        </section>

        <section v-if="payload_fields.length > 0" class="rounded-lg border p-4">
            <h2 class="mb-2 font-medium">Safe identifiers</h2>
            <dl class="grid grid-cols-2 gap-x-3 gap-y-1 text-sm md:grid-cols-4">
                <template v-for="field in payload_fields" :key="field.key">
                    <dt class="text-muted-foreground">{{ field.label }}</dt>
                    <dd>{{ field.value ?? '—' }}</dd>
                </template>
            </dl>
        </section>

        <section class="rounded-lg border p-4">
            <h2 class="mb-3 font-medium">Deliveries</h2>
            <div
                v-if="deliveries.length === 0"
                class="text-sm text-muted-foreground"
            >
                No consumer deliveries were prepared for this event.
            </div>
            <ul v-else class="divide-y rounded-md border">
                <li
                    v-for="delivery in deliveries"
                    :key="delivery.id"
                    class="flex flex-wrap items-center justify-between gap-3 p-3"
                >
                    <div>
                        <Link
                            class="font-medium underline-offset-4 hover:underline"
                            :href="
                                outboxDeliveries.show.url([slug, delivery.id])
                            "
                        >
                            {{ delivery.consumer_label }}
                        </Link>
                        <div class="text-sm text-muted-foreground">
                            Attempts {{ delivery.attempt_count }}
                            <span v-if="delivery.error.message">
                                — {{ delivery.error.message }}
                            </span>
                        </div>
                    </div>
                    <Badge>{{ delivery.status_label }}</Badge>
                </li>
            </ul>
        </section>
    </div>
</template>
