<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/**
 * Pre-hardening integrity checks for Phase 0E.
 * Fails closed without repairing, deleting, or backfilling rows.
 */
class TenantSchemaHardeningGuard
{
    /**
     * @return list<string>
     */
    public static function violations(): array
    {
        if (DB::connection()->pretending()) {
            return [];
        }

        $violations = [];

        $nullChecks = [
            'companies.parent_account_id' => 'select count(*) as c from companies where parent_account_id is null',
            'contacts.parent_account_id' => 'select count(*) as c from contacts where parent_account_id is null',
            'vendors.parent_account_id' => 'select count(*) as c from vendors where parent_account_id is null',
            'product_categories.parent_account_id' => 'select count(*) as c from product_categories where parent_account_id is null',
            'products.parent_account_id' => 'select count(*) as c from products where parent_account_id is null',
            'deals.parent_account_id' => 'select count(*) as c from deals where parent_account_id is null',
            'deals.organization_id' => 'select count(*) as c from deals where organization_id is null',
            'deals.organization_company_id' => 'select count(*) as c from deals where organization_company_id is null',
            'teams.parent_account_id' => 'select count(*) as c from teams where parent_account_id is null',
            'teams.organization_id' => 'select count(*) as c from teams where organization_id is null',
        ];

        foreach ($nullChecks as $label => $sql) {
            if (self::countOrFail($sql, $label) > 0) {
                $violations[] = "null_tenant:{$label}";
            }
        }

        $mismatchChecks = [
            'organization_companies_parent_organization' => <<<'SQL'
                select count(*) as c from organization_companies oc
                join organizations o on o.id = oc.organization_id
                where oc.parent_account_id <> o.parent_account_id
                SQL,
            'organization_companies_parent_company' => <<<'SQL'
                select count(*) as c from organization_companies oc
                join companies c on c.id = oc.company_id
                where c.parent_account_id is null or oc.parent_account_id <> c.parent_account_id
                SQL,
            'contacts_parent_company' => <<<'SQL'
                select count(*) as c from contacts ct
                join companies c on c.id = ct.company_id
                where ct.parent_account_id is not null
                  and (c.parent_account_id is null or ct.parent_account_id <> c.parent_account_id)
                SQL,
            'products_parent_vendor' => <<<'SQL'
                select count(*) as c from products p
                join vendors v on v.id = p.vendor_id
                where p.parent_account_id is not null
                  and (v.parent_account_id is null or p.parent_account_id <> v.parent_account_id)
                SQL,
            'products_parent_category' => <<<'SQL'
                select count(*) as c from products p
                join product_categories pc on pc.id = p.product_category_id
                where p.parent_account_id is not null
                  and (pc.parent_account_id is null or p.parent_account_id <> pc.parent_account_id)
                SQL,
            'deals_organization_company_org' => <<<'SQL'
                select count(*) as c from deals d
                join organization_companies oc on oc.id = d.organization_company_id
                where d.organization_id is not null and d.organization_id <> oc.organization_id
                SQL,
            'deals_parent_organization' => <<<'SQL'
                select count(*) as c from deals d
                join organizations o on o.id = d.organization_id
                where d.parent_account_id is not null and d.parent_account_id <> o.parent_account_id
                SQL,
            'deals_company_organization_company' => <<<'SQL'
                select count(*) as c from deals d
                join organization_companies oc on oc.id = d.organization_company_id
                where d.company_id is not null and d.company_id <> oc.company_id
                SQL,
            'teams_parent_organization' => <<<'SQL'
                select count(*) as c from teams t
                join organizations o on o.id = t.organization_id
                where t.parent_account_id is not null and t.parent_account_id <> o.parent_account_id
                SQL,
            'audit_parent_organization' => <<<'SQL'
                select count(*) as c from audit_events a
                join organizations o on o.id = a.organization_id
                where a.organization_id is not null and a.parent_account_id <> o.parent_account_id
                SQL,
        ];

        foreach ($mismatchChecks as $label => $sql) {
            if (self::countOrFail($sql, $label) > 0) {
                $violations[] = "mismatch:{$label}";
            }
        }

        $orphanChecks = [
            'companies_parent' => <<<'SQL'
                select count(*) as c from companies c
                where c.parent_account_id is not null
                  and not exists (select 1 from parent_accounts p where p.id = c.parent_account_id)
                SQL,
            'organization_companies_organization' => <<<'SQL'
                select count(*) as c from organization_companies oc
                where not exists (select 1 from organizations o where o.id = oc.organization_id)
                SQL,
            'deals_organization_company' => <<<'SQL'
                select count(*) as c from deals d
                where d.organization_company_id is not null
                  and not exists (select 1 from organization_companies oc where oc.id = d.organization_company_id)
                SQL,
        ];

        foreach ($orphanChecks as $label => $sql) {
            if (self::countOrFail($sql, $label) > 0) {
                $violations[] = "orphan:{$label}";
            }
        }

        return $violations;
    }

    public static function assertReadyOrFail(): void
    {
        if (DB::connection()->pretending()) {
            return;
        }

        $violations = self::violations();

        if ($violations === []) {
            return;
        }

        throw new RuntimeException(
            'Tenant schema hardening aborted; integrity violations: '.implode(', ', $violations)
        );
    }

    private static function countOrFail(string $sql, string $label): int
    {
        $row = DB::selectOne($sql);

        if (! $row instanceof stdClass || ! property_exists($row, 'c') || $row->c === null) {
            throw new RuntimeException(
                "Tenant schema hardening aborted; validation query returned no result for [{$label}]."
            );
        }

        return (int) $row->c;
    }
}
