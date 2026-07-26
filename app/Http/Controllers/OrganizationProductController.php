<?php

namespace App\Http\Controllers;

use App\Enums\InventoryTrackingMode;
use App\Enums\ItemKind;
use App\Enums\OverheadMode;
use App\Enums\PricingMethod;
use App\Enums\ProductFamily;
use App\Enums\UnitOfMeasure;
use App\Http\Controllers\Concerns\RequiresTenantContext;
use App\Http\Controllers\Concerns\ScopesQueriesToTenant;
use App\Http\Requests\AssociateOrganizationProductRequest;
use App\Http\Requests\DeactivateOrganizationProductComponentRequest;
use App\Http\Requests\PreviewOrganizationProductPricingRequest;
use App\Http\Requests\PreviewOrganizationProductUnitConversionRequest;
use App\Http\Requests\ReactivateOrganizationProductComponentRequest;
use App\Http\Requests\StoreOrganizationProductComponentRequest;
use App\Http\Requests\StoreOrganizationProductRequest;
use App\Http\Requests\StoreOrganizationProductUnitConversionRequest;
use App\Http\Requests\UpdateOrganizationProductComponentRequest;
use App\Http\Requests\UpdateOrganizationProductPricingRequest;
use App\Http\Requests\UpdateOrganizationProductPurchaseCostRequest;
use App\Http\Requests\UpdateOrganizationProductSettingsRequest;
use App\Http\Requests\UpdateOrganizationProductUnitConversionRequest;
use App\Http\Requests\UpdateProductMasterRequest;
use App\Http\Resources\OrganizationProductResource;
use App\Models\Organization;
use App\Models\OrganizationProduct;
use App\Models\OrganizationProductComponent;
use App\Models\OrganizationProductSource;
use App\Models\OrganizationProductUnitConversion;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorProductOffering;
use App\Support\Catalog\ComponentCost\ComponentCostMapper;
use App\Support\Catalog\OrganizationProductCatalogService;
use App\Support\Catalog\OrganizationProductComponentService;
use App\Support\Catalog\OrganizationProductUnitConversionService;
use App\Support\Money;
use App\Support\Pricing\InvalidPricingException;
use App\Support\Pricing\PricingCalculator;
use App\Support\Pricing\PricingInput;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationProductController extends Controller
{
    use RequiresTenantContext;
    use ScopesQueriesToTenant;

    public function __construct(
        private OrganizationProductCatalogService $catalog,
        private OrganizationProductUnitConversionService $conversions,
        private OrganizationProductComponentService $components,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', OrganizationProduct::class);

        /** @var User $user */
        $user = $request->user();
        $tenant = $this->requireTenantContext();

        $query = OrganizationProduct::query()
            ->with(['product.vendor:id,name', 'product.category:id,name', 'unitConversions'])
            ->where('organization_id', $tenant->organizationId)
            ->where('parent_account_id', $tenant->parentAccountId);

        $query->when($request->string('search')->toString(), function ($builder, string $search): void {
            $builder->where(function ($inner) use ($search): void {
                $inner->where('display_name', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhereHas('product', function ($productQuery) use ($search): void {
                        $productQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%");
                    });
            });
        });

        $family = $request->string('product_family')->toString();
        if ($family !== '') {
            $query->whereHas('product', fn ($productQuery) => $productQuery->where('product_family', $family));
        }

        if ($request->has('is_available') && $request->string('is_available')->toString() !== '') {
            $query->where('is_available', $request->boolean('is_available'));
        }

        $itemKind = $request->string('item_kind')->toString();
        if ($itemKind !== '') {
            $query->whereHas('product', fn ($productQuery) => $productQuery->where('item_kind', $itemKind));
        }

        if ($request->has('is_sellable') && $request->string('is_sellable')->toString() !== '') {
            $query->where('is_sellable', $request->boolean('is_sellable'));
        }

        if ($request->has('is_purchasable') && $request->string('is_purchasable')->toString() !== '') {
            $query->where('is_purchasable', $request->boolean('is_purchasable'));
        }

        $inventoryMode = $request->string('inventory_tracking_mode')->toString();
        if ($inventoryMode !== '') {
            $query->where('inventory_tracking_mode', $inventoryMode);
        }

        $products = $query
            ->latest('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (OrganizationProduct $organizationProduct): array => OrganizationProductResource::make($organizationProduct, $user));

        return Inertia::render('products/Index', [
            'products' => $products,
            'filters' => [
                'search' => $request->string('search')->toString(),
                'product_family' => $family !== '' ? $family : null,
                'is_available' => $request->has('is_available') && $request->string('is_available')->toString() !== ''
                    ? $request->boolean('is_available')
                    : null,
                'item_kind' => $itemKind !== '' ? $itemKind : null,
                'is_sellable' => $request->has('is_sellable') && $request->string('is_sellable')->toString() !== ''
                    ? $request->boolean('is_sellable')
                    : null,
                'is_purchasable' => $request->has('is_purchasable') && $request->string('is_purchasable')->toString() !== ''
                    ? $request->boolean('is_purchasable')
                    : null,
                'inventory_tracking_mode' => $inventoryMode !== '' ? $inventoryMode : null,
            ],
            'families' => collect(ProductFamily::cases())->map(fn (ProductFamily $family): array => [
                'value' => $family->value,
                'label' => ucfirst($family->value),
            ]),
            'itemKinds' => $this->itemKindOptions(),
            'inventoryModes' => $this->inventoryModeOptions(),
            'canCreate' => $user->can('create', OrganizationProduct::class),
            'canAssociate' => $user->can('associate', OrganizationProduct::class),
            'canViewCost' => $tenant->canViewCost(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', OrganizationProduct::class);

        return Inertia::render('products/Create', $this->formOptions());
    }

    public function store(StoreOrganizationProductRequest $request): RedirectResponse
    {
        $tenant = $this->requireTenantContext();
        $data = $request->validated();

        $master = [
            'name' => $data['name'],
            'sku' => $data['sku'],
            'product_family' => $data['product_family'],
            'item_kind' => $data['item_kind'],
            'vendor_sku' => $data['vendor_sku'] ?? null,
            'vendor_id' => $data['vendor_id'] ?? null,
            'product_category_id' => $data['product_category_id'] ?? null,
            'unit_of_measure' => $data['unit_of_measure'],
            'description' => $data['description'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ];

        $organization = [
            'display_name' => $data['display_name'] ?? null,
            'is_available' => $request->boolean('is_available', true),
            'is_sellable' => $data['is_sellable'],
            'is_purchasable' => $data['is_purchasable'],
            'inventory_tracking_mode' => $data['inventory_tracking_mode'],
            'purchase_unit_of_measure' => $data['purchase_unit_of_measure'] ?? null,
            'stock_unit_of_measure' => $data['stock_unit_of_measure'] ?? null,
            'usage_unit_of_measure' => $data['usage_unit_of_measure'] ?? null,
            'lead_time_days' => $data['lead_time_days'] ?? null,
            'organization_notes' => $data['organization_notes'] ?? null,
            'material_cost_micro_units' => $data['material_cost_micro_units'],
            'labor_cost_micro_units' => $data['labor_cost_micro_units'],
            'overhead_mode' => $data['overhead_mode'],
            'overhead_amount_micro_units' => $data['overhead_amount_micro_units'],
            'overhead_rate_basis_points' => $data['overhead_rate_basis_points'],
            'pricing_method' => $data['pricing_method'],
            'markup_basis_points' => $data['markup_basis_points'],
            'target_margin_basis_points' => $data['target_margin_basis_points'],
            'fixed_price_cents' => $data['fixed_price_cents'],
            'minimum_price_cents' => $data['minimum_price_cents'],
            'allow_price_override' => $data['allow_price_override'],
        ];

        $organizationProduct = $this->catalog->createMasterWithOrganizationProduct(
            $tenant,
            $request->user(),
            $request,
            $master,
            $organization,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product added to organization catalog.')]);

        return redirect()->to(TenantRoute::to('products.show', $organizationProduct));
    }

    public function createFromMaster(): Response
    {
        $this->authorize('associate', OrganizationProduct::class);
        $tenant = $this->requireTenantContext();

        $existingProductIds = OrganizationProduct::query()
            ->where('organization_id', $tenant->organizationId)
            ->pluck('product_id');

        $availableMasters = Product::query()
            ->where('parent_account_id', $tenant->parentAccountId)
            ->where('is_active', true)
            ->whereNotIn('id', $existingProductIds)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'product_family', 'item_kind', 'unit_of_measure']);

        return Inertia::render('products/AddExisting', [
            ...$this->formOptions(),
            'availableMasters' => $availableMasters,
        ]);
    }

    public function associate(AssociateOrganizationProductRequest $request): RedirectResponse
    {
        $tenant = $this->requireTenantContext();
        $data = $request->validated();
        $product = Product::query()->whereKey($data['product_id'])->firstOrFail();

        $organizationProduct = $this->catalog->associateExistingMaster(
            $tenant,
            $request->user(),
            $request,
            $product,
            $data,
            (bool) ($data['pricing_complete'] ?? false),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product master associated with this organization.')]);

        return redirect()->to(TenantRoute::to('products.show', $organizationProduct));
    }

    public function show(?Organization $organization, OrganizationProduct $organizationProduct): Response
    {
        $this->authorize('view', $organizationProduct);

        /** @var User $user */
        $user = request()->user();
        $organizationProduct->load([
            'product.vendor:id,name',
            'product.category:id,name',
            'unitConversions',
            'components.componentOrganizationProduct.product',
            'components.componentOrganizationProduct.unitConversions',
        ]);

        $tenant = $this->requireTenantContext();

        return Inertia::render('products/Show', [
            'product' => OrganizationProductResource::make($organizationProduct, $user),
            'vendorOfferings' => VendorProductOfferingController::filteredOfferingsForProduct(
                request(),
                $tenant->parentAccountId,
                $organizationProduct->product_id,
            ),
            'offeringFilters' => [
                'offering_search' => request()->string('offering_search')->toString(),
                'offering_status' => request()->string('offering_status')->toString(),
            ],
            'canUpdateMaster' => $user->can('updateMaster', $organizationProduct),
            'canUpdateSettings' => $user->can('updateSettings', $organizationProduct),
            'canManageConversions' => $user->can('updateSettings', $organizationProduct),
            'canManageComponents' => $user->can('manageComponents', $organizationProduct),
            'canManageOfferings' => $user->can('create', VendorProductOffering::class),
            'vendorSources' => OrganizationProductSourceController::sourcesForProduct($organizationProduct, request()),
            'canManageSources' => $user->can('attach', [OrganizationProductSource::class, $organizationProduct]),
            'canManageSourcePricing' => $user->can('updatePricing', $organizationProduct),
            'canClearPreferredSource' => $user->can('clearPreferred', [OrganizationProductSource::class, $organizationProduct]),
            'canUpdatePricing' => $user->can('updatePricing', $organizationProduct),
            'canUpdatePurchaseCost' => $user->can('updatePurchaseCost', $organizationProduct)
                && $organizationProduct->preferred_source_id === null,
            'canArchive' => $user->can('archive', $organizationProduct),
            'canViewCost' => $user->can('viewCost', $organizationProduct),
        ]);
    }

    public function editMaster(?Organization $organization, OrganizationProduct $organizationProduct): Response
    {
        $this->authorize('updateMaster', $organizationProduct);
        $organizationProduct->load('product');

        $associatedOrganizationCount = OrganizationProduct::query()
            ->where('product_id', $organizationProduct->product_id)
            ->count();

        return Inertia::render('products/EditMaster', [
            'product' => OrganizationProductResource::make($organizationProduct, request()->user()),
            'associatedOrganizationCount' => $associatedOrganizationCount,
            ...$this->formOptions(),
        ]);
    }

    public function updateMaster(
        UpdateProductMasterRequest $request,
        ?Organization $organization,
        OrganizationProduct $organizationProduct,
    ): RedirectResponse {
        $tenant = $this->requireTenantContext();
        $this->catalog->updateMaster($tenant, $request->user(), $request, $organizationProduct, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Shared product information updated.')]);

        return redirect()->to(TenantRoute::to('products.show', $organizationProduct));
    }

    public function editSettings(?Organization $organization, OrganizationProduct $organizationProduct): Response
    {
        $this->authorize('updateSettings', $organizationProduct);
        $organizationProduct->load(['product', 'unitConversions', 'components.componentOrganizationProduct.product']);

        return Inertia::render('products/EditSettings', [
            'product' => OrganizationProductResource::make($organizationProduct, request()->user()),
            'units' => collect(UnitOfMeasure::cases())->map(fn (UnitOfMeasure $unit): array => [
                'value' => $unit->value,
                'label' => $unit->label(),
            ]),
            'inventoryModes' => $this->inventoryModeOptions(),
            'itemKind' => $organizationProduct->product->item_kind->value,
            'canManageConversions' => request()->user()->can('updateSettings', $organizationProduct),
            'canUpdatePurchaseCost' => request()->user()->can('updatePurchaseCost', $organizationProduct)
                && $organizationProduct->preferred_source_id === null,
            'hasPreferredSource' => $organizationProduct->preferred_source_id !== null,
            'preferredSourceId' => $organizationProduct->preferred_source_id,
        ]);
    }

    public function updateSettings(
        UpdateOrganizationProductSettingsRequest $request,
        ?Organization $organization,
        OrganizationProduct $organizationProduct,
    ): RedirectResponse {
        $tenant = $this->requireTenantContext();
        $this->catalog->updateSettings($tenant, $request->user(), $request, $organizationProduct, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Organization product settings updated.')]);

        return redirect()->to(TenantRoute::to('products.show', $organizationProduct));
    }

    public function editPricing(?Organization $organization, OrganizationProduct $organizationProduct): Response
    {
        $this->authorize('updatePricing', $organizationProduct);
        $organizationProduct->load([
            'product',
            'components.componentOrganizationProduct.product',
            'components.componentOrganizationProduct.unitConversions',
        ]);

        $user = request()->user();

        return Inertia::render('products/EditPricing', [
            'product' => OrganizationProductResource::make($organizationProduct, $user),
            'canManageComponents' => $user->can('manageComponents', $organizationProduct),
            'canViewCost' => $user->can('viewCost', $organizationProduct),
            'componentCandidates' => $this->componentCandidates($organizationProduct),
            'units' => collect(UnitOfMeasure::cases())->map(fn (UnitOfMeasure $unit): array => [
                'value' => $unit->value,
                'label' => $unit->label(),
            ]),
            'overheadModes' => collect(OverheadMode::cases())->map(fn (OverheadMode $mode): array => [
                'value' => $mode->value,
                'label' => match ($mode) {
                    OverheadMode::None => 'None',
                    OverheadMode::Fixed => 'Fixed amount',
                    OverheadMode::Rate => 'Percentage of material + labor',
                },
            ]),
            'pricingMethods' => collect(PricingMethod::cases())->map(fn (PricingMethod $method): array => [
                'value' => $method->value,
                'label' => match ($method) {
                    PricingMethod::Markup => 'Markup on cost',
                    PricingMethod::TargetMargin => 'Target margin on selling price',
                    PricingMethod::Fixed => 'Fixed selling price',
                },
            ]),
        ]);
    }

    public function updatePricing(
        UpdateOrganizationProductPricingRequest $request,
        ?Organization $organization,
        OrganizationProduct $organizationProduct,
    ): RedirectResponse {
        $tenant = $this->requireTenantContext();
        $data = $request->validated();
        $expectedVersion = (int) $data['pricing_version'];
        $expectedComponentsVersion = (int) $data['components_version'];
        unset($data['pricing_version'], $data['components_version']);

        $this->catalog->updatePricing(
            $tenant,
            $request->user(),
            $request,
            $organizationProduct,
            $data,
            $expectedVersion,
            $expectedComponentsVersion,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Organization product pricing updated.')]);

        return redirect()->to(TenantRoute::to('products.show', $organizationProduct));
    }

    public function updatePurchaseCost(
        UpdateOrganizationProductPurchaseCostRequest $request,
        ?Organization $organization,
        OrganizationProduct $organizationProduct,
    ): RedirectResponse {
        $tenant = $this->requireTenantContext();
        $data = $request->validated();

        $this->catalog->updatePurchaseCost(
            $tenant,
            $request->user(),
            $request,
            $organizationProduct,
            $data['purchase_cost_micro_units'],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Purchase cost updated.')]);

        return redirect()->to(TenantRoute::to('products.edit-settings', $organizationProduct));
    }

    public function archive(?Organization $organization, OrganizationProduct $organizationProduct): RedirectResponse
    {
        $this->authorize('archive', $organizationProduct);
        $tenant = $this->requireTenantContext();
        $this->catalog->archive($tenant, request()->user(), request(), $organizationProduct);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product archived for this organization.')]);

        return redirect()->to(TenantRoute::to('products.index'));
    }

    public function previewPricing(PreviewOrganizationProductPricingRequest $request): JsonResponse
    {
        $tenant = $this->requireTenantContext();
        $data = $request->validated();

        $organizationProduct = OrganizationProduct::query()
            ->whereKey($data['organization_product_id'])
            ->first();

        if (
            $organizationProduct === null
            || $organizationProduct->organization_id !== $tenant->organizationId
            || $organizationProduct->parent_account_id !== $tenant->parentAccountId
        ) {
            abort(404);
        }

        $resolved = $this->catalog->resolvePreviewMaterialCost(
            $organizationProduct,
            $data,
            (int) $data['pricing_version'],
            (int) $data['components_version'],
        );

        try {
            $input = new PricingInput(
                materialCostMicroUnits: $resolved['material_cost_micro_units'],
                laborCostMicroUnits: (int) $data['labor_cost_micro_units'],
                overheadMode: OverheadMode::from((string) $data['overhead_mode']),
                overheadAmountMicroUnits: (int) $data['overhead_amount_micro_units'],
                overheadRateBasisPoints: (int) $data['overhead_rate_basis_points'],
                pricingMethod: PricingMethod::from((string) $data['pricing_method']),
                markupBasisPoints: (int) $data['markup_basis_points'],
                targetMarginBasisPoints: (int) $data['target_margin_basis_points'],
                fixedPriceCents: $data['fixed_price_cents'] !== null ? (int) $data['fixed_price_cents'] : null,
                minimumPriceCents: $data['minimum_price_cents'] !== null ? (int) $data['minimum_price_cents'] : null,
                allowPriceOverride: (bool) $data['allow_price_override'],
                requestedOverridePriceCents: null,
                quantity: (string) ($data['quantity'] ?? '1'),
                currencyCode: PricingCalculator::CURRENCY_USD,
                pricingVersion: (int) $data['pricing_version'],
            );

            $result = (new PricingCalculator)->calculate($input);
        } catch (InvalidPricingException $exception) {
            throw ValidationException::withMessages([
                'pricing' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'unit_cost' => Money::microUnitsToDollars($result->totalUnitCostMicroUnits),
            'unit_selling_price' => Money::centsToDollars($result->finalUnitPriceCents),
            'extended_selling_price' => Money::centsToDollars($result->extendedPriceCents),
            'below_minimum' => $result->belowMinimum,
            'approval_required' => $result->approvalRequired,
            'warnings' => $result->warnings,
            'quantity' => $result->quantity,
            'material_cost' => Money::microUnitsToDollars($resolved['material_cost_micro_units']),
            'material_source' => $resolved['material_source'],
            'pricing_version' => $organizationProduct->pricing_version,
            'components_version' => $organizationProduct->components_version,
        ]);
    }

    public function storeComponent(
        StoreOrganizationProductComponentRequest $request,
        ?Organization $organization,
        OrganizationProduct $organizationProduct,
    ): RedirectResponse {
        $tenant = $this->requireTenantContext();
        $data = $request->validated();

        $this->components->create(
            $tenant,
            $request->user(),
            $request,
            $organizationProduct,
            [
                'component_organization_product_id' => (int) $data['component_organization_product_id'],
                'quantity' => (string) $data['quantity'],
                'usage_uom' => (string) $data['usage_uom'],
                'waste_basis_points' => (int) ($data['waste_basis_points'] ?? 0),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'components_version' => (int) $data['components_version'],
            ],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Component added.')]);

        return redirect()->to(TenantRoute::to('products.edit-pricing', $organizationProduct));
    }

    public function updateComponent(
        UpdateOrganizationProductComponentRequest $request,
        ?Organization $organization,
        OrganizationProduct $organizationProduct,
        OrganizationProductComponent $component,
    ): RedirectResponse {
        $tenant = $this->requireTenantContext();
        $data = $request->validated();

        /** @var array{quantity: string, usage_uom: string, waste_basis_points?: int, sort_order?: int, components_version: int} $payload */
        $payload = [
            'quantity' => (string) $data['quantity'],
            'usage_uom' => (string) $data['usage_uom'],
            'waste_basis_points' => (int) ($data['waste_basis_points'] ?? $component->waste_basis_points),
            'components_version' => (int) $data['components_version'],
        ];

        if (array_key_exists('sort_order', $data)) {
            $payload['sort_order'] = (int) $data['sort_order'];
        }

        $this->components->update(
            $tenant,
            $request->user(),
            $request,
            $organizationProduct,
            $component,
            $payload,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Component updated.')]);

        return redirect()->to(TenantRoute::to('products.edit-pricing', $organizationProduct));
    }

    public function deactivateComponent(
        DeactivateOrganizationProductComponentRequest $request,
        ?Organization $organization,
        OrganizationProduct $organizationProduct,
        OrganizationProductComponent $component,
    ): RedirectResponse {
        $tenant = $this->requireTenantContext();

        $this->components->deactivate(
            $tenant,
            $request->user(),
            $request,
            $organizationProduct,
            $component,
            (int) $request->validated('components_version'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Component deactivated.')]);

        return redirect()->to(TenantRoute::to('products.edit-pricing', $organizationProduct));
    }

    public function reactivateComponent(
        ReactivateOrganizationProductComponentRequest $request,
        ?Organization $organization,
        OrganizationProduct $organizationProduct,
        OrganizationProductComponent $component,
    ): RedirectResponse {
        $tenant = $this->requireTenantContext();

        $this->components->reactivate(
            $tenant,
            $request->user(),
            $request,
            $organizationProduct,
            $component,
            (int) $request->validated('components_version'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Component reactivated.')]);

        return redirect()->to(TenantRoute::to('products.edit-pricing', $organizationProduct));
    }

    public function storeConversion(
        StoreOrganizationProductUnitConversionRequest $request,
        ?Organization $organization,
        OrganizationProduct $organizationProduct,
    ): RedirectResponse {
        $tenant = $this->requireTenantContext();

        $this->conversions->create(
            $tenant,
            $request->user(),
            $request,
            $organizationProduct,
            [
                'from_unit' => (string) $request->validated('from_unit'),
                'to_unit' => (string) $request->validated('to_unit'),
                'numerator' => (int) $request->validated('numerator'),
                'denominator' => (int) $request->validated('denominator'),
                'is_active' => $request->boolean('is_active', true),
            ],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Unit conversion added.')]);

        return redirect()->to(TenantRoute::to('products.edit-settings', $organizationProduct));
    }

    public function updateConversion(
        UpdateOrganizationProductUnitConversionRequest $request,
        ?Organization $organization,
        OrganizationProduct $organizationProduct,
        OrganizationProductUnitConversion $unitConversion,
    ): RedirectResponse {
        $tenant = $this->requireTenantContext();

        $this->conversions->update(
            $tenant,
            $request->user(),
            $request,
            $organizationProduct,
            $unitConversion,
            [
                'from_unit' => (string) $request->validated('from_unit'),
                'to_unit' => (string) $request->validated('to_unit'),
                'numerator' => (int) $request->validated('numerator'),
                'denominator' => (int) $request->validated('denominator'),
                'is_active' => $request->has('is_active')
                    ? $request->boolean('is_active')
                    : $unitConversion->is_active,
            ],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Unit conversion updated.')]);

        return redirect()->to(TenantRoute::to('products.edit-settings', $organizationProduct));
    }

    public function deactivateConversion(
        ?Organization $organization,
        OrganizationProduct $organizationProduct,
        OrganizationProductUnitConversion $unitConversion,
    ): RedirectResponse {
        $tenant = $this->requireTenantContext();

        $this->conversions->deactivate(
            $tenant,
            request()->user(),
            request(),
            $organizationProduct,
            $unitConversion,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Unit conversion deactivated.')]);

        return redirect()->to(TenantRoute::to('products.edit-settings', $organizationProduct));
    }

    public function reactivateConversion(
        ?Organization $organization,
        OrganizationProduct $organizationProduct,
        OrganizationProductUnitConversion $unitConversion,
    ): RedirectResponse {
        $tenant = $this->requireTenantContext();

        $this->conversions->reactivate(
            $tenant,
            request()->user(),
            request(),
            $organizationProduct,
            $unitConversion,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Unit conversion reactivated.')]);

        return redirect()->to(TenantRoute::to('products.edit-settings', $organizationProduct));
    }

    public function previewConversion(
        PreviewOrganizationProductUnitConversionRequest $request,
        ?Organization $organization,
        OrganizationProduct $organizationProduct,
    ): JsonResponse {
        $this->requireTenantContext();
        $data = $request->validated();

        $preview = $this->conversions->preview(
            $data['from_unit'],
            $data['to_unit'],
            (int) $data['numerator'],
            (int) $data['denominator'],
        );

        return response()->json($preview);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function itemKindOptions(): array
    {
        return collect(ItemKind::cases())->map(fn (ItemKind $kind): array => [
            'value' => $kind->value,
            'label' => $kind->label(),
        ])->all();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function inventoryModeOptions(): array
    {
        return collect(InventoryTrackingMode::cases())->map(fn (InventoryTrackingMode $mode): array => [
            'value' => $mode->value,
            'label' => $mode->label(),
        ])->all();
    }

    /**
     * Materials in the current organization that may be selected as components.
     *
     * @return array<int, array{id: int, display_name: string, sku: string|null, purchase_unit_of_measure: string|null, purchase_unit_of_measure_label: string|null, eligible: bool, disabled_reason: string|null}>
     */
    private function componentCandidates(OrganizationProduct $finished): array
    {
        $tenant = $this->requireTenantContext();
        $mapper = new ComponentCostMapper;
        $usageDefault = $finished->usage_unit_of_measure ?? UnitOfMeasure::SquareFoot;

        $materials = OrganizationProduct::query()
            ->with(['product', 'unitConversions'])
            ->where('organization_id', $tenant->organizationId)
            ->where('parent_account_id', $tenant->parentAccountId)
            ->whereKeyNot($finished->id)
            ->whereHas('product', fn ($query) => $query->where('item_kind', ItemKind::Material->value))
            ->orderBy('id')
            ->get();

        $existingIds = $finished->components
            ->where('is_active', true)
            ->pluck('component_organization_product_id')
            ->all();

        return $materials->map(function (OrganizationProduct $material) use ($mapper, $usageDefault, $existingIds): array {
            $reason = $mapper->materialIneligibilityReason($material, $usageDefault);
            if (in_array($material->id, $existingIds, true)) {
                $reason = 'Already added as an active component.';
            }

            $product = $material->product;

            return [
                'id' => $material->id,
                'display_name' => $material->display_name ?: ($product->name ?? 'Material'),
                'sku' => $product->sku,
                'purchase_unit_of_measure' => $material->purchase_unit_of_measure?->value,
                'purchase_unit_of_measure_label' => $material->purchase_unit_of_measure?->label(),
                'eligible' => $reason === null,
                'disabled_reason' => $reason,
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        $vendorsQuery = Vendor::query()->where('is_active', true)->orderBy('name');
        $categoriesQuery = ProductCategory::query()->orderBy('sort_order')->orderBy('name');

        if (TenantContext::has()) {
            $vendorsQuery = $this->scopeVendorsForRequest($vendorsQuery);
            $categoriesQuery = $this->scopeCategoriesForRequest($categoriesQuery);
        }

        return [
            'vendors' => $vendorsQuery->get(['id', 'name']),
            'categories' => $categoriesQuery->get(['id', 'name']),
            'units' => collect(UnitOfMeasure::cases())->map(fn (UnitOfMeasure $unit): array => [
                'value' => $unit->value,
                'label' => $unit->label(),
            ]),
            'families' => collect(ProductFamily::cases())->map(fn (ProductFamily $family): array => [
                'value' => $family->value,
                'label' => ucfirst($family->value),
            ]),
            'itemKinds' => $this->itemKindOptions(),
            'inventoryModes' => $this->inventoryModeOptions(),
            'overheadModes' => collect(OverheadMode::cases())->map(fn (OverheadMode $mode): array => [
                'value' => $mode->value,
                'label' => match ($mode) {
                    OverheadMode::None => 'None',
                    OverheadMode::Fixed => 'Fixed amount',
                    OverheadMode::Rate => 'Percentage of material + labor',
                },
            ]),
            'pricingMethods' => collect(PricingMethod::cases())->map(fn (PricingMethod $method): array => [
                'value' => $method->value,
                'label' => match ($method) {
                    PricingMethod::Markup => 'Markup on cost',
                    PricingMethod::TargetMargin => 'Target margin on selling price',
                    PricingMethod::Fixed => 'Fixed selling price',
                },
            ]),
        ];
    }
}
