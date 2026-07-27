<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import QuoteTaxNotice from '@/components/quotes/QuoteTaxNotice.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useOrganizationSlug } from '@/composables/useTenantAction';
import { dashboard } from '@/routes';
import { index as legacyDealIndex } from '@/routes/deals';
import { show as showQuote } from '@/routes/org/quotes';
import revisions from '@/routes/org/quotes/revisions';
import type { QuoteDetail, QuoteRevisionDetail } from '@/types';

const props = defineProps<{
    quote: QuoteDetail;
    revision: QuoteRevisionDetail;
    canViewCost: boolean;
    canUpdate: boolean;
}>();

const slug = useOrganizationSlug();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Deals', href: legacyDealIndex() },
            { title: 'Quote revision', href: legacyDealIndex() },
        ],
    },
});
</script>

<template>
    <Head
        :title="`${props.quote.quote_number} rev ${props.revision.revision_number}`"
    />

    <div class="flex flex-col gap-6 p-4">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                :title="`${props.quote.quote_number} — revision ${props.revision.revision_number}`"
                :description="`Status: ${props.revision.status}. Totals are pre-tax.`"
            />
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" as-child>
                    <Link :href="showQuote([slug, props.quote.id])">
                        Quote overview
                    </Link>
                </Button>
                <Button
                    v-if="props.canUpdate && props.revision.is_draft"
                    as-child
                >
                    <Link
                        :href="
                            revisions.edit([
                                slug,
                                props.quote.id,
                                props.revision.id,
                            ])
                        "
                    >
                        Edit draft
                    </Link>
                </Button>
            </div>
        </div>

        <QuoteTaxNotice :message="props.revision.tax_message" />

        <section class="space-y-3 rounded-xl border p-4 text-sm">
            <h2 class="font-medium">Customer</h2>
            <dl class="grid gap-2 sm:grid-cols-2">
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Company</dt>
                    <dd>
                        {{
                            props.revision.party_snapshot
                                ?.customer_company_name ?? '—'
                        }}
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Contact</dt>
                    <dd>
                        {{ props.revision.party_snapshot?.contact_name ?? '—' }}
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Customer PO</dt>
                    <dd>
                        {{
                            props.revision.party_snapshot
                                ?.customer_po_reference ?? '—'
                        }}
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Expires</dt>
                    <dd>{{ props.revision.expiration_date ?? '—' }}</dd>
                </div>
            </dl>
        </section>

        <section class="overflow-x-auto rounded-xl border">
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-muted/50">
                    <tr>
                        <th class="px-3 py-2 font-medium">Item</th>
                        <th class="px-3 py-2 font-medium">Qty</th>
                        <th class="px-3 py-2 font-medium">Unit price</th>
                        <th class="px-3 py-2 font-medium">Net</th>
                        <th
                            v-if="props.canViewCost"
                            class="px-3 py-2 font-medium"
                        >
                            Unit cost
                        </th>
                        <th
                            v-if="props.canViewCost"
                            class="px-3 py-2 font-medium"
                        >
                            Margin
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="line in props.revision.lines"
                        :key="line.id"
                        class="border-b last:border-0"
                    >
                        <td class="px-3 py-2">
                            <div class="font-medium">
                                {{ line.name_snapshot }}
                            </div>
                            <div class="flex flex-wrap gap-1 pt-1">
                                <Badge variant="outline">
                                    {{ line.line_type }}
                                </Badge>
                                <Badge
                                    v-if="line.price_override"
                                    variant="secondary"
                                >
                                    Override
                                </Badge>
                                <Badge
                                    v-if="line.below_minimum"
                                    variant="destructive"
                                >
                                    Below minimum
                                </Badge>
                            </div>
                        </td>
                        <td class="px-3 py-2">{{ line.quantity ?? '—' }}</td>
                        <td class="px-3 py-2">
                            {{
                                line.final_unit_price
                                    ? `$${line.final_unit_price}`
                                    : '—'
                            }}
                        </td>
                        <td class="px-3 py-2">
                            {{
                                line.net_line_total
                                    ? `$${line.net_line_total}`
                                    : '—'
                            }}
                        </td>
                        <td v-if="props.canViewCost" class="px-3 py-2">
                            {{ line.unit_cost ? `$${line.unit_cost}` : '—' }}
                        </td>
                        <td v-if="props.canViewCost" class="px-3 py-2">
                            {{
                                line.margin_percent
                                    ? `${line.margin_percent}%`
                                    : '—'
                            }}
                        </td>
                    </tr>
                    <tr v-if="props.revision.lines.length === 0">
                        <td
                            class="px-3 py-8 text-center text-muted-foreground"
                            :colspan="props.canViewCost ? 6 : 4"
                        >
                            No lines on this revision.
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section
            class="ml-auto w-full max-w-sm space-y-2 rounded-xl border p-4 text-sm"
        >
            <div class="flex justify-between gap-4">
                <span class="text-muted-foreground">Subtotal (pre-tax)</span>
                <span>${{ props.revision.subtotal }}</span>
            </div>
            <div class="flex justify-between gap-4">
                <span class="text-muted-foreground">Discounts</span>
                <span>-${{ props.revision.discount_total }}</span>
            </div>
            <div class="flex justify-between gap-4 border-t pt-2 font-medium">
                <span>Pre-tax total</span>
                <span>${{ props.revision.pretax_total }}</span>
            </div>
        </section>
    </div>
</template>
