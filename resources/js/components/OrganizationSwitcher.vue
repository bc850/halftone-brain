<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Building2, ChevronsUpDown } from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { dashboard as orgDashboard } from '@/routes/org';
import type { OrganizationSummary } from '@/types';

const page = usePage();
const { isMobile, state } = useSidebar();

const tenant = computed(() => page.props.tenant);
const organizations = computed(() => tenant.value?.organizations ?? []);

const currentLabel = computed(() => {
    return tenant.value?.organization?.name ?? 'Select organization';
});

const MODULES = new Set([
    'dashboard',
    'companies',
    'contacts',
    'deals',
    'products',
    'vendors',
    'categories',
]);

function destinationForOrganization(organization: OrganizationSummary): string {
    const path = page.url.split('?')[0] ?? '';
    const parts = path.split('/').filter(Boolean);

    // /o/{slug}/module[/id[/action]]
    if (parts[0] === 'o' && parts.length >= 3 && MODULES.has(parts[2])) {
        const moduleName = parts[2];

        if (moduleName === 'dashboard' || parts.length === 3) {
            return `/o/${organization.slug}/${parts.slice(2).join('/')}`;
        }

        // Show/edit/create deeper paths → module index (safe cross-org fallback)
        if (parts.length === 4 && parts[3] === 'create') {
            return `/o/${organization.slug}/${moduleName}/create`;
        }

        return `/o/${organization.slug}/${moduleName}`;
    }

    return orgDashboard.url(organization.slug);
}

const switchOrganization = (organization: OrganizationSummary): void => {
    if (tenant.value?.organization?.slug === organization.slug) {
        return;
    }

    router.visit(destinationForOrganization(organization));
};
</script>

<template>
    <SidebarMenu v-if="organizations.length > 0">
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton
                        size="lg"
                        class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                        data-test="organization-switcher"
                    >
                        <div
                            class="flex aspect-square size-8 items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground"
                        >
                            <Building2 class="size-4" />
                        </div>
                        <div
                            class="grid flex-1 text-left text-sm leading-tight"
                        >
                            <span class="truncate font-medium">{{
                                currentLabel
                            }}</span>
                            <span
                                class="truncate text-xs text-muted-foreground"
                            >
                                Organization
                            </span>
                        </div>
                        <ChevronsUpDown class="ml-auto size-4" />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="w-(--reka-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                    :side="
                        isMobile
                            ? 'bottom'
                            : state === 'collapsed'
                              ? 'left'
                              : 'bottom'
                    "
                    align="start"
                    :side-offset="4"
                >
                    <DropdownMenuItem
                        v-for="organization in organizations"
                        :key="organization.id"
                        class="cursor-pointer"
                        @click="switchOrganization(organization)"
                    >
                        {{ organization.name }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
