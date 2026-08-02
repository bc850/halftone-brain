<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useOrganizationSlug } from '@/composables/useTenantAction';
import { dashboard } from '@/routes';
import monday from '@/routes/org/integrations/settings/monday';

type MappingField = {
    column_id: string;
    expected_type: string;
};

type OptionalMappingField = MappingField & {
    enabled: boolean;
};

type MondaySettings = {
    id: number;
    enabled: boolean;
    api_version: string;
    board_id: string;
    group_id: string;
    item_name_template: string;
    line_detail_mode: string;
    required_mappings: Record<string, MappingField>;
    optional_mappings: Record<string, OptionalMappingField>;
    intake_status_label: string;
    last_validated_at: string | null;
    last_validation_status: string;
    last_validation_error_code: string | null;
    last_validation_error_message: string | null;
    lock_version: number;
    can_enable: boolean;
};

type KeyOption = { key: string; label: string };
type Option = { value: string; label: string };

const props = defineProps<{
    settings: MondaySettings | null;
    defaults: {
        api_version: string;
        item_name_template: string;
        line_detail_mode: string;
        intake_status_label: string;
    };
    column_types: Option[];
    line_detail_modes: Option[];
    required_mapping_keys: KeyOption[];
    optional_mapping_keys: KeyOption[];
    allowed_template_placeholders: string[];
    pinned_api_version: string;
    can_manage: boolean;
    can_validate: boolean;
    safety_notes: string[];
    explanation: string[];
}>();

const slug = useOrganizationSlug();

const fieldClass =
    'border-input bg-transparent dark:bg-input/30 h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs outline-none';

function emptyRequired(): Record<string, MappingField> {
    return Object.fromEntries(
        props.required_mapping_keys.map((item) => [
            item.key,
            { column_id: '', expected_type: 'text' },
        ]),
    );
}

function emptyOptional(): Record<string, OptionalMappingField> {
    return Object.fromEntries(
        props.optional_mapping_keys.map((item) => [
            item.key,
            { enabled: false, column_id: '', expected_type: 'text' },
        ]),
    );
}

const form = reactive({
    board_id: props.settings?.board_id ?? '',
    group_id: props.settings?.group_id ?? '',
    api_version: props.settings?.api_version ?? props.defaults.api_version,
    item_name_template:
        props.settings?.item_name_template ?? props.defaults.item_name_template,
    line_detail_mode:
        props.settings?.line_detail_mode ?? props.defaults.line_detail_mode,
    intake_status_label:
        props.settings?.intake_status_label ??
        props.defaults.intake_status_label,
    required_mappings: {
        ...emptyRequired(),
        ...(props.settings?.required_mappings ?? {}),
    },
    optional_mappings: {
        ...emptyOptional(),
        ...(props.settings?.optional_mappings ?? {}),
    },
});

const validationLabel = computed(() => {
    switch (props.settings?.last_validation_status) {
        case 'valid':
            return 'Valid';
        case 'invalid':
            return 'Invalid';
        case 'client_not_configured':
            return 'Client not configured';
        default:
            return 'Never validated';
    }
});

const enabledLabel = computed(() =>
    props.settings?.enabled ? 'Enabled' : 'Disabled',
);

const routeArgs = computed(() => ({
    organization: slug,
    mondaySetting: props.settings?.id ?? 0,
}));

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Monday settings', href: '#' },
        ],
    },
});
</script>

