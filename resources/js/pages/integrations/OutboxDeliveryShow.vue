<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
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

type ProjectedDelivery = {
    id: number;
    consumer_key: string;
    consumer_label: string;
    status: string;
    status_label: string;
    attempt_count: number;
    available_at: string | null;
    locked_at: string | null;
    locked_by_worker: string | null;
    succeeded_at: string | null;
    blocked_at: string | null;
    abandoned_at: string | null;
    updated_at: string | null;
    error: { code: string | null; message: string | null };
    provider_reference: Record<string, string | number | null>;
    lease_active: boolean;
    lease_expired: boolean;
    can_replay: boolean;
    can_abandon: boolean;
};

const props = defineProps<{
    delivery: ProjectedDelivery;
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
    } | null;
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
    } | null;
    audits: {
        id: number;
        action: string;
        actor_user_id: number | null;
        before: Record<string, unknown> | null;
        after: Record<string, unknown> | null;
        correlation_id: string | null;
        created_at: string | null;
    }[];
    canReplay: boolean;
    canAbandon: boolean;
}>();

const slug = useOrganizationSlug();
const showReplay = ref(false);
const showAbandon = ref(false);

const replayForm = useForm({
    reason: '',
    expected_status: props.delivery.status,
    reset_attempts: false,
});

const abandonForm = useForm({
    reason: '',
    expected_status: props.delivery.status,
    confirm: false,
});

function submitReplay(): void {
    replayForm.post(outboxDeliveries.replay.url([slug, props.delivery.id]), {
        preserveScroll: true,
        onSuccess: () => {
            showReplay.value = false;
            replayForm.reset('reason');
        },
    });
}

function submitAbandon(): void {
    abandonForm
        .transform((data) => ({
            ...data,
            confirm: data.confirm ? 1 : 0,
        }))
        .post(outboxDeliveries.abandon.url([slug, props.delivery.id]), {
            preserveScroll: true,
            onSuccess: () => {
                showAbandon.value = false;
                abandonForm.reset('reason', 'confirm');
            },
        });
}

function formatWhen(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Integration activity', href: '#' },
            { title: 'Delivery', href: '#' },
        ],
    },
});
</script>

