<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useOrganizationSlug } from '@/composables/useTenantAction';
import revisions from '@/routes/org/quotes/revisions';
import type { QuoteApprovalPanel } from '@/types';

const props = defineProps<{
    approval: QuoteApprovalPanel;
    quoteId: number;
    revisionId: number;
    lockVersion: number;
    quoteLockVersion: number;
}>();

const slug = useOrganizationSlug();

const routeArgs = computed<[string, number, number]>(() => [
    slug,
    props.quoteId,
    props.revisionId,
]);

const manualEscalation = ref(false);

const statusLabel = computed(() => props.approval.status.replaceAll('_', ' '));

/**
 * Reasons captured at submission are what the approver is deciding on; before
 * submission the panel shows whatever the last evaluation found.
 */
const reasons = computed(() =>
    props.approval.current_request
        ? props.approval.current_request.reasons
        : props.approval.reasons,
);

const explanations = computed(() =>
    props.approval.current_request
        ? props.approval.current_request.explanations
        : props.approval.explanations,
);

function explain(reason: string): string {
    return (
        explanations.value[reason] ??
        props.approval.reason_catalog[reason] ??
        reason.replaceAll('_', ' ')
    );
}

const lockPayload = computed(() => ({
    expected_lock_version: props.lockVersion,
    expected_quote_lock_version: props.quoteLockVersion,
}));

const visitOptions = { preserveScroll: true } as const;

function evaluate(): void {
    router.post(
        revisions.approvals.evaluate.url(routeArgs.value),
        { manual_escalation: manualEscalation.value },
        visitOptions,
    );
}

function submit(): void {
    router.post(
        revisions.approvals.submit.url(routeArgs.value),
        { ...lockPayload.value, manual_escalation: manualEscalation.value },
        visitOptions,
    );
}

function withdraw(): void {
    router.post(
        revisions.approvals.withdraw.url(routeArgs.value),
        lockPayload.value,
        visitOptions,
    );
}

function returnToDraft(): void {
    router.post(
        revisions.approvals.returnToDraft.url(routeArgs.value),
        lockPayload.value,
        visitOptions,
    );
}
</script>

<template>
    <section class="space-y-4 rounded-xl border p-4 text-sm">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-medium">Approval</h2>
            <Badge
                :variant="
                    props.approval.status === 'approved'
                        ? 'secondary'
                        : 'outline'
                "
            >
                {{ statusLabel }}
            </Badge>
        </div>

        <div v-if="props.approval.current_request" class="space-y-1">
            <p>
                <span class="text-muted-foreground">Requested by:</span>
                {{ props.approval.current_request.requested_by ?? 'Unknown' }}
            </p>
            <p>
                <span class="text-muted-foreground">Waiting:</span>
                {{ props.approval.current_request.age_days }} day(s)
            </p>
        </div>

        <div v-if="reasons.length > 0" class="space-y-1">
            <p class="font-medium">Why approval is required</p>
            <ul class="list-inside list-disc text-muted-foreground">
                <li v-for="reason in reasons" :key="reason">
                    {{ explain(reason) }}
                </li>
            </ul>
        </div>

        <p
            v-else-if="!props.approval.approval_required"
            class="text-muted-foreground"
        >
            Nothing on this revision currently requires approval.
        </p>

        <p
            v-if="props.approval.blocked_by_tax"
            class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
        >
            Tax must be resolved before this revision can be submitted for
            approval.
        </p>

        <div class="flex flex-wrap items-center gap-3 border-t pt-3">
            <label
                v-if="props.approval.can_evaluate"
                class="flex items-center gap-2 text-xs"
            >
                <input v-model="manualEscalation" type="checkbox" />
                Escalate manually
            </label>
            <Button
                v-if="props.approval.can_evaluate"
                variant="outline"
                size="sm"
                @click="evaluate"
            >
                Review requirements
            </Button>
            <Button v-if="props.approval.can_submit" size="sm" @click="submit">
                Submit for approval
            </Button>
            <Button
                v-if="props.approval.can_withdraw"
                variant="outline"
                size="sm"
                @click="withdraw"
            >
                Withdraw request
            </Button>
            <Button
                v-if="props.approval.can_return_to_draft"
                variant="outline"
                size="sm"
                @click="returnToDraft"
            >
                Return to draft
            </Button>
        </div>
    </section>
</template>
