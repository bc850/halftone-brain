<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { index as legacyDealIndex } from '@/routes/deals';

/**
 * One-request customer link page.
 *
 * `customer_url` is supplied only on the Inertia::render response from prepare/
 * regenerate. It is not flashed to session. Reloading this page later cannot
 * recover the raw URL — leave and the link is gone from the UI.
 */
const props = defineProps<{
    customer_url: string;
    token_id: number;
    delivery_id: number;
    document_id: number;
    expires_at: string;
    quote_id: number;
    revision_id: number;
    quote_number: string;
    revision_number: number;
    delivery_url: string;
    revision_url: string;
}>();

const copied = ref(false);

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Deals', href: legacyDealIndex() },
            { title: 'Customer link ready', href: legacyDealIndex() },
        ],
    },
});

async function copyLink(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.customer_url);
        copied.value = true;
    } catch {
        copied.value = false;
    }
}
</script>

<template>
    <Head :title="`Customer link — ${props.quote_number}`" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            :title="`Customer link ready — ${props.quote_number}`"
            description="Copy this link now. It will not be shown again."
        />

        <div
            class="space-y-4 rounded-xl border border-amber-500/40 bg-amber-500/5 p-4 text-sm"
        >
            <p class="font-medium">
                This URL contains a one-time secret. Leaving this page loses
                access to the raw link. Copy it before you continue.
            </p>
            <div
                class="rounded-md border bg-background p-3 font-mono text-xs break-all"
            >
                {{ props.customer_url }}
            </div>
            <div class="flex flex-wrap gap-2">
                <Button type="button" @click="copyLink">
                    {{ copied ? 'Copied' : 'Copy link' }}
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="props.delivery_url">Back to delivery</Link>
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="props.revision_url">Back to revision</Link>
                </Button>
            </div>
            <p class="text-muted-foreground">
                Expires {{ props.expires_at }} · Token #{{ props.token_id }} ·
                Delivery #{{ props.delivery_id }} · Document #{{
                    props.document_id
                }}
            </p>
        </div>
    </div>
</template>
