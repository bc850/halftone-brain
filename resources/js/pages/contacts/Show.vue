<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { useTenantRoute } from '@/composables/useTenantAction';
import { dashboard } from '@/routes';
import { show as legacyShowCompany } from '@/routes/companies';
import {
    destroy as legacyDestroy,
    edit as legacyEdit,
    index as legacyIndex,
} from '@/routes/contacts';
import { create as legacyCreateDeal } from '@/routes/deals';
import { show as orgShowCompany } from '@/routes/org/companies';
import { destroy as orgDestroy, edit as orgEdit } from '@/routes/org/contacts';
import { create as orgCreateDeal } from '@/routes/org/deals';

const destroy = useTenantRoute(legacyDestroy, orgDestroy);
const edit = useTenantRoute(legacyEdit, orgEdit);
const showCompany = useTenantRoute(legacyShowCompany, orgShowCompany);
const createDeal = useTenantRoute(legacyCreateDeal, orgCreateDeal);

type Contact = {
    id: number;
    first_name: string;
    last_name: string;
    email: string | null;
    phone: string | null;
    title: string | null;
    is_primary: boolean;
    notes: string | null;
    company?: { id: number; name: string } | null;
};

const props = defineProps<{
    contact: Contact;
}>();

function deleteContact(): void {
    if (confirm('Delete this contact?')) {
        router.delete(destroy.url(props.contact.id));
    }
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Contacts', href: legacyIndex() },
            { title: 'Contact', href: legacyIndex() },
        ],
    },
});
</script>

<template>
    <Head :title="`${contact.first_name} ${contact.last_name}`" />

    <div class="flex flex-col gap-6 p-4">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                :title="`${contact.first_name} ${contact.last_name}`"
                :description="contact.title ?? undefined"
            />
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" as-child>
                    <Link :href="edit(contact.id)">
                        <Pencil class="size-4" />
                        Edit
                    </Link>
                </Button>
                <Button v-if="contact.company" as-child>
                    <Link
                        :href="
                            createDeal({
                                query: {
                                    company_id: contact.company.id,
                                    contact_id: contact.id,
                                },
                            })
                        "
                    >
                        <Plus class="size-4" />
                        Deal
                    </Link>
                </Button>
            </div>
        </div>

        <section class="max-w-xl space-y-3 rounded-xl border p-4 text-sm">
            <dl class="grid gap-2">
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Company</dt>
                    <dd>
                        <Link
                            v-if="contact.company"
                            :href="showCompany(contact.company.id)"
                            class="hover:underline"
                        >
                            {{ contact.company.name }}
                        </Link>
                        <span v-else>—</span>
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Email</dt>
                    <dd>{{ contact.email ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Phone</dt>
                    <dd>{{ contact.phone ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Primary</dt>
                    <dd>{{ contact.is_primary ? 'Yes' : 'No' }}</dd>
                </div>
            </dl>
            <p v-if="contact.notes" class="text-muted-foreground">
                {{ contact.notes }}
            </p>
        </section>

        <Button variant="destructive" class="w-fit" @click="deleteContact">
            Delete contact
        </Button>
    </div>
</template>
