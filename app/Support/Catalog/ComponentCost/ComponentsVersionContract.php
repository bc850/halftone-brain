<?php

namespace App\Support\Catalog\ComponentCost;

use App\Models\OrganizationProduct;

/**
 * Intended 1C.6B concurrency contract for estimated material components.
 *
 * 1C.6A only persists {@see OrganizationProduct::$components_version};
 * mutation wiring and pricing-save integration happen in 1C.6B.
 *
 * Binding rules for 1C.6B:
 * - Component create/update/activate/deactivate increments the finished parent's
 *   components_version.
 * - Purchase-cost or purchase-UOM changes on a material increment components_version
 *   for every active finished item that depends on that material.
 * - Those mutations do not directly increment pricing_version.
 * - Pricing preview/save requires both expected pricing_version and
 *   components_version; a stale components estimate returns HTTP 409.
 * - When active components exist, pricing save re-estimates server-side.
 * - Persisted material_cost_micro_units is only the last pricing snapshot and is
 *   never the source of truth while components are active.
 */
final class ComponentsVersionContract
{
    public const INITIAL_VERSION = 1;

    /**
     * Human-readable contract bullets for tests and implementers.
     *
     * @return list<string>
     */
    public static function rules(): array
    {
        return [
            'component_graph_mutations_bump_parent_components_version',
            'raw_purchase_cost_or_uom_changes_bump_dependent_finished_components_versions',
            'component_mutations_do_not_bump_pricing_version',
            'pricing_save_requires_pricing_version_and_components_version',
            'stale_components_version_returns_409',
            'active_components_reestimate_on_pricing_save',
            'material_cost_micro_units_is_last_snapshot_only',
        ];
    }
}
