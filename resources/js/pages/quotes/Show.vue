<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useOrganizationSlug } from '@/composables/useTenantAction';
import { dashboard } from '@/routes';
import { index as legacyDealIndex } from '@/routes/deals';
import { show as showDeal } from '@/routes/org/deals';
import revisions from '@/routes/org/quotes/revisions';
import type { QuoteDetail } from '@/types';

const props = defineProps<{
    quote: QuoteDetail;
}>();

const slug = useOrganizationSlug();

function revisionArgs(revisionId: number): [string, number, number] {
    return [slug, props.quote.id, revisionId];
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Deals', href: legacyDealIndex() },
            { title: 'Quote', href: legacyDealIndex() },
        ],
    },
});
</script>

<template>
    <Head :title="props.quote.quote_number" />

    <div class="flex flex-col gap-6 p-4">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                :title="props.quote.quote_number"
                :description="
                    props.quote.deal
                        ? `Deal: ${props.quote.deal.name}`
                        : undefined
                "
            />
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" as-child>
                    <Link :href="showDeal([slug, props.quote.deal_id])">
                        Back to deal
                    </Link>
                </Button>
                <Button
                    v-if="
                        props.quote.can_update &&
                        props.quote.current_revision?.is_draft
                    "
                    as-child
                >
                    <Link
                        :href="
                            revisions.edit(
                                revisionArgs(props.quote.current_revision.id),
                            )
                        "
                    >
                        Edit draft
                    </Link>
                </Button>
            </div>
        </div>

        <section class="space-y-3 rounded-xl border p-4 text-sm">
            <h2 class="font-medium">Revision history</h2>
            <table class="w-full text-left">
                <thead class="border-b">
                    <tr>
                        <th class="py-2 font-medium">Revision</th>
                        <th class="py-2 font-medium">Status</th>
                        <th class="py-2 font-medium">Pre-tax total</th>
                        <th class="py-2 font-medium">Created</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="revision in props.quote.revisions"
                        :key="revision.id"
                        class="border-b last:border-0"
                    >
                        <td class="py-2">
                            Rev {{ revision.revision_number }}
                            <Badge
                                v-if="
                                    revision.id ===
                                    props.quote.current_revision_id
                                "
                                variant="secondary"
                                class="ml-1"
                            >
                                Current
                            </Badge>
                        </td>
                        <td class="py-2">
                            <div class="flex flex-wrap gap-1">
                                <Badge variant="outline">
                                    {{ revision.status }}
                                </Badge>
                                <Badge
                                    v-if="revision.tax_pending"
                                    variant="outline"
                                >
                                    Tax pending
                                </Badge>
                            </div>
                        </td>
                        <td class="py-2">${{ revision.pretax_total }}</td>
                        <td class="py-2 text-muted-foreground">
                            {{ revision.created_at?.slice(0, 10) ?? '—' }}
                        </td>
                        <td class="py-2 text-right">
                            <Link
                                class="text-primary underline-offset-2 hover:underline"
                                :href="
                                    revisions.show(revisionArgs(revision.id))
                                "
                            >
                                View
                            </Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>