<template>
    <Head title="Monday settings" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Monday settings"
            description="Configure Monday as an optional intake destination for accepted quotes."
        />

        <section class="space-y-3 rounded-xl border p-4">
            <h2 class="font-medium">How this works</h2>
            <ul class="list-disc space-y-2 pl-5 text-sm text-muted-foreground">
                <li v-for="line in props.explanation" :key="line">
                    {{ line }}
                </li>
            </ul>
        </section>

        <section
            v-if="props.settings"
            class="grid gap-3 rounded-xl border p-4 sm:grid-cols-2"
        >
            <div>
                <p class="text-sm text-muted-foreground">Destination status</p>
                <Badge
                    :variant="props.settings.enabled ? 'default' : 'secondary'"
                >
                    {{ enabledLabel }}
                </Badge>
            </div>
            <div>
                <p class="text-sm text-muted-foreground">Last validation</p>
                <p class="text-sm font-medium">{{ validationLabel }}</p>
                <p
                    v-if="props.settings.last_validated_at"
                    class="text-xs text-muted-foreground"
                >
                    {{ props.settings.last_validated_at }}
                </p>
                <p
                    v-if="props.settings.last_validation_error_message"
                    class="mt-1 text-sm text-destructive"
                    data-testid="validation-problem"
                >
                    {{ props.settings.last_validation_error_message }}
                </p>
            </div>
        </section>

        <section
            v-else
            class="rounded-xl border border-dashed p-4 text-sm text-muted-foreground"
            data-testid="monday-empty-state"
        >
            No Monday settings are saved for this organization yet. Save a
            configuration, validate it, then enable it as a separate step.
        </section>

        <Form
            v-if="props.can_manage"
            v-bind="
                props.settings
                    ? monday.update.form(routeArgs)
                    : monday.store.form(slug)
            "
            class="space-y-6"
            v-slot="{ errors, processing }"
            data-testid="monday-settings-form"
        >
            <input
                v-if="props.settings"
                type="hidden"
                name="expected_lock_version"
                :value="props.settings.lock_version"
            />

            <section class="space-y-4 rounded-xl border p-4">
                <div class="space-y-1">
                    <h2 class="font-medium">Connection</h2>
                    <p class="text-sm text-muted-foreground">
                        Board and group identifiers from Monday. API tokens are
                        never entered or shown here.
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="board_id">Board ID</Label>
                        <Input
                            id="board_id"
                            name="board_id"
                            v-model="form.board_id"
                            maxlength="64"
                            required
                        />
                        <InputError :message="errors.board_id" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="group_id">Group ID</Label>
                        <Input
                            id="group_id"
                            name="group_id"
                            v-model="form.group_id"
                            maxlength="64"
                            required
                        />
                        <InputError :message="errors.group_id" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="api_version">API version</Label>
                        <Input
                            id="api_version"
                            name="api_version"
                            :model-value="pinned_api_version"
                            readonly
                        />
                        <p class="text-xs text-muted-foreground">
                            Pinned to {{ pinned_api_version }}.
                        </p>
                    </div>
                </div>
            </section>

            <section class="space-y-4 rounded-xl border p-4">
                <div class="space-y-1">
                    <h2 class="font-medium">Item format</h2>
                    <p class="text-sm text-muted-foreground">
                        Allowed placeholders:
                        {{ allowed_template_placeholders.join(', ') }}.
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="item_name_template"
                            >Item name template</Label
                        >
                        <Input
                            id="item_name_template"
                            name="item_name_template"
                            v-model="form.item_name_template"
                            maxlength="191"
                            required
                        />
                        <InputError :message="errors.item_name_template" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="line_detail_mode">Line detail</Label>
                        <select
                            id="line_detail_mode"
                            name="line_detail_mode"
                            v-model="form.line_detail_mode"
                            :class="fieldClass"
                        >
                            <option
                                v-for="mode in line_detail_modes"
                                :key="mode.value"
                                :value="mode.value"
                            >
                                {{ mode.label }}
                            </option>
                        </select>
                        <InputError :message="errors.line_detail_mode" />
                    </div>
                </div>
            </section>

            <section class="space-y-4 rounded-xl border p-4">
                <div class="space-y-1">
                    <h2 class="font-medium">Required mappings</h2>
                    <p class="text-sm text-muted-foreground">
                        Each field needs a Monday column ID and expected column
                        type.
                    </p>
                </div>

                <div
                    v-for="item in required_mapping_keys"
                    :key="item.key"
                    class="grid gap-3 border-t pt-3 sm:grid-cols-3"
                >
                    <p class="text-sm font-medium sm:col-span-3">
                        {{ item.label }}
                    </p>
                    <div class="grid gap-2">
                        <Label :for="`required-${item.key}-column`"
                            >Monday column ID</Label
                        >
                        <Input
                            :id="`required-${item.key}-column`"
                            :name="`required_mappings[${item.key}][column_id]`"
                            v-model="form.required_mappings[item.key].column_id"
                            maxlength="64"
                            required
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label :for="`required-${item.key}-type`"
                            >Expected type</Label
                        >
                        <select
                            :id="`required-${item.key}-type`"
                            :name="`required_mappings[${item.key}][expected_type]`"
                            v-model="
                                form.required_mappings[item.key].expected_type
                            "
                            :class="fieldClass"
                        >
                            <option
                                v-for="type in column_types"
                                :key="type.value"
                                :value="type.value"
                            >
                                {{ type.label }}
                            </option>
                        </select>
                    </div>
                </div>
                <InputError :message="errors.required_mappings" />
            </section>

            <section class="space-y-4 rounded-xl border p-4">
                <div class="space-y-1">
                    <h2 class="font-medium">Optional mappings</h2>
                    <p class="text-sm text-muted-foreground">
                        Quote expiration is not a production due date and is not
                        sent to Monday.
                    </p>
                </div>

                <div
                    v-for="item in optional_mapping_keys"
                    :key="item.key"
                    class="grid gap-3 border-t pt-3 sm:grid-cols-3"
                >
                    <label
                        class="flex items-center gap-2 text-sm font-medium sm:col-span-3"
                    >
                        <input
                            type="checkbox"
                            :name="`optional_mappings[${item.key}][enabled]`"
                            value="1"
                            v-model="form.optional_mappings[item.key].enabled"
                        />
                        {{ item.label }}
                    </label>
                    <template v-if="form.optional_mappings[item.key].enabled">
                        <div class="grid gap-2">
                            <Label :for="`optional-${item.key}-column`"
                                >Monday column ID</Label
                            >
                            <Input
                                :id="`optional-${item.key}-column`"
                                :name="`optional_mappings[${item.key}][column_id]`"
                                v-model="
                                    form.optional_mappings[item.key].column_id
                                "
                                maxlength="64"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label :for="`optional-${item.key}-type`"
                                >Expected type</Label
                            >
                            <select
                                :id="`optional-${item.key}-type`"
                                :name="`optional_mappings[${item.key}][expected_type]`"
                                v-model="
                                    form.optional_mappings[item.key]
                                        .expected_type
                                "
                                :class="fieldClass"
                            >
                                <option
                                    v-for="type in column_types"
                                    :key="type.value"
                                    :value="type.value"
                                >
                                    {{ type.label }}
                                </option>
                            </select>
                        </div>
                    </template>
                </div>
            </section>

            <section class="space-y-4 rounded-xl border p-4">
                <div class="space-y-1">
                    <h2 class="font-medium">Status mapping</h2>
                    <p class="text-sm text-muted-foreground">
                        Monday status label for a newly accepted quote.
                        Validation confirms the label exists on the board.
                    </p>
                </div>
                <div class="grid max-w-md gap-2">
                    <Label for="intake_status_label">New intake</Label>
                    <Input
                        id="intake_status_label"
                        name="intake_status_label"
                        v-model="form.intake_status_label"
                        maxlength="64"
                        required
                    />
                    <InputError :message="errors.intake_status_label" />
                </div>
            </section>

            <InputError :message="errors.settings" />

            <div class="flex flex-wrap gap-2">
                <Button type="submit" :disabled="processing">
                    {{ props.settings ? 'Save changes' : 'Save settings' }}
                </Button>
            </div>
        </Form>

        <section
            v-else-if="props.settings"
            class="space-y-4 rounded-xl border p-4"
            data-testid="monday-readonly"
        >
            <h2 class="font-medium">Saved configuration</h2>
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-muted-foreground">Board ID</dt>
                    <dd>{{ props.settings.board_id }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Group ID</dt>
                    <dd>{{ props.settings.group_id }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">API version</dt>
                    <dd>{{ props.settings.api_version }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Item name template</dt>
                    <dd>{{ props.settings.item_name_template }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Line detail</dt>
                    <dd>{{ props.settings.line_detail_mode }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">New intake label</dt>
                    <dd>{{ props.settings.intake_status_label }}</dd>
                </div>
            </dl>
            <p class="text-sm text-muted-foreground">
                Quote expiration is not a production due date and is not sent to
                Monday.
            </p>
        </section>

        <section
            v-if="props.settings"
            class="flex flex-wrap gap-2"
            data-testid="monday-actions"
        >
            <Form
                v-if="props.can_validate"
                v-bind="monday.validate.form(routeArgs)"
                v-slot="{ processing }"
            >
                <input
                    type="hidden"
                    name="expected_lock_version"
                    :value="props.settings.lock_version"
                />
                <Button
                    type="submit"
                    variant="secondary"
                    :disabled="processing"
                >
                    Validate configuration
                </Button>
            </Form>

            <Form
                v-if="props.can_manage && props.settings.can_enable"
                v-bind="monday.enable.form(routeArgs)"
                v-slot="{ processing }"
                data-testid="monday-enable"
            >
                <input
                    type="hidden"
                    name="expected_lock_version"
                    :value="props.settings.lock_version"
                />
                <Button type="submit" :disabled="processing">Enable</Button>
            </Form>

            <Form
                v-if="props.can_manage && props.settings.enabled"
                v-bind="monday.disable.form(routeArgs)"
                v-slot="{ processing }"
                data-testid="monday-disable"
            >
                <input
                    type="hidden"
                    name="expected_lock_version"
                    :value="props.settings.lock_version"
                />
                <Button type="submit" variant="outline" :disabled="processing">
                    Disable
                </Button>
            </Form>
        </section>

        <section class="space-y-3 rounded-xl border p-4">
            <h2 class="font-medium">Safety</h2>
            <p class="text-sm text-muted-foreground">
                The following stay in Halftone Brain and are not sent to Monday:
            </p>
            <ul class="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                <li v-for="note in props.safety_notes" :key="note">
                    {{ note }}
                </li>
            </ul>
        </section>
    </div>
</template>
