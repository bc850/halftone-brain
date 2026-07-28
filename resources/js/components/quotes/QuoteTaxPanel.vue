<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useOrganizationSlug } from '@/composables/useTenantAction';
import revisions from '@/routes/org/quotes/revisions';
import type { QuoteAddress, QuoteTaxPanel } from '@/types';

const props = defineProps<{
    tax: QuoteTaxPanel;
    quoteId: number;
    revisionId: number;
    lockVersion: number;
}>();

const slug = useOrganizationSlug();

const routeArgs = computed<[string, number, number]>(() => [
    slug,
    props.quoteId,
    props.revisionId,
]);

const fieldClass =
    'border-input bg-transparent dark:bg-input/30 h-9 w-full rounded-md border px-3 py-1 text-sm outline-none';

const selectedRateId = ref<number | ''>(
    props.tax.rates.length === 1 ? props.tax.rates[0].id : '',
);
const selectedCertificateId = ref<number | ''>('');
const overrideAmount = ref('');
const overrideReason = ref('');
const showHistory = ref(false);

const statusLabel = computed(() => props.tax.status.replaceAll('_', ' '));

const statusVariant = computed<'secondary' | 'outline' | 'destructive'>(() => {
    if (props.tax.status === 'review_required') {
        return 'destructive';
    }

    return props.tax.is_resolved ? 'secondary' : 'outline';
});

function formatAddress(address: QuoteAddress | null): string {
    if (!address) {
        return '—';
    }

    return (
        [
            address.line1,
            [address.city, address.state].filter(Boolean).join(', '),
            address.postal_code,
        ]
            .filter(Boolean)
            .join(' · ') || '—'
    );
}

function calculate(): void {
    if (selectedRateId.value === '') {
        return;
    }

    router.post(
        revisions.tax.calculate.url(routeArgs.value),
        {
            expected_lock_version: props.lockVersion,
            organization_tax_rate_id: selectedRateId.value,
            certificate_id: selectedCertificateId.value || null,
        },
        { preserveScroll: true },
    );
}

function override(): void {
    router.post(
        revisions.tax.override.url(routeArgs.value),
        {
            expected_lock_version: props.lockVersion,
            override_tax: overrideAmount.value,
            reason: overrideReason.value,
            organization_tax_rate_id: selectedRateId.value || null,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                overrideAmount.value = '';
                overrideReason.value = '';
            },
        },
    );
}
</script>

