<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { tenantAwareHref } from '@/composables/useTenantAction';
import { toUrl } from '@/lib/utils';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

type Props = {
    breadcrumbs: BreadcrumbItemType[];
};

const props = defineProps<Props>();
const page = usePage();

const resolvedBreadcrumbs = computed(() => {
    const slug = page.props.tenant?.organization?.slug ?? null;

    return props.breadcrumbs.map((item) => {
        const href = toUrl(item.href);

        if (typeof href !== 'string') {
            return item;
        }

        return {
            ...item,
            href: tenantAwareHref(href, slug),
        };
    });
});
</script>

<template>
    <Breadcrumb>
        <BreadcrumbList>
            <template v-for="(item, index) in resolvedBreadcrumbs" :key="index">
                <BreadcrumbItem>
                    <template v-if="index === resolvedBreadcrumbs.length - 1">
                        <BreadcrumbPage>{{ item.title }}</BreadcrumbPage>
                    </template>
                    <template v-else>
                        <BreadcrumbLink as-child>
                            <Link :href="item.href">{{ item.title }}</Link>
                        </BreadcrumbLink>
                    </template>
                </BreadcrumbItem>
                <BreadcrumbSeparator
                    v-if="index !== resolvedBreadcrumbs.length - 1"
                />
            </template>
        </BreadcrumbList>
    </Breadcrumb>
</template>
