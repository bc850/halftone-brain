<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import QuoteApprovalPanel from '@/components/quotes/QuoteApprovalPanel.vue';
import QuoteTaxNotice from '@/components/quotes/QuoteTaxNotice.vue';
import QuoteTaxPanel from '@/components/quotes/QuoteTaxPanel.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useOrganizationSlug } from '@/composables/useTenantAction';
import { dashboard } from '@/routes';
import { index as legacyDealIndex } from '@/routes/deals';
import revisions from '@/routes/org/quotes/revisions';
import type {
    CatalogOption,
    QuoteAdjustment,
    QuoteApprovalPanel as ApprovalPanelData,
    QuoteDetail,
    QuoteLine,
    QuoteRevisionDetail,
    QuoteTaxPanel as TaxPanelData,
} from '@/types';

const props = defineProps<{
    quote: QuoteDetail;
    revision: QuoteRevisionDetail;
    catalog: CatalogOption[];
    catalogSearch: string;
    unitOfMeasureOptions: { value: string; label: string }[];
    canViewCost: boolean;
    canOverridePrice: boolean;
    canApproveBelowMinimum: boolean;
    tax: TaxPanelData;
    approval: ApprovalPanelData;
    partyEditUrl: string;
    quoteUrl: string;
}>();

const slug = useOrganizationSlug();

const routeArgs = computed<[string, number, number]>(() => [
    slug,
    props.quote.id,
    props.revision.id,
]);

const inputClass =
    'border-input bg-transparent dark:bg-input/30 h-9 w-full rounded-md border px-3 py-1 text-sm outline-none';
const textareaClass =
    'border-input bg-transparent dark:bg-input/30 w-full rounded-md border px-3 py-2 text-sm outline-none';

type DraftPayload = Record<
    string,
    string | number | boolean | null | undefined | number[]
>;

/** Every mutation carries the revision lock version the page was rendered with. */
function withLock(payload: DraftPayload = {}): DraftPayload {
    return { ...payload, expected_lock_version: props.revision.lock_version };
}

const visitOptions = { preserveScroll: true } as const;

function post(url: string, payload: DraftPayload = {}): void {
    router.post(url, withLock(payload), visitOptions);
}

/* ------------------------------------------------------------------ content */

const content = reactive({
    introduction: props.revision.introduction ?? '',
    terms_text: props.revision.terms_text ?? '',
    customer_notes: props.revision.customer_notes ?? '',
    internal_notes: props.revision.internal_notes ?? '',
    expiration_date: props.revision.expiration_date ?? '',
});

function saveContent(): void {
    router.patch(
        revisions.content.url(routeArgs.value),
        withLock({
            introduction: content.introduction || null,
            terms_text: content.terms_text || null,
            customer_notes: content.customer_notes || null,
            internal_notes: content.internal_notes || null,
            expiration_date: content.expiration_date || null,
        }),
        visitOptions,
    );
}

/* --------------------------------------------------------------- catalog add */

const catalogSearch = ref(props.catalogSearch);
const catalogDraft = reactive({
    organization_product_id: '' as number | '',
    quantity: '1',
    override_unit_price: '',
    override_reason: '',
});

let searchTimer: ReturnType<typeof setTimeout> | undefined;

watch(catalogSearch, (value) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.reload({
            data: { catalog_search: value },
            only: ['catalog', 'catalogSearch'],
        });
    }, 250);
});

const selectedCatalogOption = computed<CatalogOption | undefined>(() =>
    props.catalog.find(
        (option) => option.id === Number(catalogDraft.organization_product_id),
    ),
);

function addCatalogLine(): void {
    if (catalogDraft.organization_product_id === '') {
        return;
    }

    post(revisions.lines.catalog.url(routeArgs.value), {
        organization_product_id: catalogDraft.organization_product_id,
        quantity: catalogDraft.quantity,
        override_unit_price: catalogDraft.override_unit_price || null,
        override_reason: catalogDraft.override_reason || null,
    });

    catalogDraft.override_unit_price = '';
    catalogDraft.override_reason = '';
}

/* ---------------------------------------------------------------- custom add */

const customDraft = reactive({
    name: '',
    quantity: '1',
    unit_price: '',
    uom: '',
    reason: '',
});

