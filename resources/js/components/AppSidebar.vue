<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Building2, Handshake, LayoutGrid, Package, Tags, Truck, Users } from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as categoriesIndex } from '@/routes/categories';
import { index as companiesIndex } from '@/routes/companies';
import { index as contactsIndex } from '@/routes/contacts';
import { index as dealsIndex } from '@/routes/deals';
import { index as productsIndex } from '@/routes/products';
import { index as vendorsIndex } from '@/routes/vendors';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Companies',
        href: companiesIndex(),
        icon: Building2,
    },
    {
        title: 'Contacts',
        href: contactsIndex(),
        icon: Users,
    },
    {
        title: 'Deals',
        href: dealsIndex(),
        icon: Handshake,
    },
    {
        title: 'Products',
        href: productsIndex(),
        icon: Package,
    },
    {
        title: 'Vendors',
        href: vendorsIndex(),
        icon: Truck,
    },
    {
        title: 'Categories',
        href: categoriesIndex(),
        icon: Tags,
    },
];

const footerNavItems: NavItem[] = [];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
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
