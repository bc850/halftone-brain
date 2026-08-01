<?php

namespace App\Support\Tenancy;

/**
 * Canonical RBAC permission keys and system role templates for checkpoint 0B.
 */
final class RbacDefinitions
{
    /** @return list<array{key: string, module: string, description: string}> */
    public static function permissions(): array
    {
        return [
            // Organization CRM — companies
            ['key' => 'crm.company.view', 'module' => 'crm', 'description' => 'View organization-associated companies'],
            ['key' => 'crm.company.view_all', 'module' => 'crm', 'description' => 'View all companies in the organization'],
            ['key' => 'crm.company.create', 'module' => 'crm', 'description' => 'Create companies in the organization'],
            ['key' => 'crm.company.update', 'module' => 'crm', 'description' => 'Update organization company relationship fields'],
            ['key' => 'crm.company.delete', 'module' => 'crm', 'description' => 'Delete organization company associations'],

            // Organization CRM — contacts
            ['key' => 'crm.contact.view', 'module' => 'crm', 'description' => 'View contacts for organization companies'],
            ['key' => 'crm.contact.view_all', 'module' => 'crm', 'description' => 'View all contacts in the organization'],
            ['key' => 'crm.contact.create', 'module' => 'crm', 'description' => 'Create contacts'],
            ['key' => 'crm.contact.update', 'module' => 'crm', 'description' => 'Update contacts'],
            ['key' => 'crm.contact.delete', 'module' => 'crm', 'description' => 'Delete contacts'],

            // Organization CRM — deals
            ['key' => 'crm.deal.view', 'module' => 'crm', 'description' => 'View owned deals'],
            ['key' => 'crm.deal.view_all', 'module' => 'crm', 'description' => 'View all deals in the organization'],
            ['key' => 'crm.deal.create', 'module' => 'crm', 'description' => 'Create deals'],
            ['key' => 'crm.deal.update', 'module' => 'crm', 'description' => 'Update deals'],
            ['key' => 'crm.deal.delete', 'module' => 'crm', 'description' => 'Delete deals'],
            ['key' => 'crm.deal.reassign', 'module' => 'crm', 'description' => 'Reassign deal ownership'],

            // Organization CRM — quotes (Phase 2A)
            ['key' => 'crm.quote.view', 'module' => 'crm', 'description' => 'View owned quotes'],
            ['key' => 'crm.quote.view_all', 'module' => 'crm', 'description' => 'View all quotes in the organization'],
            ['key' => 'crm.quote.create', 'module' => 'crm', 'description' => 'Create quotes'],
            ['key' => 'crm.quote.update', 'module' => 'crm', 'description' => 'Update draft quotes and revisions'],
            ['key' => 'crm.quote.void', 'module' => 'crm', 'description' => 'Void quotes'],

            // Organization CRM — quote tax and approval (Phase 2C.1)
            ['key' => 'crm.quote.approve', 'module' => 'crm', 'description' => 'Approve or reject quote approval requests'],
            ['key' => 'crm.quote.tax_calculate', 'module' => 'crm', 'description' => 'Calculate quote tax from configured rates'],
            ['key' => 'crm.quote.tax_override', 'module' => 'crm', 'description' => 'Manually override calculated quote tax'],
            ['key' => 'crm.tax_certificate.view', 'module' => 'crm', 'description' => 'View customer exemption certificates'],
            ['key' => 'crm.tax_certificate.manage', 'module' => 'crm', 'description' => 'Create, update, and verify customer exemption certificates'],

            // Organization CRM — quote delivery and customer response (Phase 2D.1)
            ['key' => 'crm.quote.send', 'module' => 'crm', 'description' => 'Send customer quote documents and record manual delivery'],
            ['key' => 'crm.quote.record_customer_response', 'module' => 'crm', 'description' => 'Record an employee-entered customer quote acceptance or rejection'],

            // Integrations — outbox operations (Phase 2E.2)
            ['key' => 'integrations.outbox.view', 'module' => 'integrations', 'description' => 'View integration outbox and delivery activity'],
            ['key' => 'integrations.outbox.replay', 'module' => 'integrations', 'description' => 'Replay failed, dead, or configuration-blocked integration deliveries'],
            ['key' => 'integrations.outbox.abandon', 'module' => 'integrations', 'description' => 'Abandon eligible unsuccessful integration deliveries'],

            // Integrations — provider settings (Phase 2E.3A definitions only; do not sync primary yet)
            ['key' => 'integrations.settings.view', 'module' => 'integrations', 'description' => 'View organization integration provider settings'],
            ['key' => 'integrations.settings.manage', 'module' => 'integrations', 'description' => 'Create and update organization integration provider settings'],
            ['key' => 'integrations.settings.validate', 'module' => 'integrations', 'description' => 'Validate organization integration provider configuration'],

            // OrganizationCompany
            ['key' => 'crm.org_company.view', 'module' => 'crm', 'description' => 'View organization-company relationships'],
            ['key' => 'crm.org_company.create', 'module' => 'crm', 'description' => 'Associate companies with the organization'],
            ['key' => 'crm.org_company.update', 'module' => 'crm', 'description' => 'Update organization-company fields'],
            ['key' => 'crm.org_company.delete', 'module' => 'crm', 'description' => 'Remove organization-company associations'],

            // Organization catalog (read/mutate shared masters still need parent perms for writes)
            ['key' => 'catalog.product.view', 'module' => 'catalog', 'description' => 'View products'],
            ['key' => 'catalog.product.create', 'module' => 'catalog', 'description' => 'Create products (requires parent permission for masters)'],
            ['key' => 'catalog.product.update', 'module' => 'catalog', 'description' => 'Update products (requires parent permission for masters)'],
            ['key' => 'catalog.product.delete', 'module' => 'catalog', 'description' => 'Delete products (requires parent permission for masters)'],
            ['key' => 'catalog.product.view_cost', 'module' => 'catalog', 'description' => 'View product true cost'],
            ['key' => 'catalog.org_product.manage', 'module' => 'catalog', 'description' => 'Manage organization product settings and associations'],
            ['key' => 'catalog.org_product.manage_pricing', 'module' => 'catalog', 'description' => 'Manage organization product costs and pricing'],
            ['key' => 'catalog.org_product.override_price', 'module' => 'catalog', 'description' => 'Override organization product selling price on quotes'],
            ['key' => 'catalog.org_product.override_margin', 'module' => 'catalog', 'description' => 'Override organization product margin on quotes'],
            ['key' => 'catalog.org_product.approve_below_minimum', 'module' => 'catalog', 'description' => 'Approve quote prices below organization product minimum'],
            ['key' => 'catalog.org_product.archive', 'module' => 'catalog', 'description' => 'Archive organization products (set unavailable)'],
            ['key' => 'catalog.vendor.view', 'module' => 'catalog', 'description' => 'View vendors'],
            ['key' => 'catalog.vendor.create', 'module' => 'catalog', 'description' => 'Create vendors'],
            ['key' => 'catalog.vendor.update', 'module' => 'catalog', 'description' => 'Update vendors'],
            ['key' => 'catalog.vendor.delete', 'module' => 'catalog', 'description' => 'Delete vendors'],
            ['key' => 'catalog.category.view', 'module' => 'catalog', 'description' => 'View categories'],
            ['key' => 'catalog.category.create', 'module' => 'catalog', 'description' => 'Create categories'],
            ['key' => 'catalog.category.update', 'module' => 'catalog', 'description' => 'Update categories'],
            ['key' => 'catalog.category.delete', 'module' => 'catalog', 'description' => 'Delete categories'],

            // Organization admin surface
            ['key' => 'org.team.view', 'module' => 'org', 'description' => 'View teams'],
            ['key' => 'org.team.manage', 'module' => 'org', 'description' => 'Manage teams'],
            ['key' => 'org.membership.view', 'module' => 'org', 'description' => 'View organization memberships'],
            ['key' => 'org.membership.manage', 'module' => 'org', 'description' => 'Manage organization memberships'],
            ['key' => 'org.role.view', 'module' => 'org', 'description' => 'View organization roles'],
            ['key' => 'org.role.manage', 'module' => 'org', 'description' => 'Manage organization roles'],
            ['key' => 'org.audit.view', 'module' => 'org', 'description' => 'View organization audit events'],
            ['key' => 'org.sequence.manage', 'module' => 'org', 'description' => 'Manage number sequences'],

            // Parent permissions
            ['key' => 'parent.organization.view', 'module' => 'parent', 'description' => 'View organizations'],
            ['key' => 'parent.organization.manage', 'module' => 'parent', 'description' => 'Manage organizations'],
            ['key' => 'parent.company.view', 'module' => 'parent', 'description' => 'View shared company identity'],
            ['key' => 'parent.company.update', 'module' => 'parent', 'description' => 'Update shared company identity'],
            ['key' => 'parent.contact.view', 'module' => 'parent', 'description' => 'View shared contact identity'],
            ['key' => 'parent.contact.update', 'module' => 'parent', 'description' => 'Update shared contact identity'],
            ['key' => 'parent.catalog.product.manage', 'module' => 'parent', 'description' => 'Manage shared product masters'],
            ['key' => 'parent.catalog.vendor.manage', 'module' => 'parent', 'description' => 'Manage shared vendor masters'],
            ['key' => 'parent.catalog.category.manage', 'module' => 'parent', 'description' => 'Manage shared category masters'],
            ['key' => 'parent.catalog.product.view_cost', 'module' => 'parent', 'description' => 'View parent catalog costs'],
            ['key' => 'parent.membership.view', 'module' => 'parent', 'description' => 'View parent memberships'],
            ['key' => 'parent.membership.manage', 'module' => 'parent', 'description' => 'Manage parent memberships'],
            ['key' => 'parent.role.view', 'module' => 'parent', 'description' => 'View parent roles'],
            ['key' => 'parent.role.manage', 'module' => 'parent', 'description' => 'Manage parent roles'],
            ['key' => 'parent.audit.view', 'module' => 'parent', 'description' => 'View parent audit events'],
        ];
    }

