<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useOrganizationSlug } from '@/composables/useTenantAction';
import { dashboard } from '@/routes';
import { profile as updateProfile } from '@/routes/org/tax-settings';
import taxRateRoutes from '@/routes/org/tax-settings/rates';
import type { TaxProfile, TaxRate } from '@/types';

const props = defineProps<{
    profile: TaxProfile | null;
    rates: TaxRate[];
    sourcingStrategies: { value: string; label: string }[];
    canManage: boolean;
    disclaimer: string;
}>();

const slug = useOrganizationSlug();

const fieldClass =
    'border-input bg-transparent dark:bg-input/30 h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs outline-none';

const taxCalculationEnabled = ref(
    props.profile?.tax_calculation_enabled ?? true,
);
const profileIsActive = ref(props.profile?.is_active ?? true);
const sourcingStrategy = ref(props.profile?.sourcing_strategy ?? 'delivery');

/** A rate is never edited into a different rate, so each row opens its own panel. */
const editingRateId = ref<number | null>(null);
const supersedingRateId = ref<number | null>(null);

const today = new Date().toISOString().slice(0, 10);

const activeRates = computed(() =>
    props.rates.filter((rate) => rate.is_active),
);
const retiredRates = computed(() =>
    props.rates.filter((rate) => !rate.is_active),
);

function coversToday(rate: TaxRate): boolean {
    return (
        rate.is_active &&
        rate.effective_from <= today &&
        (rate.effective_through === null || rate.effective_through >= today)
    );
}

function togglePanel(target: 'edit' | 'supersede', rate: TaxRate): void {
    if (target === 'edit') {
        supersedingRateId.value = null;
        editingRateId.value = editingRateId.value === rate.id ? null : rate.id;

        return;
    }

    editingRateId.value = null;
    supersedingRateId.value =
        supersedingRateId.value === rate.id ? null : rate.id;
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Tax settings', href: '#' },
        ],
    },
});
</script>

