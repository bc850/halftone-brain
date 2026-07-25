<?php

namespace App\Http\Controllers;

use App\Enums\OverheadMode;
use App\Enums\PricingMethod;
use App\Enums\ProductFamily;
use App\Enums\UnitOfMeasure;
use App\Http\Controllers\Concerns\RequiresTenantContext;
use App\Http\Controllers\Concerns\ScopesQueriesToTenant;
use App\Http\Requests\AssociateOrganizationProductRequest;
use App\Http\Requests\PreviewOrganizationProductPricingRequest;
use App\Http\Requests\StoreOrganizationProductRequest;
use App\Http\Requests\UpdateOrganizationProductPricingRequest;
use App\Http\Requests\UpdateOrganizationProductSettingsRequest;
use App\Http\Requests\UpdateProductMasterRequest;
use App\Http\Resources\OrganizationProductResource;
use App\Models\Organization;
use App\Models\OrganizationProduct;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Catalog\OrganizationProductCatalogService;
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

    public function __construct(private OrganizationProductCatalogService $catalog) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', OrganizationProduct::class);

        /** @var User $user */
        $user = $request->user();
        $tenant = $this->requireTenantContext();

        $query = OrganizationProduct::query()
            ->with(['product.vendor:id,name', 'product.category:id,name'])
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
            ],
            'families' => collect(ProductFamily::cases())->map(fn (ProductFamily $family): array => [
                'value' => $family->value,
                'label' => ucfirst($family->value),
            ]),
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
            ->get(['id', 'name', 'sku', 'product_family', 'unit_of_measure']);

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
        $organizationProduct->load(['product.vendor:id,name', 'product.category:id,name']);

        return Inertia::render('products/Show', [
            'product' => OrganizationProductResource::make($organizationProduct, $user),
            'canUpdateMaster' => $user->can('updateMaster', $organizationProduct),
            'canUpdateSettings' => $user->can('updateSettings', $organizationProduct),
            'canUpdatePricing' => $user->can('updatePricing', $organizationProduct),
            'canArchive' => $user->can('archive', $organizationProduct),
            'canViewCost' => $user->can('viewCost', $organizationProduct),
        ]);
    }

    public function editMaster(?Organization $organization, OrganizationProduct $organizationProduct): Response
    {
        $this->authorize('updateMaster', $organizationProduct);
        $organizationProduct->load('product');

        return Inertia::render('products/EditMaster', [
            'product' => OrganizationProductResource::make($organizationProduct, request()->user()),
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
        $organizationProduct->load('product');

        return Inertia::render('products/EditSettings', [
            'product' => OrganizationProductResource::make($organizationProduct, request()->user()),
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
        $organizationProduct->load('product');

        return Inertia::render('products/EditPricing', [
            'product' => OrganizationProductResource::make($organizationProduct, request()->user()),
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
        unset($data['pricing_version']);

        $this->catalog->updatePricing(
            $tenant,
            $request->user(),
            $request,
            $organizationProduct,
            $data,
            $expectedVersion,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Organization product pricing updated.')]);

        return redirect()->to(TenantRoute::to('products.show', $organizationProduct));
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
        $this->requireTenantContext();
        $data = $request->validated();

        try {
            $input = new PricingInput(
                materialCostMicroUnits: (int) $data['material_cost_micro_units'],
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
                pricingVersion: 1,
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
        ]);
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
