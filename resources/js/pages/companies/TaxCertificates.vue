<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useOrganizationSlug } from '@/composables/useTenantAction';
import { dashboard } from '@/routes';
import { index as legacyCompaniesIndex } from '@/routes/companies';
import taxCertificates from '@/routes/org/companies/tax-certificates';
import type { TaxCertificate } from '@/types';

const props = defineProps<{
    company: { id: number; name: string; organization_company_id: number };
    certificates: TaxCertificate[];
    exemptionCategories: { value: string; label: string }[];
    canManage: boolean;
    canViewEvidence: boolean;
    companyUrl: string;
}>();

const slug = useOrganizationSlug();

const fieldClass =
    'border-input bg-transparent dark:bg-input/30 h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs outline-none';

const editingId = ref<number | null>(null);
const decidingId = ref<number | null>(null);
const decision = ref<'reject' | 'revoke'>('reject');

const usable = computed(() =>
    props.certificates.filter(
        (certificate) => certificate.can_support_exemption,
    ),
);

function statusVariant(
    certificate: TaxCertificate,
): 'secondary' | 'outline' | 'destructive' {
    if (certificate.can_support_exemption) {
        return 'secondary';
    }

    return certificate.verification_status === 'pending'
        ? 'outline'
        : 'destructive';
}

function startDecision(
    certificate: TaxCertificate,
    kind: 'reject' | 'revoke',
): void {
    editingId.value = null;
    decision.value = kind;
    decidingId.value =
        decidingId.value === certificate.id ? null : certificate.id;
}

function decisionAction(certificateId: number) {
    return decision.value === 'reject'
        ? taxCertificates.reject.form([slug, props.company.id, certificateId])
        : taxCertificates.revoke.form([slug, props.company.id, certificateId]);
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Companies', href: legacyCompaniesIndex() },
            { title: 'Tax certificates', href: '#' },
        ],
    },
});
</script>