function addCustomLine(): void {
    post(revisions.lines.custom.url(routeArgs.value), {
        name: customDraft.name,
        quantity: customDraft.quantity,
        unit_price: customDraft.unit_price,
        uom: customDraft.uom || null,
        reason: customDraft.reason,
    });

    customDraft.name = '';
    customDraft.unit_price = '';
    customDraft.reason = '';
}

/* ---------------------------------------------------------- section and note */

const presentationDraft = reactive({ name: '', customer_description: '' });

function addPresentationLine(kind: 'section' | 'note'): void {
    const url =
        kind === 'section'
            ? revisions.lines.section.url(routeArgs.value)
            : revisions.lines.note.url(routeArgs.value);

    post(url, {
        name: presentationDraft.name,
        customer_description: presentationDraft.customer_description || null,
    });

    presentationDraft.name = '';
    presentationDraft.customer_description = '';
}

/* ------------------------------------------------------------- line mutation */

const editingLineId = ref<number | null>(null);
const lineDraft = reactive({
    name_snapshot: '',
    quantity: '',
    final_unit_price: '',
    override_reason: '',
    is_taxable: true,
});

function startEditing(line: QuoteLine): void {
    editingLineId.value = line.id;
    lineDraft.name_snapshot = line.name_snapshot;
    lineDraft.quantity = line.quantity ?? '';
    lineDraft.final_unit_price = line.final_unit_price ?? '';
    lineDraft.override_reason = line.override_reason ?? '';
    lineDraft.is_taxable = line.is_taxable;
}

function saveLine(line: QuoteLine): void {
    const payload: DraftPayload = {
        name_snapshot: lineDraft.name_snapshot,
        is_taxable: lineDraft.is_taxable,
    };

    if (line.is_financial) {
        payload.quantity = lineDraft.quantity;

        if (props.canOverridePrice && lineDraft.final_unit_price !== '') {
            payload.final_unit_price = lineDraft.final_unit_price;
            payload.override_reason = lineDraft.override_reason || null;
        }
    }

    router.patch(
        revisions.lines.update.url([...routeArgs.value, line.id]),
        withLock(payload),
        { ...visitOptions, onSuccess: () => (editingLineId.value = null) },
    );
}

function removeLine(line: QuoteLine): void {
    if (!confirm(`Remove "${line.name_snapshot}"?`)) {
        return;
    }

    router.delete(revisions.lines.destroy.url([...routeArgs.value, line.id]), {
        ...visitOptions,
        data: withLock({}),
    });
}

function moveLine(index: number, delta: number): void {
    const ordered = props.revision.lines.map((line) => line.id);
    const target = index + delta;

    if (target < 0 || target >= ordered.length) {
        return;
    }

    [ordered[index], ordered[target]] = [ordered[target], ordered[index]];

    post(revisions.lines.reorder.url(routeArgs.value), { line_ids: ordered });
}

function repriceLine(line: QuoteLine): void {
    post(revisions.lines.reprice.url([...routeArgs.value, line.id]));
}

function resetOverride(line: QuoteLine): void {
    post(revisions.lines.resetOverride.url([...routeArgs.value, line.id]));
}

function repriceCatalog(): void {
    post(revisions.repriceCatalog.url(routeArgs.value));
}

/* -------------------------------------------------------------- adjustments */

const adjustmentDraft = reactive({
    adjustment_type: 'quote_discount',
    description: '',
    method: 'fixed',
    value: '',
    reason: '',
});

const adjustmentIsDiscount = computed(
    () => adjustmentDraft.adjustment_type === 'quote_discount',
);

function addAdjustment(): void {
    post(revisions.adjustments.store.url(routeArgs.value), {
        adjustment_type: adjustmentDraft.adjustment_type,
        description: adjustmentDraft.description,
        method: adjustmentDraft.method,
        value: adjustmentDraft.value,
        reason: adjustmentDraft.reason || null,
    });

    adjustmentDraft.description = '';
    adjustmentDraft.value = '';
    adjustmentDraft.reason = '';
}

function removeAdjustment(adjustment: QuoteAdjustment): void {
    router.delete(
        revisions.adjustments.destroy.url([...routeArgs.value, adjustment.id]),
        { ...visitOptions, data: withLock({}) },
    );
}

