<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useOrganizationSlug } from '@/composables/useTenantAction';
import revisions from '@/routes/org/quotes/revisions';
import type { QuoteDeliveryPanel } from '@/types';

const props = defineProps<{
    delivery: QuoteDeliveryPanel;
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

const recipientName = ref(props.delivery.recipient_defaults.name ?? '');
const recipientEmail = ref(props.delivery.recipient_defaults.email ?? '');
const confirmed = ref(false);
const externalReference = ref('');
const revokeReason = ref('lost_link');
const typedName = ref('');
const termsAccepted = ref(false);
const employeeReason = ref('');
const rejectionReason = ref('');

const visitOptions = { preserveScroll: true } as const;

const lockPayload = computed(() => ({
    expected_lock_version: props.lockVersion,
    expected_quote_lock_version: props.quoteLockVersion,
}));

const currentDocumentId = computed(
    () => props.delivery.current_document?.id ?? null,
);

function generateDocument(): void {
    router.post(
        revisions.documents.generate.url(routeArgs.value),
        {},
        visitOptions,
    );
}

function previewDocument(format: 'html' | 'pdf' = 'html'): void {
    if (!currentDocumentId.value) {
        return;
    }

    const url = revisions.documents.preview.url([
        slug,
        props.quoteId,
        props.revisionId,
        currentDocumentId.value,
    ]);

    window.open(
        format === 'pdf' ? `${url}?format=pdf` : url,
        '_blank',
        'noopener',
    );
}

function downloadDocument(): void {
    if (!currentDocumentId.value) {
        return;
    }

    window.open(
        revisions.documents.download.url([
            slug,
            props.quoteId,
            props.revisionId,
            currentDocumentId.value,
        ]),
        '_blank',
        'noopener',
    );
}

function prepareLink(): void {
    router.post(
        revisions.customerLink.prepare.url(routeArgs.value),
        {
            recipient_name: recipientName.value || null,
            recipient_email: recipientEmail.value || null,
        },
        visitOptions,
    );
}

function recordManual(): void {
    if (
        !props.delivery.pending_delivery ||
        !props.delivery.active_token ||
        !confirmed.value
    ) {
        return;
    }

    router.post(
        revisions.deliveries.recordManual.url([
            slug,
            props.quoteId,
            props.revisionId,
            props.delivery.pending_delivery.id,
        ]),
        {
            ...lockPayload.value,
            quote_customer_access_token_id: props.delivery.active_token.id,
            recipient_name: recipientName.value,
            recipient_email: recipientEmail.value,
            confirmed: true,
            external_reference: externalReference.value || null,
        },
        visitOptions,
    );
}

function revokeToken(): void {
    if (!props.delivery.active_token) {
        return;
    }

    router.post(
        revisions.tokens.revoke.url([
            slug,
            props.quoteId,
            props.revisionId,
            props.delivery.active_token.id,
        ]),
        { reason: revokeReason.value },
        visitOptions,
    );
}

function regenerateToken(): void {
    router.post(
        revisions.tokens.regenerate.url(routeArgs.value),
        {
            recipient_name: recipientName.value || null,
            recipient_email: recipientEmail.value || null,
        },
        visitOptions,
    );
}

function acceptAsEmployee(): void {
    if (!props.delivery.active_token) {
        return;
    }

    router.post(
        revisions.employeeResponses.accept.url(routeArgs.value),
        {
            ...lockPayload.value,
            quote_customer_access_token_id: props.delivery.active_token.id,
            typed_name: typedName.value,
            terms_accepted: termsAccepted.value,
            employee_recorded_reason: employeeReason.value,
        },
        visitOptions,
    );
}

function rejectAsEmployee(): void {
    if (!props.delivery.active_token) {
        return;
    }

    router.post(
        revisions.employeeResponses.reject.url(routeArgs.value),
        {
            ...lockPayload.value,
            quote_customer_access_token_id: props.delivery.active_token.id,
            typed_name: typedName.value || null,
            rejection_reason: rejectionReason.value || null,
            employee_recorded_reason: employeeReason.value,
        },
        visitOptions,
    );
}
</script>

<template>
    <section class="space-y-4 rounded-xl border p-4 text-sm">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-medium">Delivery</h2>
            <Badge variant="outline">
                {{
                    props.delivery.current_document
                        ? `Document v${props.delivery.current_document.document_version}`
                        : 'No document'
                }}
            </Badge>
        </div>

        <div class="flex flex-wrap gap-2">
            <Button
                v-if="props.delivery.can_generate_document"
                type="button"
                size="sm"
                @click="generateDocument"
            >
                Generate PDF
            </Button>
            <Button
                v-if="props.delivery.can_preview_document"
                type="button"
                size="sm"
                variant="outline"
                @click="previewDocument('html')"
            >
                Preview HTML
            </Button>
            <Button
                v-if="props.delivery.can_preview_document"
                type="button"
                size="sm"
                variant="outline"
                @click="previewDocument('pdf')"
            >
                Preview PDF
            </Button>
            <Button
                v-if="props.delivery.can_preview_document"
                type="button"
                size="sm"
                variant="outline"
                @click="downloadDocument"
            >
                Download PDF
            </Button>
            <Button
                v-if="props.delivery.can_send"
                type="button"
                size="sm"
                @click="prepareLink"
            >
                Prepare customer link
            </Button>
            <Button
                v-if="props.delivery.can_send"
                type="button"
                size="sm"
                variant="outline"
                @click="regenerateToken"
            >
                Regenerate link
            </Button>
        </div>

        <div
            v-if="props.delivery.active_token"
            class="space-y-2 rounded-lg border p-3"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="font-medium">Active token</p>
                <Badge
                    :variant="
                        props.delivery.active_token.is_usable
                            ? 'secondary'
                            : 'destructive'
                    "
                >
                    {{
                        props.delivery.active_token.is_usable
                            ? 'usable'
                            : 'unusable'
                    }}
                </Badge>
            </div>
            <p class="text-muted-foreground">
                Expires
                {{ props.delivery.active_token.expires_at }} · Views
                {{ props.delivery.active_token.view_count }}
            </p>
            <div
                v-if="props.delivery.can_send"
                class="flex flex-wrap items-end gap-2"
            >
                <div class="grid gap-1">
                    <Label for="revoke-reason">Revoke reason</Label>
                    <Input
                        id="revoke-reason"
                        v-model="revokeReason"
                        class="w-48"
                    />
                </div>
                <Button
                    type="button"
                    size="sm"
                    variant="destructive"
                    @click="revokeToken"
                >
                    Revoke
                </Button>
            </div>
        </div>
        <p v-else class="text-muted-foreground">No active customer link.</p>

        <div
            v-if="props.delivery.can_send && props.delivery.pending_delivery"
            class="space-y-3 rounded-lg border p-3"
        >
            <p class="font-medium">Record manual send</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-1">
                    <Label for="recipient-name">Recipient name</Label>
                    <Input id="recipient-name" v-model="recipientName" />
                </div>
                <div class="grid gap-1">
                    <Label for="recipient-email">Recipient email</Label>
                    <Input
                        id="recipient-email"
                        v-model="recipientEmail"
                        type="email"
                    />
                </div>
                <div class="grid gap-1 sm:col-span-2">
                    <Label for="external-reference"
                        >External reference (optional)</Label
                    >
                    <Input
                        id="external-reference"
                        v-model="externalReference"
                    />
                </div>
            </div>
            <label class="flex items-start gap-2 text-sm">
                <input v-model="confirmed" type="checkbox" class="mt-1" />
                <span
                    >I confirm this quote was sent outside the app (for example
                    via Outlook).</span
                >
            </label>
            <Button
                type="button"
                size="sm"
                :disabled="!confirmed || !props.delivery.active_token"
                @click="recordManual"
            >
                Record manual send
            </Button>
        </div>

        <div
            v-if="props.delivery.can_record_customer_response"
            class="space-y-3 rounded-lg border p-3"
        >
            <p class="font-medium">Employee-recorded response</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-1">
                    <Label for="typed-name">Customer typed name</Label>
                    <Input id="typed-name" v-model="typedName" />
                </div>
                <div class="grid gap-1 sm:col-span-2">
                    <Label for="employee-reason">Evidence / reason</Label>
                    <Input id="employee-reason" v-model="employeeReason" />
                </div>
                <div class="grid gap-1 sm:col-span-2">
                    <Label for="rejection-reason"
                        >Rejection reason (optional)</Label
                    >
                    <Input id="rejection-reason" v-model="rejectionReason" />
                </div>
            </div>
            <label class="flex items-start gap-2 text-sm">
                <input v-model="termsAccepted" type="checkbox" class="mt-1" />
                <span>Customer accepted the terms (required for accept).</span>
            </label>
            <div class="flex flex-wrap gap-2">
                <Button type="button" size="sm" @click="acceptAsEmployee">
                    Record accept
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    @click="rejectAsEmployee"
                >
                    Record reject
                </Button>
            </div>
        </div>

        <div
            v-if="props.delivery.deliveries.length > 0"
            class="space-y-2 border-t pt-3"
        >
            <p class="font-medium">Delivery history</p>
            <ul class="space-y-1 text-muted-foreground">
                <li
                    v-for="item in props.delivery.deliveries"
                    :key="item.id"
                    class="flex flex-wrap justify-between gap-2"
                >
                    <span
                        >#{{ item.id }} · {{ item.channel }} ·
                        {{ item.status }}</span
                    >
                    <span>{{ item.recipient_email_snapshot }}</span>
                </li>
            </ul>
        </div>
    </section>
</template>
