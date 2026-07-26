<?php

use App\Support\Catalog\ComponentCost\ComponentsVersionContract;

test('components version contract documents 1c6b bump and stale pricing rules', function () {
    expect(ComponentsVersionContract::INITIAL_VERSION)->toBe(1)
        ->and(ComponentsVersionContract::rules())->toContain(
            'component_graph_mutations_bump_parent_components_version',
            'raw_purchase_cost_or_uom_changes_bump_dependent_finished_components_versions',
            'component_mutations_do_not_bump_pricing_version',
            'pricing_save_requires_pricing_version_and_components_version',
            'stale_components_version_returns_409',
            'active_components_reestimate_on_pricing_save',
            'material_cost_micro_units_is_last_snapshot_only',
        );
});
