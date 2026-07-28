<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useOrganizationSlug } from '@/composables/useTenantAction';
import { dashboard } from '@/routes';
import quoteApprovals from '@/routes/org/quote-approvals';
import revisions from '@/routes/org/quotes/revisions';
import type { ApprovalRequest } from '@/types';

const props = defineProps<{
    requests: ApprovalRequest[];
    filters: {
        status: string;
        salesperson: number | null;
        min_amount: string | null;
        reason: string | null;
        min_age_days: number | null;
    };
    statuses: { value: string; label: string }[];
    reasonOptions: { value: string; label: string }[];
    salespeople: { value: number; label: string }[];
}>();

const slug = useOrganizationSlug();

const fieldClass =
    'border-input bg-transparent dark:bg-input/30 h-9 w-full rounded-md border px-3 py-1 text-sm outline-none';

const filters = reactive({
    status: props.filters.status,
    salesperson: props.filters.salesperson ?? '',
    min_amount: props.filters.min_amount ?? '',
    reason: props.filters.reason ?? '',
    min_age_days: props.filters.min_age_days ?? '',
});

const rejectingId = ref<number | null>(null);
const rejectReason = ref('');

let filterTimer: ReturnType<typeof setTimeout> | undefined;

watch(filters, () => {
    clearTimeout(filterTimer);
    filterTimer = setTimeout(() => {
        router.get(
            quoteApprovals.index.url(slug),
            { ...filters },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }, 250);
});

/**
 * Both lock versions travel with the decision so a queue row rendered before the
 * quote moved is refused rather than applied to a different quote than the one
 * the approver read.
 */
function lockPayload(request: ApprovalRequest): Record<string, number> {
    return {
        expected_lock_version: request.revision_lock_version ?? 0,
        expected_quote_lock_version: request.quote_lock_version ?? 0,
    };
}

function approve(request: ApprovalRequest): void {
    router.post(
        quoteApprovals.approve.url([slug, request.id]),
        lockPayload(request),
        { preserveScroll: true },
    );
}

function reject(request: ApprovalRequest): void {
    router.post(
        quoteApprovals.reject.url([slug, request.id]),
        { ...lockPayload(request), reason: rejectReason.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                rejectingId.value = null;
                rejectReason.value = '';
            },
        },
    );
}

function revisionHref(request: ApprovalRequest): string {
    return revisions.show.url([
        slug,
        request.quote_id,
        request.quote_revision_id,
    ]);
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Approvals', href: '#' },
        ],
    },
});
</script>

<template>
    <Head title="Quote approvals" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Quote approvals"
            description="Quotes waiting on a decision, with the reasons captured when they were submitted."
        />

        <section
            class="grid gap-3 rounded-xl border p-4 text-sm sm:grid-cols-5"
        >
            <div class="space-y-1">
                <Label for="filter_status">Status</Label>
                <select
                    id="filter_status"
                    v-model="filters.status"
                    :class="fieldClass"
                >
                    <option value="all">All</option>
                    <option
                        v-for="status in props.statuses"
                        :key="status.value"
                        :value="status.value"
                    >
                        {{ status.label }}
                    </option>
                </select>
            </div>
            <div class="space-y-1">
                <Label for="filter_salesperson">Salesperson</Label>
                <select
                    id="filter_salesperson"
                    v-model="filters.salesperson"
                    :class="fieldClass"
                >
                    <option value="">Anyone</option>
                    <option
                        v-for="person in props.salespeople"
                        :key="person.value"
                        :value="person.value"
                    >
                        {{ person.label }}
                    </option>
                </select>
            </div>
            <div class="space-y-1">
                <Label for="filter_min_amount">Minimum amount</Label>
                <Input
                    id="filter_min_amount"
                    v-model="filters.min_amount"
                    placeholder="10000"
                />
            </div>
            <div class="space-y-1">
                <Label for="filter_reason">Reason</Label>
                <select
                    id="filter_reason"
                    v-model="filters.reason"
                    :class="fieldClass"
                >
                    <option value="">Any reason</option>
                    <option
                        v-for="reason in props.reasonOptions"
                        :key="reason.value"
                        :value="reason.value"
                    >
                        {{ reason.label }}
                    </option>
                </select>
            </div>
            <div class="space-y-1">
                <Label for="filter_age">Waiting at least (days)</Label>
                <Input
                    id="filter_age"
                    v-model="filters.min_age_days"
                    type="number"
                    min="0"
                />
            </div>
        </section>

        <section class="space-y-3 rounded-xl border p-4 text-sm">
            <ul class="divide-y">
                <li
                    v-for="request in props.requests"
                    :key="request.id"
                    class="space-y-3 py-3"
                >
                    <div
                        class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div class="space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <Link
                                    :href="revisionHref(request)"
                                    class="font-medium underline-offset-4 hover:underline"
                                >
                                    {{ request.quote_number }} rev
                                    {{ request.revision_number }}
                                </Link>
                                <Badge
                                    :variant="
                                        request.is_open
                                            ? 'outline'
                                            : 'secondary'
                                    "
                                >
                                    {{ request.status }}
                                </Badge>
                            </div>
                            <p class="text-muted-foreground">
                                ${{ request.threshold_basis }} pre-tax ·
                                {{ request.requested_by ?? 'Unknown' }} ·
                                waiting {{ request.age_days }} day(s)
                            </p>
                            <ul
                                class="list-inside list-disc text-muted-foreground"
                            >
                                <li
                                    v-for="reason in request.reasons"
                                    :key="reason"
                                >
                                    {{
                                        request.explanations[reason] ??
                                        reason.replaceAll('_', ' ')
                                    }}
                                </li>
                            </ul>
                        </div>

                        <div v-if="request.is_open" class="flex gap-2">
                            <Button size="sm" @click="approve(request)">
                                Approve
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                @click="
                                    rejectingId =
                                        rejectingId === request.id
                                            ? null
                                            : request.id
                                "
                            >
                                Reject
                            </Button>
                        </div>
                    </div>

                    <div
                        v-if="rejectingId === request.id"
                        class="flex flex-col gap-2 rounded-lg bg-muted/30 p-3 sm:flex-row sm:items-end"
                    >
                        <div class="flex-1 space-y-1">
                            <Label :for="`reject_reason_${request.id}`">
                                Why is this being rejected?
                            </Label>
                            <Input
                                :id="`reject_reason_${request.id}`"
                                v-model="rejectReason"
                            />
                        </div>
                        <Button
                            size="sm"
                            :disabled="rejectReason === ''"
                            @click="reject(request)"
                        >
                            Confirm rejection
                        </Button>
                    </div>
                </li>

                <li
                    v-if="props.requests.length === 0"
                    class="py-8 text-center text-muted-foreground"
                >
                    Nothing is waiting on a decision.
                </li>
            </ul>
        </section>
    </div>
</template>