<template>
    <Head :title="`Delivery #${delivery.id}`" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="`Delivery #${delivery.id}`"
                :description="delivery.consumer_label"
            />
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" as-child>
                    <Link :href="outbox.index.url(slug)">Back to list</Link>
                </Button>
                <Button
                    v-if="delivery.can_replay"
                    @click="showReplay = !showReplay"
                >
                    Retry delivery
                </Button>
                <Button
                    v-if="delivery.can_abandon"
                    variant="destructive"
                    @click="showAbandon = !showAbandon"
                >
                    Abandon
                </Button>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <Badge>{{ delivery.status_label }}</Badge>
            <Badge v-if="delivery.lease_active" variant="secondary">
                Actively leased
            </Badge>
            <Badge v-if="delivery.lease_expired" variant="destructive">
                Lease expired
            </Badge>
        </div>

        <div
            v-if="showReplay && delivery.can_replay"
            class="space-y-3 rounded-lg border p-4"
        >
            <p class="text-sm font-medium">
                Queue this delivery for another attempt. This does not run the
                integration immediately.
            </p>
            <div>
                <Label for="replay_reason">Reason</Label>
                <Input
                    id="replay_reason"
                    v-model="replayForm.reason"
                    placeholder="Why retry?"
                />
                <p
                    v-if="replayForm.errors.reason"
                    class="text-sm text-destructive"
                >
                    {{ replayForm.errors.reason }}
                </p>
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input
                    v-model="replayForm.reset_attempts"
                    type="checkbox"
                    class="size-4"
                />
                Reset attempt counter
            </label>
            <Button :disabled="replayForm.processing" @click="submitReplay">
                Confirm retry
            </Button>
        </div>

        <div
            v-if="showAbandon && delivery.can_abandon"
            class="space-y-3 rounded-lg border border-destructive/40 p-4"
        >
            <p class="text-sm font-medium">
                Mark this delivery abandoned. The original event is kept.
            </p>
            <div>
                <Label for="abandon_reason">Reason</Label>
                <Input
                    id="abandon_reason"
                    v-model="abandonForm.reason"
                    placeholder="Why abandon?"
                />
                <p
                    v-if="abandonForm.errors.reason"
                    class="text-sm text-destructive"
                >
                    {{ abandonForm.errors.reason }}
                </p>
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input
                    v-model="abandonForm.confirm"
                    type="checkbox"
                    class="size-4"
                />
                I understand this stops automatic retries
            </label>
            <p
                v-if="abandonForm.errors.confirm"
                class="text-sm text-destructive"
            >
                {{ abandonForm.errors.confirm }}
            </p>
            <Button
                variant="destructive"
                :disabled="abandonForm.processing"
                @click="submitAbandon"
            >
                Confirm abandon
            </Button>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="space-y-2 rounded-lg border p-4">
                <h2 class="font-medium">Delivery</h2>
                <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                    <dt class="text-muted-foreground">Attempts</dt>
                    <dd>{{ delivery.attempt_count }}</dd>
                    <dt class="text-muted-foreground">Available</dt>
                    <dd>{{ formatWhen(delivery.available_at) }}</dd>
                    <dt class="text-muted-foreground">Locked</dt>
                    <dd>{{ formatWhen(delivery.locked_at) }}</dd>
                    <dt class="text-muted-foreground">Worker</dt>
                    <dd>{{ delivery.locked_by_worker ?? '—' }}</dd>
                    <dt class="text-muted-foreground">Succeeded</dt>
                    <dd>{{ formatWhen(delivery.succeeded_at) }}</dd>
                    <dt class="text-muted-foreground">Blocked</dt>
                    <dd>{{ formatWhen(delivery.blocked_at) }}</dd>
                    <dt class="text-muted-foreground">Abandoned</dt>
                    <dd>{{ formatWhen(delivery.abandoned_at) }}</dd>
                    <dt class="text-muted-foreground">Problem code</dt>
                    <dd>{{ delivery.error.code ?? '—' }}</dd>
                    <dt class="text-muted-foreground">Problem</dt>
                    <dd>{{ delivery.error.message ?? '—' }}</dd>
                </dl>
            </section>

            <section class="space-y-2 rounded-lg border p-4">
                <h2 class="font-medium">Business context</h2>
                <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                    <dt class="text-muted-foreground">Quote</dt>
                    <dd>{{ business?.quote_number ?? '—' }}</dd>
                    <dt class="text-muted-foreground">Customer</dt>
                    <dd>{{ business?.company_name ?? '—' }}</dd>
                    <dt class="text-muted-foreground">Revision</dt>
                    <dd>{{ business?.quote_revision_id ?? '—' }}</dd>
                    <dt class="text-muted-foreground">Deal</dt>
                    <dd>{{ business?.deal_id ?? '—' }}</dd>
                </dl>
                <div
                    v-if="Object.keys(delivery.provider_reference).length > 0"
                    class="pt-2"
                >
                    <h3 class="mb-1 text-sm font-medium">Provider reference</h3>
                    <dl class="grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
                        <template
                            v-for="(value, key) in delivery.provider_reference"
                            :key="key"
                        >
                            <dt class="text-muted-foreground">{{ key }}</dt>
                            <dd>{{ value ?? '—' }}</dd>
                        </template>
                    </dl>
                </div>
            </section>
        </div>

        <section v-if="event" class="space-y-2 rounded-lg border p-4">
            <div class="flex items-center justify-between gap-2">
                <h2 class="font-medium">Source event</h2>
                <Link
                    class="text-sm underline-offset-4 hover:underline"
                    :href="outboxEvents.show.url([slug, event.id])"
                >
                    Open event #{{ event.id }}
                </Link>
            </div>
            <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-sm md:grid-cols-4">
                <dt class="text-muted-foreground">Event</dt>
                <dd>{{ event.event_label }}</dd>
                <dt class="text-muted-foreground">Schema</dt>
                <dd>{{ event.schema_version }}</dd>
                <dt class="text-muted-foreground">Status</dt>
                <dd>{{ event.status_label }}</dd>
                <dt class="text-muted-foreground">Correlation</dt>
                <dd class="break-all">{{ event.correlation_id }}</dd>
            </dl>
            <div v-if="payload_fields.length > 0" class="pt-2">
                <h3 class="mb-1 text-sm font-medium">Safe identifiers</h3>
                <dl
                    class="grid grid-cols-2 gap-x-3 gap-y-1 text-sm md:grid-cols-4"
                >
                    <template v-for="field in payload_fields" :key="field.key">
                        <dt class="text-muted-foreground">{{ field.label }}</dt>
                        <dd>{{ field.value ?? '—' }}</dd>
                    </template>
                </dl>
            </div>
        </section>

        <section class="space-y-2 rounded-lg border p-4">
            <h2 class="font-medium">Operator history</h2>
            <p v-if="audits.length === 0" class="text-sm text-muted-foreground">
                No replay or abandon actions yet.
            </p>
            <ul v-else class="space-y-2 text-sm">
                <li
                    v-for="audit in audits"
                    :key="audit.id"
                    class="rounded-md border p-3"
                >
                    <div class="font-medium">{{ audit.action }}</div>
                    <div class="text-muted-foreground">
                        {{ formatWhen(audit.created_at) }}
                        <span v-if="audit.after && 'reason' in audit.after">
                            — {{ String(audit.after.reason) }}
                        </span>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>
