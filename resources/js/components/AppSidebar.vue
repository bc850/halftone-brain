<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Building2,
    Handshake,
    LayoutGrid,
    Package,
    Percent,
    Stamp,
    Tags,
    Truck,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import OrganizationSwitcher from '@/components/OrganizationSwitcher.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard as legacyDashboard } from '@/routes';
import { index as legacyCategoriesIndex } from '@/routes/categories';
import { index as legacyCompaniesIndex } from '@/routes/companies';
import { index as legacyContactsIndex } from '@/routes/contacts';
import { index as legacyDealsIndex } from '@/routes/deals';
import { dashboard as orgDashboard } from '@/routes/org';
import { index as orgCategoriesIndex } from '@/routes/org/categories';
import { index as orgCompaniesIndex } from '@/routes/org/companies';
import { index as orgContactsIndex } from '@/routes/org/contacts';
import { index as orgDealsIndex } from '@/routes/org/deals';
import { index as orgProductsIndex } from '@/routes/org/products';
import { index as orgApprovalQueueIndex } from '@/routes/org/quote-approvals';
import { edit as orgTaxSettingsEdit } from '@/routes/org/tax-settings';
import { index as orgVendorsIndex } from '@/routes/org/vendors';
import { index as legacyProductsIndex } from '@/routes/products';
import { index as legacyVendorsIndex } from '@/routes/vendors';
import type { NavItem } from '@/types';

const page = usePage();
const organizationSlug = computed(
    () => page.props.tenant?.organization?.slug ?? null,
);

const permissions = computed(() => page.props.tenant?.permissions ?? []);

function may(...required: string[]): boolean {
    return required.some((permission) =>
        permissions.value.includes(permission),
    );
}

const mainNavItems = computed((): NavItem[] => {
    const slug = organizationSlug.value;

    if (slug === null) {
        return [
            { title: 'Dashboard', href: legacyDashboard(), icon: LayoutGrid },
            {
                title: 'Companies',
                href: legacyCompaniesIndex(),
                icon: Building2,
            },
            { title: 'Contacts', href: legacyContactsIndex(), icon: Users },
            { title: 'Deals', href: legacyDealsIndex(), icon: Handshake },
            { title: 'Products', href: legacyProductsIndex(), icon: Package },
            { title: 'Vendors', href: legacyVendorsIndex(), icon: Truck },
            { title: 'Categories', href: legacyCategoriesIndex(), icon: Tags },
        ];
    }

    const items: NavItem[] = [
        {
            title: 'Dashboard',
            href: orgDashboard.url(slug),
            icon: LayoutGrid,
        },
        {
            title: 'Companies',
            href: orgCompaniesIndex.url(slug),
            icon: Building2,
        },
        {
            title: 'Contacts',
            href: orgContactsIndex.url(slug),
            icon: Users,
        },
        { title: 'Deals', href: orgDealsIndex.url(slug), icon: Handshake },
        {
            title: 'Products',
            href: orgProductsIndex.url(slug),
            icon: Package,
        },
        { title: 'Vendors', href: orgVendorsIndex.url(slug), icon: Truck },
        {
            title: 'Categories',
            href: orgCategoriesIndex.url(slug),
            icon: Tags,
        },
    ];

    if (may('crm.quote.approve')) {
        items.push({
            title: 'Approvals',
            href: orgApprovalQueueIndex.url(slug),
            icon: Stamp,
        });
    }

    if (may('crm.quote.tax_calculate', 'crm.quote.tax_override')) {
        items.push({
            title: 'Tax settings',
            href: orgTaxSettingsEdit.url(slug),
            icon: Percent,
        });
    }

    return items;
});

const footerNavItems: NavItem[] = [];

const logoHref = computed(() => {
    const slug = organizationSlug.value;

    return slug === null ? legacyDashboard() : orgDashboard.url(slug);
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="logoHref">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
                <OrganizationSwitcher />
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter v-if="footerNavItems.length" :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