<template>
    <Head title="Tax settings" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Tax settings"
            description="Jurisdiction rates and the profile that decides how quotes are sourced."
        />

        <p
            class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
        >
            {{ props.disclaimer }}
        </p>

        <!-- Profile -->
        <section class="space-y-4 rounded-xl border p-4">
            <div class="space-y-1">
                <h2 class="font-medium">Tax profile</h2>
                <p class="text-sm text-muted-foreground">
                    Applies to every quote in this organization. Changing it
                    does not re-tax quotes that already resolved.
                </p>
            </div>

            <Form
                v-if="props.canManage"
                v-bind="updateProfile.form(slug)"
                class="grid gap-4"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="default_country">Default country</Label>
                        <Input
                            id="default_country"
                            name="default_country"
                            maxlength="2"
                            :default-value="
                                props.profile?.default_country ?? 'US'
                            "
                        />
                        <InputError :message="errors.default_country" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="default_state">Default state</Label>
                        <Input
                            id="default_state"
                            name="default_state"
                            :default-value="props.profile?.default_state ?? ''"
                        />
                        <InputError :message="errors.default_state" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="sourcing_strategy">Sourcing</Label>
                        <select
                            id="sourcing_strategy"
                            name="sourcing_strategy"
                            :class="fieldClass"
                            v-model="sourcingStrategy"
                        >
                            <option
                                v-for="strategy in props.sourcingStrategies"
                                :key="strategy.value"
                                :value="strategy.value"
                            >
                                {{ strategy.label }}
                            </option>
                        </select>
                        <InputError :message="errors.sourcing_strategy" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="registration_reference">
                            Registration reference
                        </Label>
                        <Input
                            id="registration_reference"
                            name="registration_reference"
                            :default-value="
                                props.profile?.registration_reference ?? ''
                            "
                        />
                        <InputError :message="errors.registration_reference" />
                    </div>
                </div>

                <input
                    type="hidden"
                    name="tax_calculation_enabled"
                    :value="taxCalculationEnabled ? '1' : '0'"
                />
                <input
                    type="hidden"
                    name="is_active"
                    :value="profileIsActive ? '1' : '0'"
                />
                <div class="flex flex-wrap gap-6">
                    <label class="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            value="1"
                            v-model="taxCalculationEnabled"
                        />
                        Calculate tax on quotes
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            value="1"
                            v-model="profileIsActive"
                        />
                        Profile is active
                    </label>
                </div>

                <p
                    v-if="!taxCalculationEnabled"
                    class="text-sm text-muted-foreground"
                >
                    With calculation off, quotes resolve to review required
                    rather than to a taxable amount.
                </p>

                <InputError :message="errors.profile" />

                <Button type="submit" class="w-fit" :disabled="processing">
                    Save tax profile
                </Button>
            </Form>

            <div
                v-else-if="props.profile"
                class="grid gap-2 text-sm sm:grid-cols-2"
            >
                <p>
                    <span class="text-muted-foreground">Country:</span>
                    {{ props.profile.default_country }}
                </p>
                <p>
                    <span class="text-muted-foreground">State:</span>
                    {{ props.profile.default_state ?? '—' }}
                </p>
                <p>
                    <span class="text-muted-foreground">Sourcing:</span>
                    {{ props.profile.sourcing_strategy_label }}
                </p>
                <p>
                    <span class="text-muted-foreground">Calculation:</span>
                    {{ props.profile.tax_calculation_enabled ? 'On' : 'Off' }}
                </p>
            </div>

            <p v-else class="text-sm text-muted-foreground">
                No tax profile configured yet.
            </p>
        </section>

        <!-- Rates -->
        <section class="space-y-4 rounded-xl border p-4">
            <div class="space-y-1">
                <h2 class="font-medium">Jurisdiction rates</h2>
                <p class="text-sm text-muted-foreground">
                    A rate is never edited into a different rate. Supersede it
                    so quotes taxed under the old one stay explainable.
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b">
                        <tr>
                            <th class="py-2 font-medium">Jurisdiction</th>
                            <th class="py-2 font-medium">Rate</th>
                            <th class="py-2 font-medium">Effective</th>
                            <th class="py-2 font-medium">Status</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template
                            v-for="rate in [...activeRates, ...retiredRates]"
                            :key="rate.id"
                        >
                            <tr
                                class="border-b"
                                :class="{ 'opacity-60': !rate.is_active }"
                            >
                                <td class="py-2 pr-2">
                                    <div class="font-medium">
                                        {{ rate.display_name }}
                                    </div>
                                    <div class="text-xs text-muted-foreground">
                                        {{ rate.jurisdiction_code }}
                                    </div>
                                </td>
                                <td class="py-2 pr-2">
                                    {{ rate.rate_percent }}%
                                </td>
                                <td class="py-2 pr-2">
                                    {{ rate.effective_from }} —
                                    {{ rate.effective_through ?? 'open' }}
                                </td>
                                <td class="py-2 pr-2">
                                    <Badge
                                        v-if="coversToday(rate)"
                                        variant="secondary"
                                    >
                                        In effect
                                    </Badge>
                                    <Badge
                                        v-else-if="rate.is_active"
                                        variant="outline"
                                    >
                                        Scheduled or past
                                    </Badge>
                                    <Badge v-else variant="outline">
                                        Retired
                                    </Badge>
                                </td>
                                <td class="py-2 text-right whitespace-nowrap">
                                    <div
                                        v-if="props.canManage"
                                        class="flex justify-end gap-1"
                                    >
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            @click="togglePanel('edit', rate)"
                                        >
                                            Edit labels
                                        </Button>
                                        <Button
                                            v-if="rate.is_active"
                                            variant="ghost"
                                            size="sm"
                                            @click="
                                                togglePanel('supersede', rate)
                                            "
                                        >
                                            Supersede
                                        </Button>
                                        <Form
                                            v-if="rate.is_active"
                                            v-bind="
                                                taxRateRoutes.deactivate.form([
                                                    slug,
                                                    rate.id,
                                                ])
                                            "
                                        >
                                            <Button
                                                type="submit"
                                                variant="ghost"
                                                size="sm"
                                            >
                                                Deactivate
                                            </Button>
                                        </Form>
                                    </div>
                                </td>
                            </tr>

                            <tr
                                v-if="editingRateId === rate.id"
                                class="border-b bg-muted/30"
                            >
                                <td class="p-3" colspan="5">
                                    <Form
                                        v-bind="
                                            taxRateRoutes.update.form([
                                                slug,
                                                rate.id,
                                            ])
                                        "
                                        class="grid gap-3 sm:grid-cols-4"
                                        v-slot="{
                                            errors: editErrors,
                                            processing: savingEdit,
                                        }"
                                    >
                                        <div class="grid gap-2">
                                            <Label>Display name</Label>
                                            <Input
                                                name="display_name"
                                                :default-value="
                                                    rate.display_name
                                                "
                                            />
                                            <InputError
                                                :message="
                                                    editErrors.display_name
                                                "
                                            />
                                        </div>
                                        <div class="grid gap-2">
                                            <Label>Effective through</Label>
                                            <Input
                                                name="effective_through"
                                                type="date"
                                                :default-value="
                                                    rate.effective_through ?? ''
                                                "
                                            />
                                            <InputError
                                                :message="
                                                    editErrors.effective_through
                                                "
                                            />
                                        </div>
                                        <div class="grid gap-2 sm:col-span-2">
                                            <Label>Source note</Label>
                                            <Input
                                                name="source_note"
                                                :default-value="
                                                    rate.source_note ?? ''
                                                "
                                            />
                                            <InputError
                                                :message="editErrors.rate"
                                            />
                                        </div>
                                        <Button
                                            type="submit"
                                            size="sm"
                                            class="w-fit"
                                            :disabled="savingEdit"
                                        >
                                            Save labels
                                        </Button>
                                    </Form>
                                </td>
                            </tr>

                            <tr
                                v-if="supersedingRateId === rate.id"
                                class="border-b bg-muted/30"
                            >
                                <td class="p-3" colspan="5">
                                    <Form
                                        v-bind="
                                            taxRateRoutes.supersede.form([
                                                slug,
                                                rate.id,
                                            ])
                                        "
                                        class="grid gap-3 sm:grid-cols-4"
                                        v-slot="{
                                            errors: supersedeErrors,
                                            processing: saving,
                                        }"
                                    >
                                        <div class="grid gap-2">
                                            <Label>New rate percent</Label>
                                            <Input
                                                name="rate_percent"
                                                placeholder="8.25"
                                            />
                                            <InputError
                                                :message="
                                                    supersedeErrors.rate_percent
                                                "
                                            />
                                        </div>
                                        <div class="grid gap-2">
                                            <Label>Effective from</Label>
                                            <Input
                                                name="effective_from"
                                                type="date"
                                            />
                                            <InputError
                                                :message="
                                                    supersedeErrors.effective_from
                                                "
                                            />
                                        </div>
                                        <div class="grid gap-2 sm:col-span-2">
                                            <Label>Source note</Label>
                                            <Input name="source_note" />
                                            <InputError
                                                :message="supersedeErrors.rate"
                                            />
                                        </div>
                                        <Button
                                            type="submit"
                                            size="sm"
                                            class="w-fit"
                                            :disabled="saving"
                                        >
                                            Supersede rate
                                        </Button>
                                    </Form>
                                </td>
                            </tr>
                        </template>

                        <tr v-if="props.rates.length === 0">
                            <td
                                class="py-8 text-center text-muted-foreground"
                                colspan="5"
                            >
                                No rates configured. Quotes cannot resolve tax
                                until at least one exists.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Form
                v-if="props.canManage"
                v-bind="taxRateRoutes.store.form(slug)"
                class="grid gap-3 border-t pt-4 sm:grid-cols-3"
                v-slot="{ errors: rateErrors, processing: addingRate }"
            >
                <h3 class="text-xs font-medium uppercase sm:col-span-3">
                    Add a rate
                </h3>
                <div class="grid gap-2">
                    <Label for="jurisdiction_code">Jurisdiction code</Label>
                    <Input
                        id="jurisdiction_code"
                        name="jurisdiction_code"
                        placeholder="US-TX-TRAVIS"
                    />
                    <InputError :message="rateErrors.jurisdiction_code" />
                </div>
                <div class="grid gap-2">
                    <Label for="rate_display_name">Display name</Label>
                    <Input
                        id="rate_display_name"
                        name="display_name"
                        placeholder="Travis County"
                    />
                    <InputError :message="rateErrors.display_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="rate_percent">Rate percent</Label>
                    <Input
                        id="rate_percent"
                        name="rate_percent"
                        placeholder="8.25"
                    />
                    <InputError :message="rateErrors.rate_percent" />
                </div>
                <div class="grid gap-2">
                    <Label for="rate_country">Country</Label>
                    <Input
                        id="rate_country"
                        name="country"
                        maxlength="2"
                        default-value="US"
                    />
                    <InputError :message="rateErrors.country" />
                </div>
                <div class="grid gap-2">
                    <Label for="rate_state">State</Label>
                    <Input id="rate_state" name="state" placeholder="TX" />
                    <InputError :message="rateErrors.state" />
                </div>
                <div class="grid gap-2">
                    <Label for="rate_county">County</Label>
                    <Input id="rate_county" name="county" />
                    <InputError :message="rateErrors.county" />
                </div>
                <div class="grid gap-2">
                    <Label for="rate_city">City</Label>
                    <Input id="rate_city" name="city" />
                    <InputError :message="rateErrors.city" />
                </div>
                <div class="grid gap-2">
                    <Label for="rate_effective_from">Effective from</Label>
                    <Input
                        id="rate_effective_from"
                        name="effective_from"
                        type="date"
                    />
                    <InputError :message="rateErrors.effective_from" />
                </div>
                <div class="grid gap-2">
                    <Label for="rate_effective_through">
                        Effective through
                    </Label>
                    <Input
                        id="rate_effective_through"
                        name="effective_through"
                        type="date"
                    />
                    <InputError :message="rateErrors.effective_through" />
                </div>
                <div class="grid gap-2 sm:col-span-3">
                    <Label for="rate_source_note">Source note</Label>
                    <Input
                        id="rate_source_note"
                        name="source_note"
                        placeholder="Where this rate was verified"
                    />
                    <InputError :message="rateErrors.source_note" />
                    <InputError :message="rateErrors.rate" />
                </div>
                <Button
                    type="submit"
                    size="sm"
                    class="w-fit"
                    :disabled="addingRate"
                >
                    Add rate
                </Button>
            </Form>
        </section>
    </div>
</template>
