<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useOrganizationSlug } from '@/composables/useTenantAction';
import { dashboard } from '@/routes';
import { index as legacyDealIndex } from '@/routes/deals';
import { show as showDeal } from '@/routes/org/deals';
import { create as createQuote } from '@/routes/org/deals/quotes';
import { show as showQuote } from '@/routes/org/quotes';
import type { QuoteSummary } from '@/types';

const props = defineProps<{
    deal: { id: number; name: string };
    quotes: QuoteSummary[];
    canCreate: boolean;
}>();

const slug = useOrganizationSlug();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Deals', href: legacyDealIndex() },
            { title: 'Quotes', href: legacyDealIndex() },
        ],
    },
});
</script>

<template>
    <Head :title="`Quotes — ${deal.name}`" />

    <div class="flex flex-col gap-6 p-4">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <Heading
                title="Quotes"
                :description="`Quotes for ${deal.name}. Totals shown are pre-tax.`"
            />
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" as-child>
                    <Link :href="showDeal([slug, deal.id])">Back to deal</Link>
                </Button>
                <Button v-if="canCreate" as-child>
                    <Link :href="createQuote([slug, deal.id])">
                        <Plus class="size-4" />
                        New quote
                    </Link>
                </Button>
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-muted/50">
                    <tr>
                        <th class="px-3 py-2 font-medium">Quote</th>
                        <th class="px-3 py-2 font-medium">Lifecycle</th>
                        <th class="px-3 py-2 font-medium">Current revision</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                        <th class="px-3 py-2 font-medium">Pre-tax total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="quote in props.quotes"
                        :key="quote.id"
                        class="border-b hover:bg-muted/30"
                    >
                        <td class="px-3 py-2">
                            <Link
                                class="font-medium text-primary underline-offset-2 hover:underline"
                                :href="showQuote([slug, quote.id])"
                            >
                                {{ quote.quote_number }}
                            </Link>
                        </td>
                        <td class="px-3 py-2 capitalize">
                            {{ quote.lifecycle_status }}
                        </td>
                        <td class="px-3 py-2">
                            {{
                                quote.current_revision
                                    ? `Rev ${quote.current_revision.revision_number}`
                                    : '—'
                            }}
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex flex-wrap gap-1">
                                <Badge variant="secondary">
                                    {{ quote.current_revision?.status ?? '—' }}
                                </Badge>
                                <Badge
                                    v-if="quote.current_revision?.tax_pending"
                                    variant="outline"
                                >
                                    Tax pending
                                </Badge>
                                <Badge
                                    v-if="
                                        quote.current_revision
                                            ?.approval_required
                                    "
                                    variant="destructive"
                                >
                                    Approval required
                                </Badge>
                            </div>
                        </td>
                        <td class="px-3 py-2">
                            {{
                                quote.current_revision
                                    ? `$${quote.current_revision.pretax_total}`
                                    : '—'
                            }}
                        </td>
                    </tr>
                    <tr v-if="props.quotes.length === 0">
                        <td
                            class="px-3 py-8 text-center text-muted-foreground"
                            colspan="5"
                        >
                            No quotes on this deal yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