<template>
    <section class="space-y-4 rounded-xl border p-4 text-sm">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-medium">Tax</h2>
            <Badge :variant="statusVariant">{{ statusLabel }}</Badge>
        </div>

        <div class="grid gap-2 sm:grid-cols-2">
            <p>
                <span class="text-muted-foreground">Service address:</span>
                {{ formatAddress(props.tax.service_address) }}
            </p>
            <p>
                <span class="text-muted-foreground">Billing address:</span>
                {{ formatAddress(props.tax.billing_address) }}
            </p>
        </div>

        <div v-if="props.tax.current" class="grid gap-2 sm:grid-cols-3">
            <p>
                <span class="text-muted-foreground">Taxable basis:</span>
                ${{ props.tax.current.taxable_basis }}
            </p>
            <p>
                <span class="text-muted-foreground">Rate:</span>
                {{
                    props.tax.current.rate_percent
                        ? `${props.tax.current.rate_percent}%`
                        : '—'
                }}
            </p>
            <p>
                <span class="text-muted-foreground">Tax:</span>
                ${{ props.tax.current.tax }}
            </p>
            <p v-if="props.tax.current.jurisdiction?.display_name">
                <span class="text-muted-foreground">Jurisdiction:</span>
                {{ props.tax.current.jurisdiction.display_name }}
            </p>
            <p v-if="props.tax.current.certificate_reference">
                <span class="text-muted-foreground">Certificate:</span>
                {{ props.tax.current.certificate_reference }}
            </p>
            <p v-if="props.tax.current.is_override">
                <span class="text-muted-foreground">Override reason:</span>
                {{ props.tax.current.override_reason }}
            </p>
        </div>

        <p v-else class="text-muted-foreground">
            No tax has been calculated for this revision yet.
        </p>

        <div
            v-if="props.tax.review_reasons.length > 0"
            class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
        >
            <p class="font-medium">Tax needs review</p>
            <ul class="list-inside list-disc">
                <li v-for="reason in props.tax.review_reasons" :key="reason">
                    {{ reason.replaceAll('_', ' ') }}
                </li>
            </ul>
        </div>

        <template v-if="props.tax.can_calculate">
            <div class="grid gap-3 border-t pt-3 sm:grid-cols-3">
                <div class="space-y-1">
                    <Label for="tax_rate">Jurisdiction rate</Label>
                    <select
                        id="tax_rate"
                        v-model="selectedRateId"
                        :class="fieldClass"
                    >
                        <option value="">Select a configured rate</option>
                        <option
                            v-for="rate in props.tax.rates"
                            :key="rate.id"
                            :value="rate.id"
                        >
                            {{ rate.display_name }} ({{ rate.rate_percent }}%)
                        </option>
                    </select>
                </div>
                <div class="space-y-1">
                    <Label for="tax_certificate">Exemption certificate</Label>
                    <select
                        id="tax_certificate"
                        v-model="selectedCertificateId"
                        :class="fieldClass"
                    >
                        <option value="">None</option>
                        <option
                            v-for="certificate in props.tax.certificates"
                            :key="certificate.id"
                            :value="certificate.id"
                        >
                            {{ certificate.certificate_form_type }} ·
                            {{ certificate.jurisdiction_state }}
                        </option>
                    </select>
                </div>
                <div class="flex items-end">
                    <Button
                        size="sm"
                        :disabled="selectedRateId === ''"
                        @click="calculate"
                    >
                        Calculate tax
                    </Button>
                </div>
            </div>

            <p
                v-if="props.tax.rates.length === 0"
                class="text-xs text-muted-foreground"
            >
                No rates are configured for today. Add one in tax settings
                before calculating.
            </p>
        </template>

        <div
            v-if="props.tax.can_override"
            class="grid gap-3 border-t pt-3 sm:grid-cols-3"
        >
            <div class="space-y-1">
                <Label for="override_tax">Manual tax amount</Label>
                <Input id="override_tax" v-model="overrideAmount" />
            </div>
            <div class="space-y-1">
                <Label for="override_tax_reason">Reason</Label>
                <Input id="override_tax_reason" v-model="overrideReason" />
            </div>
            <div class="flex items-end">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="overrideAmount === '' || overrideReason === ''"
                    @click="override"
                >
                    Record override
                </Button>
            </div>
        </div>

        <div v-if="props.tax.history.length > 0" class="border-t pt-3">
            <Button
                variant="ghost"
                size="sm"
                @click="showHistory = !showHistory"
            >
                {{ showHistory ? 'Hide' : 'Show' }} calculation history ({{
                    props.tax.history.length
                }})
            </Button>

            <table v-if="showHistory" class="mt-2 w-full text-left text-xs">
                <thead class="border-b">
                    <tr>
                        <th class="py-1 font-medium">Version</th>
                        <th class="py-1 font-medium">Outcome</th>
                        <th class="py-1 font-medium">Basis</th>
                        <th class="py-1 font-medium">Rate</th>
                        <th class="py-1 font-medium">Tax</th>
                        <th class="py-1 font-medium">Source</th>
                        <th class="py-1 font-medium">When</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="entry in props.tax.history"
                        :key="entry.id"
                        class="border-b"
                    >
                        <td class="py-1">{{ entry.calculation_version }}</td>
                        <td class="py-1">
                            {{ entry.outcome.replaceAll('_', ' ') }}
                        </td>
                        <td class="py-1">${{ entry.taxable_basis }}</td>
                        <td class="py-1">
                            {{
                                entry.rate_percent
                                    ? `${entry.rate_percent}%`
                                    : '—'
                            }}
                        </td>
                        <td class="py-1">${{ entry.tax }}</td>
                        <td class="py-1">
                            {{ entry.is_override ? 'override' : entry.source }}
                        </td>
                        <td class="py-1">{{ entry.calculated_at }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="border-t pt-3 text-xs text-muted-foreground">
            {{ props.tax.disclaimer }}
        </p>
    </section>
</template>