<template>
    <Head :title="`Tax certificates · ${props.company.name}`" />

    <div class="flex flex-col gap-6 p-4">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                title="Exemption certificates"
                :description="props.company.name"
            />
            <Button variant="outline" as-child>
                <Link :href="props.companyUrl">Back to company</Link>
            </Button>
        </div>

        <p
            v-if="usable.length === 0"
            class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
        >
            No verified certificate is on file, so quotes for this customer are
            taxed unless one is recorded and verified.
        </p>

        <p v-if="!props.canViewEvidence" class="text-sm text-muted-foreground">
            Certificate numbers, evidence, and notes are hidden. Viewing them
            requires certificate authority.
        </p>

        <section class="space-y-4 rounded-xl border p-4 text-sm">
            <h2 class="font-medium">On file</h2>

            <ul class="divide-y">
                <li
                    v-for="certificate in props.certificates"
                    :key="certificate.id"
                    class="space-y-3 py-3"
                >
                    <div
                        class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div class="space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium">
                                    {{ certificate.certificate_form_type }}
                                </span>
                                <Badge :variant="statusVariant(certificate)">
                                    {{ certificate.verification_status_label }}
                                </Badge>
                                <Badge variant="outline">
                                    {{ certificate.jurisdiction_state }}
                                </Badge>
                            </div>
                            <p class="text-muted-foreground">
                                {{ certificate.exemption_category_label }} ·
                                {{ certificate.effective_date }} —
                                {{ certificate.expiration_date ?? 'no expiry' }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ certificate.certificate_reference }}
                                <template v-if="certificate.has_evidence">
                                    · evidence on file
                                </template>
                            </p>
                            <template v-if="props.canViewEvidence">
                                <p v-if="certificate.certificate_number">
                                    <span class="text-muted-foreground">
                                        Number:
                                    </span>
                                    {{ certificate.certificate_number }}
                                </p>
                                <p v-if="certificate.evidence_reference">
                                    <span class="text-muted-foreground">
                                        Evidence:
                                    </span>
                                    {{ certificate.evidence_reference }}
                                </p>
                                <p v-if="certificate.internal_notes">
                                    <span class="text-muted-foreground">
                                        Notes:
                                    </span>
                                    {{ certificate.internal_notes }}
                                </p>
                                <p v-if="certificate.rejection_reason">
                                    <span class="text-muted-foreground">
                                        Reason:
                                    </span>
                                    {{ certificate.rejection_reason }}
                                </p>
                            </template>
                        </div>

                        <div
                            v-if="props.canManage"
                            class="flex flex-wrap gap-1"
                        >
                            <Button
                                v-if="certificate.is_editable"
                                variant="ghost"
                                size="sm"
                                @click="
                                    editingId =
                                        editingId === certificate.id
                                            ? null
                                            : certificate.id
                                "
                            >
                                Edit
                            </Button>
                            <Form
                                v-if="certificate.is_editable"
                                v-bind="
                                    taxCertificates.verify.form([
                                        slug,
                                        props.company.id,
                                        certificate.id,
                                    ])
                                "
                            >
                                <Button type="submit" variant="ghost" size="sm">
                                    Verify
                                </Button>
                            </Form>
                            <Button
                                v-if="certificate.is_editable"
                                variant="ghost"
                                size="sm"
                                @click="startDecision(certificate, 'reject')"
                            >
                                Reject
                            </Button>
                            <Button
                                v-if="certificate.can_support_exemption"
                                variant="ghost"
                                size="sm"
                                @click="startDecision(certificate, 'revoke')"
                            >
                                Revoke
                            </Button>
                            <Form
                                v-if="certificate.can_support_exemption"
                                v-bind="
                                    taxCertificates.markExpired.form([
                                        slug,
                                        props.company.id,
                                        certificate.id,
                                    ])
                                "
                            >
                                <Button type="submit" variant="ghost" size="sm">
                                    Mark expired
                                </Button>
                            </Form>
                        </div>
                    </div>

                    <Form
                        v-if="editingId === certificate.id"
                        v-bind="
                            taxCertificates.update.form([
                                slug,
                                props.company.id,
                                certificate.id,
                            ])
                        "
                        class="grid gap-3 rounded-lg bg-muted/30 p-3 sm:grid-cols-3"
                        v-slot="{ errors, processing }"
                    >
                        <div class="grid gap-2">
                            <Label>Form type</Label>
                            <Input
                                name="certificate_form_type"
                                :default-value="
                                    certificate.certificate_form_type
                                "
                            />
                            <InputError
                                :message="errors.certificate_form_type"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label>Jurisdiction state</Label>
                            <Input
                                name="jurisdiction_state"
                                :default-value="certificate.jurisdiction_state"
                            />
                            <InputError :message="errors.jurisdiction_state" />
                        </div>
                        <div class="grid gap-2">
                            <Label>Expiration date</Label>
                            <Input
                                name="expiration_date"
                                type="date"
                                :default-value="
                                    certificate.expiration_date ?? ''
                                "
                            />
                            <InputError :message="errors.expiration_date" />
                        </div>
                        <div
                            v-if="props.canViewEvidence"
                            class="grid gap-2 sm:col-span-2"
                        >
                            <Label>Certificate number</Label>
                            <Input
                                name="certificate_number"
                                :default-value="
                                    certificate.certificate_number ?? ''
                                "
                            />
                            <InputError :message="errors.certificate_number" />
                        </div>
                        <div class="grid gap-2 sm:col-span-3">
                            <InputError :message="errors.certificate" />
                            <Button
                                type="submit"
                                size="sm"
                                class="w-fit"
                                :disabled="processing"
                            >
                                Save certificate
                            </Button>
                        </div>
                    </Form>

                    <Form
                        v-if="decidingId === certificate.id"
                        v-bind="decisionAction(certificate.id)"
                        class="grid gap-3 rounded-lg bg-muted/30 p-3"
                        v-slot="{
                            errors: decisionErrors,
                            processing: deciding,
                        }"
                    >
                        <Label>
                            Why is this certificate being
                            {{
                                decision === 'reject' ? 'rejected' : 'revoked'
                            }}?
                        </Label>
                        <Input name="reason" />
                        <InputError :message="decisionErrors.reason" />
                        <InputError :message="decisionErrors.certificate" />
                        <Button
                            type="submit"
                            size="sm"
                            class="w-fit"
                            :disabled="deciding"
                        >
                            {{ decision === 'reject' ? 'Reject' : 'Revoke' }}
                        </Button>
                    </Form>
                </li>

                <li
                    v-if="props.certificates.length === 0"
                    class="py-6 text-center text-muted-foreground"
                >
                    No certificates on file.
                </li>
            </ul>
        </section>

        <Form
            v-if="props.canManage"
            v-bind="taxCertificates.store.form([slug, props.company.id])"
            class="grid gap-3 rounded-xl border p-4 text-sm sm:grid-cols-3"
            v-slot="{ errors: newErrors, processing: recording }"
        >
            <h2 class="font-medium sm:col-span-3">Record a certificate</h2>
            <div class="grid gap-2">
                <Label for="exemption_category">Exemption category</Label>
                <select
                    id="exemption_category"
                    name="exemption_category"
                    :class="fieldClass"
                >
                    <option
                        v-for="category in props.exemptionCategories"
                        :key="category.value"
                        :value="category.value"
                    >
                        {{ category.label }}
                    </option>
                </select>
                <InputError :message="newErrors.exemption_category" />
            </div>
            <div class="grid gap-2">
                <Label for="new_jurisdiction_state">Jurisdiction state</Label>
                <Input
                    id="new_jurisdiction_state"
                    name="jurisdiction_state"
                    placeholder="TX"
                />
                <InputError :message="newErrors.jurisdiction_state" />
            </div>
            <div class="grid gap-2">
                <Label for="new_form_type">Form type</Label>
                <Input
                    id="new_form_type"
                    name="certificate_form_type"
                    placeholder="Texas 01-339"
                />
                <InputError :message="newErrors.certificate_form_type" />
            </div>
            <div class="grid gap-2">
                <Label for="new_certificate_number">Certificate number</Label>
                <Input id="new_certificate_number" name="certificate_number" />
                <InputError :message="newErrors.certificate_number" />
            </div>
            <div class="grid gap-2">
                <Label for="new_effective_date">Effective date</Label>
                <Input
                    id="new_effective_date"
                    name="effective_date"
                    type="date"
                />
                <InputError :message="newErrors.effective_date" />
            </div>
            <div class="grid gap-2">
                <Label for="new_expiration_date">Expiration date</Label>
                <Input
                    id="new_expiration_date"
                    name="expiration_date"
                    type="date"
                />
                <InputError :message="newErrors.expiration_date" />
            </div>
            <div class="grid gap-2 sm:col-span-3">
                <Label for="new_evidence_reference">Evidence reference</Label>
                <Input
                    id="new_evidence_reference"
                    name="evidence_reference"
                    placeholder="Where the signed certificate is stored"
                />
                <InputError :message="newErrors.evidence_reference" />
                <InputError :message="newErrors.certificate" />
            </div>
            <Button type="submit" size="sm" class="w-fit" :disabled="recording">
                Record certificate
            </Button>
        </Form>
    </div>
</template>
