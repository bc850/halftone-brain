import { usePage } from '@inertiajs/vue3';
import type { Tenant } from '@/types';

type RouteHelper = {
    (...args: never[]): unknown;
    url: (...args: never[]) => string;
    form: (...args: never[]) => unknown;
    definition?: unknown;
};

function organizationSlug(): string | undefined {
    return (usePage().props.tenant as Tenant | null | undefined)?.organization
        ?.slug;
}

/**
 * Adapt legacy Wayfinder call args into org-prefixed route args.
 * Legacy: show(1) / show({ company: 1 })
 * Org: show([slug, 1]) / show({ organization: slug, company: 1 })
 */
function withOrganizationArgs(slug: string, args: unknown[]): unknown[] {
    if (args.length === 0) {
        return [slug];
    }

    const [first, ...rest] = args;

    if (
        typeof first === 'string' ||
        typeof first === 'number' ||
        (first !== null &&
            typeof first === 'object' &&
            !Array.isArray(first) &&
            'id' in first &&
            !('organization' in first) &&
            !('company' in first) &&
            !('contact' in first) &&
            !('deal' in first) &&
            !('product' in first) &&
            !('vendor' in first) &&
            !('category' in first))
    ) {
        return [[slug, first], ...rest];
    }

    if (Array.isArray(first)) {
        return [[slug, ...first], ...rest];
    }

    if (first !== null && typeof first === 'object') {
        return [{ organization: slug, ...(first as object) }, ...rest];
    }

    return [slug, ...args];
}

function wrapOrgRoute(org: RouteHelper, slug: string): RouteHelper {
    const call = (...args: unknown[]) =>
        (org as unknown as (...a: unknown[]) => unknown)(
            ...withOrganizationArgs(slug, args),
        );

    const wrapMethod = (method: keyof RouteHelper) => {
        const fn = org[method];

        if (typeof fn !== 'function') {
            return fn;
        }

        return (...args: unknown[]) =>
            (fn as (...a: unknown[]) => unknown)(
                ...withOrganizationArgs(slug, args),
            );
    };

    return Object.assign(call, {
        url: wrapMethod('url'),
        form: wrapMethod('form'),
        get: wrapMethod('get' as keyof RouteHelper),
        post: wrapMethod('post' as keyof RouteHelper),
        put: wrapMethod('put' as keyof RouteHelper),
        patch: wrapMethod('patch' as keyof RouteHelper),
        delete: wrapMethod('delete' as keyof RouteHelper),
        head: wrapMethod('head' as keyof RouteHelper),
        definition: org.definition,
    }) as RouteHelper;
}

/**
 * When TenantContext is present (org Inertia pages), prefer `/o/{organization}/…`
 * named routes; otherwise keep legacy routes.
 */
export function useTenantRoute<T extends RouteHelper>(
    legacy: T,
    org: RouteHelper,
): T {
    const slug = organizationSlug();

    if (!slug) {
        return legacy;
    }

    return wrapOrgRoute(org, slug) as T;
}

const LEGACY_MODULE_PREFIXES = [
    '/dashboard',
    '/companies',
    '/contacts',
    '/deals',
    '/products',
    '/vendors',
    '/categories',
] as const;

/**
 * Rewrite a legacy CRM/catalog path into the current organization URL when
 * TenantContext props are present. Used by breadcrumbs and shared nav.
 */
export function tenantAwareHref(href: string, slug?: string | null): string {
    const organization = slug ?? organizationSlug();

    if (!organization) {
        return href;
    }

    if (
        href.startsWith(`/o/${organization}/`) ||
        href === `/o/${organization}`
    ) {
        return href;
    }

    for (const prefix of LEGACY_MODULE_PREFIXES) {
        if (
            href === prefix ||
            href.startsWith(`${prefix}/`) ||
            href.startsWith(`${prefix}?`)
        ) {
            return `/o/${organization}${href}`;
        }
    }

    return href;
}