    /**
     * @return array<string, array{name: string, scope: string, permissions: list<string>}>
     */
    public static function systemRoles(): array
    {
        $orgAdmin = [
            'crm.company.view', 'crm.company.view_all', 'crm.company.create', 'crm.company.update', 'crm.company.delete',
            'crm.contact.view', 'crm.contact.view_all', 'crm.contact.create', 'crm.contact.update', 'crm.contact.delete',
            'crm.deal.view', 'crm.deal.view_all', 'crm.deal.create', 'crm.deal.update', 'crm.deal.delete', 'crm.deal.reassign',
            'crm.quote.view', 'crm.quote.view_all', 'crm.quote.create', 'crm.quote.update', 'crm.quote.void',
            'crm.quote.approve', 'crm.quote.tax_calculate', 'crm.quote.tax_override',
            'crm.tax_certificate.view', 'crm.tax_certificate.manage',
            'crm.quote.send', 'crm.quote.record_customer_response',
            'integrations.outbox.view', 'integrations.outbox.replay', 'integrations.outbox.abandon',
            'integrations.settings.view', 'integrations.settings.manage', 'integrations.settings.validate',
            'crm.org_company.view', 'crm.org_company.create', 'crm.org_company.update', 'crm.org_company.delete',
            'catalog.product.view', 'catalog.product.create', 'catalog.product.update', 'catalog.product.delete', 'catalog.product.view_cost',
            'catalog.org_product.manage', 'catalog.org_product.manage_pricing', 'catalog.org_product.override_price',
            'catalog.org_product.override_margin', 'catalog.org_product.approve_below_minimum', 'catalog.org_product.archive',
            'catalog.vendor.view', 'catalog.vendor.create', 'catalog.vendor.update', 'catalog.vendor.delete',
            'catalog.category.view', 'catalog.category.create', 'catalog.category.update', 'catalog.category.delete',
            'org.team.view', 'org.team.manage',
            'org.membership.view', 'org.membership.manage',
            'org.role.view', 'org.role.manage',
            'org.audit.view', 'org.sequence.manage',
        ];

        return [
            'parent_owner' => [
                'name' => 'Parent Owner',
                'scope' => 'system',
                'permissions' => array_values(array_map(
                    fn (array $p): string => $p['key'],
                    array_filter(self::permissions(), fn (array $p): bool => str_starts_with($p['key'], 'parent.')),
                )),
            ],
            'parent_admin' => [
                'name' => 'Parent Admin',
                'scope' => 'system',
                'permissions' => [
                    'parent.organization.view', 'parent.organization.manage',
                    'parent.company.view', 'parent.company.update',
                    'parent.contact.view', 'parent.contact.update',
                    'parent.catalog.product.manage', 'parent.catalog.vendor.manage', 'parent.catalog.category.manage',
                    'parent.catalog.product.view_cost',
                    'parent.membership.view', 'parent.membership.manage',
                    'parent.role.view', 'parent.role.manage',
                    'parent.audit.view',
                ],
            ],
            'parent_catalog_manager' => [
                'name' => 'Parent Catalog Manager',
                'scope' => 'system',
                'permissions' => [
                    'parent.catalog.product.manage',
                    'parent.catalog.vendor.manage',
                    'parent.catalog.category.manage',
                    'parent.catalog.product.view_cost',
                    'parent.company.view',
                    'parent.contact.view',
                ],
            ],
            'owner' => [
                'name' => 'Owner',
                'scope' => 'system',
                'permissions' => $orgAdmin,
            ],
            'admin' => [
                'name' => 'Admin',
                'scope' => 'system',
                'permissions' => $orgAdmin,
            ],
            'sales_manager' => [
                'name' => 'Sales Manager',
                'scope' => 'system',
                'permissions' => [
                    'crm.company.view', 'crm.company.view_all', 'crm.company.create', 'crm.company.update',
                    'crm.contact.view', 'crm.contact.view_all', 'crm.contact.create', 'crm.contact.update',
                    'crm.deal.view', 'crm.deal.view_all', 'crm.deal.create', 'crm.deal.update', 'crm.deal.reassign',
                    'crm.quote.view', 'crm.quote.view_all', 'crm.quote.create', 'crm.quote.update', 'crm.quote.void',
                    'crm.quote.approve', 'crm.quote.tax_calculate',
                    'crm.tax_certificate.view',
                    'crm.quote.send', 'crm.quote.record_customer_response',
                    'integrations.outbox.view',
                    'integrations.settings.view',
                    'crm.org_company.view', 'crm.org_company.create', 'crm.org_company.update',
                    'catalog.product.view', 'catalog.vendor.view', 'catalog.category.view',
                    'catalog.org_product.override_price', 'catalog.org_product.override_margin',
                    'catalog.org_product.approve_below_minimum',
                    'org.team.view', 'org.membership.view',
                ],
            ],
            'salesperson' => [
                'name' => 'Salesperson',
                'scope' => 'system',
                'permissions' => [
                    'crm.company.view', 'crm.company.create', 'crm.company.update',
                    'crm.contact.view', 'crm.contact.create', 'crm.contact.update',
                    'crm.deal.view', 'crm.deal.create', 'crm.deal.update',
                    'crm.quote.view', 'crm.quote.create', 'crm.quote.update',
                    'crm.quote.tax_calculate',
                    'crm.tax_certificate.view',
                    'crm.quote.send',
                    'crm.org_company.view', 'crm.org_company.create', 'crm.org_company.update',
                    'catalog.product.view', 'catalog.vendor.view', 'catalog.category.view',
                ],
            ],
            'project_manager' => [
                'name' => 'Project Manager',
                'scope' => 'system',
                // No crm.tax_certificate.view: project managers never need certificate
                // numbers to run a job, and the customer detail they do need is already
                // covered by crm.org_company.view.
                'permissions' => [
                    'crm.company.view', 'crm.contact.view',
                    'crm.deal.view', 'crm.deal.view_all', 'crm.deal.update',
                    'crm.quote.view', 'crm.quote.view_all',
                    'crm.org_company.view',
                    'catalog.product.view', 'catalog.vendor.view', 'catalog.category.view',
                    'org.team.view',
                ],
            ],
            'production_worker' => [
                'name' => 'Production Worker',
                'scope' => 'system',
                'permissions' => [
                    'crm.deal.view',
                    'crm.quote.view',
                    'catalog.product.view',
                    'org.team.view',
                ],
            ],
            'finance' => [
                'name' => 'Finance',
                'scope' => 'system',
                'permissions' => [
                    'crm.company.view', 'crm.company.view_all',
                    'crm.contact.view', 'crm.contact.view_all',
                    'crm.deal.view', 'crm.deal.view_all',
                    'crm.quote.view', 'crm.quote.view_all',
                    'crm.quote.tax_calculate', 'crm.quote.tax_override',
                    'crm.tax_certificate.view', 'crm.tax_certificate.manage',
                    'crm.org_company.view',
                    'catalog.product.view', 'catalog.product.view_cost',
                    'catalog.org_product.manage_pricing',
                    'catalog.vendor.view', 'catalog.category.view',
                    'org.audit.view', 'org.sequence.manage',
                ],
            ],
        ];
    }

    /** @return list<string> */
    public static function parentRoleKeys(): array
    {
        return ['parent_owner', 'parent_admin', 'parent_catalog_manager'];
    }

    /** @return list<string> */
    public static function organizationRoleKeys(): array
    {
        return ['owner', 'admin', 'sales_manager', 'salesperson', 'project_manager', 'production_worker', 'finance'];
    }
}