/* ------------------------------------------------------------------ display */

const revisionDetail = computed<QuoteRevisionDetail>(() => props.revision);
const staleLineCount = computed(
    () => props.revision.lines.filter((line) => line.catalog_stale).length,
);

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Deals', href: legacyDealIndex() },
            { title: 'Quote builder', href: legacyDealIndex() },
        ],
    },
});
</script>

<template>
    <Head
        :title="`Edit ${props.quote.quote_number} rev ${props.revision.revision_number}`"
    />

    <div class="flex flex-col gap-6 p-4">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                :title="`${props.quote.quote_number} — draft revision ${props.revision.revision_number}`"
                description="Draft builder. All totals are pre-tax."
            />
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" as-child>
                    <Link :href="props.quoteUrl">Revision history</Link>
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="revisions.show(routeArgs)">Preview</Link>
                </Button>
            </div>
        </div>

        <QuoteTaxNotice :message="revisionDetail.tax_message" />

        <!-- 1. Customer -->
        <section class="space-y-3 rounded-xl border p-4 text-sm">
            <div class="flex items-center justify-between gap-4">
                <h2 class="font-medium">Customer and addresses</h2>
                <Button variant="outline" size="sm" as-child>
                    <Link :href="props.partyEditUrl">Edit party</Link>
                </Button>
            </div>
            <div
                v-if="revisionDetail.party_snapshot"
                class="grid gap-2 sm:grid-cols-2"
            >
                <p>
                    <span class="text-muted-foreground">Company:</span>
                    {{ revisionDetail.party_snapshot.customer_company_name }}
                </p>
                <p>
                    <span class="text-muted-foreground">Contact:</span>
                    {{ revisionDetail.party_snapshot.contact_name ?? '—' }}
                </p>
                <p>
                    <span class="text-muted-foreground">Billing:</span>
                    {{
                        revisionDetail.party_snapshot.billing_address?.line1 ??
                        '—'
                    }}
                </p>
                <p>
                    <span class="text-muted-foreground">Service:</span>
                    {{
                        revisionDetail.party_snapshot.service_address?.line1 ??
                        '—'
                    }}
                </p>
            </div>
            <p v-else class="text-muted-foreground">
                No party snapshot on this revision.
            </p>
        </section>

        <!-- 2. Content -->
        <section class="space-y-3 rounded-xl border p-4 text-sm">
            <h2 class="font-medium">Quote content</h2>
            <div class="grid gap-3 lg:grid-cols-2">
                <div class="space-y-1">
                    <Label for="introduction">Introduction</Label>
                    <textarea
                        id="introduction"
                        v-model="content.introduction"
                        rows="4"
                        :class="textareaClass"
                    />
                </div>
                <div class="space-y-1">
                    <Label for="terms_text">Terms</Label>
                    <textarea
                        id="terms_text"
                        v-model="content.terms_text"
                        rows="4"
                        :class="textareaClass"
                    />
                </div>
                <div class="space-y-1">
                    <Label for="customer_notes">Customer notes</Label>
                    <textarea
                        id="customer_notes"
                        v-model="content.customer_notes"
                        rows="3"
                        :class="textareaClass"
                    />
                </div>
                <div class="space-y-1">
                    <Label for="internal_notes">Internal notes</Label>
                    <textarea
                        id="internal_notes"
                        v-model="content.internal_notes"
                        rows="3"
                        :class="textareaClass"
                    />
                </div>
                <div class="space-y-1">
                    <Label for="expiration_date">Expiration date</Label>
                    <Input
                        id="expiration_date"
                        v-model="content.expiration_date"
                        type="date"
                    />
                </div>
            </div>
            <Button size="sm" @click="saveContent">Save content</Button>
        </section>

        <!-- 3. Lines -->
        <section class="space-y-3 rounded-xl border p-4 text-sm">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-medium">Line items</h2>
                <div class="flex items-center gap-2">
                    <span
                        v-if="staleLineCount > 0"
                        class="text-xs text-amber-700 dark:text-amber-300"
                    >
                        {{ staleLineCount }} line(s) use outdated catalog
                        pricing.
                    </span>
                    <Button variant="outline" size="sm" @click="repriceCatalog">
                        Reprice all catalog lines
                    </Button>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead class="border-b">
                        <tr>
                            <th class="py-2 font-medium">Item</th>
                            <th class="py-2 font-medium">Qty</th>
                            <th class="py-2 font-medium">Unit price</th>
                            <th class="py-2 font-medium">Net</th>
                            <th
                                v-if="props.canViewCost"
                                class="py-2 font-medium"
                            >
                                Margin
                            </th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template
                            v-for="(line, index) in revisionDetail.lines"
                            :key="line.id"
                        >
                            <tr class="border-b">
                                <td class="py-2 pr-2">
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
                                        <Badge
                                            v-if="line.catalog_stale"
                                            variant="outline"
                                        >
                                            Catalog changed
                                        </Badge>
                                    </div>
                                </td>
                                <td class="py-2 pr-2">
                                    {{ line.quantity ?? '—' }}
                                </td>
                                <td class="py-2 pr-2">
                                    {{
                                        line.final_unit_price
                                            ? `$${line.final_unit_price}`
                                            : '—'
                                    }}
                                </td>
                                <td class="py-2 pr-2">
                                    {{
                                        line.net_line_total
                                            ? `$${line.net_line_total}`
                                            : '—'
                                    }}
                                </td>
                                <td v-if="props.canViewCost" class="py-2 pr-2">
                                    {{
                                        line.margin_percent
                                            ? `${line.margin_percent}%`
                                            : '—'
                                    }}
                                </td>
                                <td class="py-2 text-right whitespace-nowrap">
                                    <div class="flex justify-end gap-1">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            :disabled="index === 0"
                                            @click="moveLine(index, -1)"
                                        >
                                            ↑
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            :disabled="
                                                index ===
                                                revisionDetail.lines.length - 1
                                            "
                                            @click="moveLine(index, 1)"
                                        >
                                            ↓
                                        </Button>
                                        <Button
                                            v-if="
                                                line.line_type === 'catalog' &&
                                                line.catalog_stale
                                            "
                                            variant="ghost"
                                            size="sm"
                                            @click="repriceLine(line)"
                                        >
                                            Reprice
                                        </Button>
                                        <Button
                                            v-if="
                                                line.price_override &&
                                                line.line_type === 'catalog'
                                            "
                                            variant="ghost"
                                            size="sm"
                                            @click="resetOverride(line)"
                                        >
                                            Reset price
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            @click="startEditing(line)"
                                        >
                                            Edit
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            @click="removeLine(line)"
                                        >
                                            Remove
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                            <tr
                                v-if="editingLineId === line.id"
                                class="border-b bg-muted/30"
                            >
                                <td
                                    class="p-3"
                                    :colspan="props.canViewCost ? 6 : 5"
                                >
                                    <div class="grid gap-3 sm:grid-cols-4">
                                        <div class="space-y-1">
                                            <Label>Name</Label>
                                            <Input
                                                v-model="
                                                    lineDraft.name_snapshot
                                                "
                                            />
                                        </div>
                                        <div
                                            v-if="line.is_financial"
                                            class="space-y-1"
                                        >
                                            <Label>Quantity</Label>
                                            <Input
                                                v-model="lineDraft.quantity"
                                            />
                                        </div>
                                        <div
                                            v-if="
                                                line.is_financial &&
                                                props.canOverridePrice
                                            "
                                            class="space-y-1"
                                        >
                                            <Label>Unit price</Label>
                                            <Input
                                                v-model="
                                                    lineDraft.final_unit_price
                                                "
                                            />
                                        </div>
                                        <div
                                            v-if="
                                                line.is_financial &&
                                                props.canOverridePrice
                                            "
                                            class="space-y-1"
                                        >
                                            <Label>Override reason</Label>
                                            <Input
                                                v-model="
                                                    lineDraft.override_reason
                                                "
                                            />
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-3 pt-3">
                                        <label
                                            class="flex items-center gap-2 text-xs"
                                        >
                                            <input
                                                v-model="lineDraft.is_taxable"
                                                type="checkbox"
                                            />
                                            Taxable
                                        </label>
                                        <Button
                                            size="sm"
                                            @click="saveLine(line)"
                                        >
                                            Save line
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            @click="editingLineId = null"
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="revisionDetail.lines.length === 0">
                            <td
                                class="py-8 text-center text-muted-foreground"
                                :colspan="props.canViewCost ? 6 : 5"
                            >
                                No lines yet. Add one below.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="grid gap-4 border-t pt-4 lg:grid-cols-3">
                <div class="space-y-2">
                    <h3 class="text-xs font-medium uppercase">Catalog line</h3>
                    <Input
                        v-model="catalogSearch"
                        placeholder="Search catalog"
                    />
                    <select
                        v-model="catalogDraft.organization_product_id"
                        :class="inputClass"
                    >
                        <option value="">Select a product</option>
                        <option
                            v-for="option in props.catalog"
                            :key="option.id"
                            :value="option.id"
                        >
                            {{ option.sku }} — {{ option.display_name }}
                            <template v-if="option.unit_selling_price">
                                (${{ option.unit_selling_price }})
                            </template>
                        </option>
                    </select>
                    <Input
                        v-model="catalogDraft.quantity"
                        placeholder="Quantity"
                    />
                    <template
                        v-if="
                            props.canOverridePrice &&
                            selectedCatalogOption?.allow_price_override
                        "
                    >
                        <Input
                            v-model="catalogDraft.override_unit_price"
                            placeholder="Override unit price (optional)"
                        />
                        <Input
                            v-model="catalogDraft.override_reason"
                            placeholder="Override reason"
                        />
                        <p
                            v-if="selectedCatalogOption?.minimum_price"
                            class="text-xs text-muted-foreground"
                        >
                            Minimum price: ${{
                                selectedCatalogOption.minimum_price
                            }}
                        </p>
                    </template>
                    <Button
                        size="sm"
                        :disabled="catalogDraft.organization_product_id === ''"
                        @click="addCatalogLine"
                    >
                        Add catalog line
                    </Button>
                </div>

                <div class="space-y-2">
                    <h3 class="text-xs font-medium uppercase">Custom line</h3>
                    <template v-if="props.canOverridePrice">
                        <Input v-model="customDraft.name" placeholder="Name" />
                        <Input
                            v-model="customDraft.quantity"
                            placeholder="Quantity"
                        />
                        <Input
                            v-model="customDraft.unit_price"
                            placeholder="Unit price"
                        />
                        <select v-model="customDraft.uom" :class="inputClass">
                            <option value="">Default unit of measure</option>
                            <option
                                v-for="uom in props.unitOfMeasureOptions"
                                :key="uom.value"
                                :value="uom.value"
                            >
                                {{ uom.label }}
                            </option>
                        </select>
                        <Input
                            v-model="customDraft.reason"
                            placeholder="Reason"
                        />
                        <Button size="sm" @click="addCustomLine">
                            Add custom line
                        </Button>
                    </template>
                    <p v-else class="text-xs text-muted-foreground">
                        Custom lines require price override authority.
                    </p>
                </div>

                <div class="space-y-2">
                    <h3 class="text-xs font-medium uppercase">
                        Section or note
                    </h3>
                    <Input
                        v-model="presentationDraft.name"
                        placeholder="Heading"
                    />
                    <textarea
                        v-model="presentationDraft.customer_description"
                        rows="2"
                        :class="textareaClass"
                        placeholder="Customer-facing text"
                    />
                    <div class="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            @click="addPresentationLine('section')"
                        >
                            Add section
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            @click="addPresentationLine('note')"
                        >
                            Add note
                        </Button>
                    </div>
                </div>
            </div>
        </section>

        <!-- 4. Adjustments -->
        <section class="space-y-3 rounded-xl border p-4 text-sm">
            <h2 class="font-medium">Discounts and charges</h2>
            <ul class="divide-y">
                <li
                    v-for="adjustment in revisionDetail.adjustments"
                    :key="adjustment.id"
                    class="flex items-center justify-between gap-4 py-2"
                >
                    <div>
                        <p class="font-medium">
                            {{ adjustment.description_snapshot }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ adjustment.adjustment_type }} ·
                            {{ adjustment.method }}
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span>
                            {{ adjustment.is_discount ? '-' : '' }}${{
                                adjustment.amount
                            }}
                        </span>
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="removeAdjustment(adjustment)"
                        >
                            Remove
                        </Button>
                    </div>
                </li>
                <li
                    v-if="revisionDetail.adjustments.length === 0"
                    class="py-3 text-muted-foreground"
                >
                    No discounts or charges.
                </li>
            </ul>

            <div
                v-if="props.canOverridePrice || !adjustmentIsDiscount"
                class="grid gap-2 border-t pt-3 sm:grid-cols-5"
            >
                <select
                    v-model="adjustmentDraft.adjustment_type"
                    :class="inputClass"
                >
                    <option value="quote_discount">Quote discount</option>
                    <option value="fee">Fee</option>
                    <option value="shipping">Shipping</option>
                    <option value="installation">Installation</option>
                    <option value="other">Other</option>
                </select>
                <Input
                    v-model="adjustmentDraft.description"
                    placeholder="Description"
                />
                <select v-model="adjustmentDraft.method" :class="inputClass">
                    <option value="fixed">Fixed amount</option>
                    <option value="percentage">Percentage</option>
                </select>
                <Input
                    v-model="adjustmentDraft.value"
                    :placeholder="
                        adjustmentDraft.method === 'percentage'
                            ? 'Percent (e.g. 10)'
                            : 'Amount'
                    "
                />
                <Input
                    v-if="adjustmentIsDiscount"
                    v-model="adjustmentDraft.reason"
                    placeholder="Discount reason"
                />
                <Button
                    size="sm"
                    class="w-fit"
                    :disabled="adjustmentIsDiscount && !props.canOverridePrice"
                    @click="addAdjustment"
                >
                    Add
                </Button>
            </div>
        </section>

        <div class="grid gap-4 lg:grid-cols-2">
            <!-- 5. Internal cost/margin -->
            <section
                v-if="props.canViewCost && revisionDetail.cost_summary"
                class="space-y-2 rounded-xl border p-4 text-sm"
            >
                <h2 class="font-medium">Internal cost and margin</h2>
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground">Total cost</span>
                    <span>${{ revisionDetail.cost_summary.total_cost }}</span>
                </div>
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground">Margin</span>
                    <span>
                        ${{ revisionDetail.cost_summary.margin_amount }}
                        <template
                            v-if="revisionDetail.cost_summary.margin_percent"
                        >
                            ({{ revisionDetail.cost_summary.margin_percent }}%)
                        </template>
                    </span>
                </div>
                <p
                    v-if="!revisionDetail.cost_summary.covers_all_lines"
                    class="text-xs text-muted-foreground"
                >
                    Some lines carry no catalog cost, so this roll-up is
                    partial.
                </p>
            </section>

            <!-- 6. Totals -->
            <section class="space-y-2 rounded-xl border p-4 text-sm">
                <h2 class="font-medium">Pre-tax totals</h2>
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground">Subtotal</span>
                    <span>${{ revisionDetail.subtotal }}</span>
                </div>
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground">Discounts</span>
                    <span>-${{ revisionDetail.discount_total }}</span>
                </div>
                <div
                    class="flex justify-between gap-4 border-t pt-2 font-medium"
                >
                    <span>Pre-tax total</span>
                    <span>${{ revisionDetail.pretax_total }}</span>
                </div>
                <div
                    v-if="revisionDetail.grand_total"
                    class="flex justify-between gap-4"
                >
                    <span class="text-muted-foreground">Tax</span>
                    <span>${{ revisionDetail.tax }}</span>
                </div>
                <div
                    v-if="revisionDetail.grand_total"
                    class="flex justify-between gap-4 border-t pt-2 font-medium"
                >
                    <span>Grand total</span>
                    <span>${{ revisionDetail.grand_total }}</span>
                </div>
                <p v-else class="text-xs text-muted-foreground">
                    Taxable base so far: ${{
                        revisionDetail.provisional_taxable_amount
                    }}
                </p>
            </section>
        </div>

        <!-- 7. Tax and approval -->
        <QuoteTaxPanel
            :tax="props.tax"
            :quote-id="props.quote.id"
            :revision-id="props.revision.id"
            :lock-version="props.revision.lock_version"
        />

        <QuoteApprovalPanel
            :approval="props.approval"
            :quote-id="props.quote.id"
            :revision-id="props.revision.id"
            :lock-version="props.revision.lock_version"
            :quote-lock-version="props.quote.lock_version"
        />
    </div>
</template>
