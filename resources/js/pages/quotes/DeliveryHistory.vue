<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import QuoteDeliveryPanel from '@/components/quotes/QuoteDeliveryPanel.vue';
import { Button } from '@/components/ui/button';
import { useOrganizationSlug } from '@/composables/useTenantAction';
import { dashboard } from '@/routes';
import { index as legacyDealIndex } from '@/routes/deals';
import { show as showQuote } from '@/routes/org/quotes';
import revisions from '@/routes/org/quotes/revisions';
import type {
    QuoteDeliveryPanel as DeliveryPanelData,
    QuoteDetail,
    QuoteRevisionDetail,
} from '@/types';

const props = defineProps<{
    quote: QuoteDetail;
    revision: QuoteRevisionDetail;
    delivery: DeliveryPanelData;
    canUpdate: boolean;
}>();

const slug = useOrganizationSlug();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Deals', href: legacyDealIndex() },
            { title: 'Delivery', href: legacyDealIndex() },
        ],
    },
});
</script>

<template>
    <Head
        :title="`${props.quote.quote_number} delivery — rev ${props.revision.revision_number}`"
    />

    <div class="flex flex-col gap-6 p-4">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                :title="`${props.quote.quote_number} — delivery`"
                :description="`Revision ${props.revision.revision_number} · ${props.revision.status}`"
            />
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" as-child>
                    <Link :href="showQuote([slug, props.quote.id])">
                        Quote overview
                    </Link>
                </Button>
                <Button variant="outline" as-child>
                    <Link
                        :href="
                            revisions.show([
                                slug,
                                props.quote.id,
                                props.revision.id,
                            ])
                        "
                    >
                        Revision
                    </Link>
                </Button>
            </div>
        </div>

        <QuoteDeliveryPanel
            :delivery="props.delivery"
            :quote-id="props.quote.id"
            :revision-id="props.revision.id"
            :lock-version="props.revision.lock_version"
            :quote-lock-version="props.quote.lock_version"
        />
    </div>
</template>
