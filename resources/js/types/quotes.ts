export type QuoteAddress = {
    line1?: string | null;
    line2?: string | null;
    city?: string | null;
    state?: string | null;
    postal_code?: string | null;
    country?: string | null;
};

export type QuoteLine = {
    id: number;
    position: number;
    line_type: 'catalog' | 'custom' | 'section' | 'note';
    is_financial: boolean;
    organization_product_id: number | null;
    product_id: number | null;
    sku_snapshot: string | null;
    name_snapshot: string;
    customer_description_snapshot: string | null;
    internal_description_snapshot: string | null;
    item_kind_snapshot: string | null;
    quantity: string | null;
    quantity_scaled: number;
    uom_snapshot: string | null;
    calculated_unit_price: string | null;
    calculated_unit_price_cents: number | null;
    final_unit_price: string | null;
    final_unit_price_cents: number | null;
    extended_price: string | null;
    line_discount_method: 'none' | 'fixed' | 'percentage';
    line_discount_value: number;
    line_discount_amount: string | null;
    net_line_total: string | null;
    net_line_total_cents: number;
    is_taxable: boolean;
    price_override: boolean;
    override_reason: string | null;
    below_minimum: boolean;
    approval_required: boolean;
    approval_reasons: string[];
    pricing_version_snapshot: number | null;
    components_version_snapshot: number | null;
    catalog_stale: boolean;
    /** Cost keys are absent entirely unless the viewer can see cost. */
    material_cost?: string | null;
    labor_cost?: string | null;
    overhead_cost?: string | null;
    unit_cost?: string | null;
    extended_cost?: string | null;
    margin_amount?: string | null;
    margin_percent?: string | null;
};

export type QuoteAdjustment = {
    id: number;
    position: number;
    adjustment_type: string;
    is_discount: boolean;
    description_snapshot: string;
    method: 'fixed' | 'percentage';
    input_value: number;
    amount: string;
    amount_cents: number;
    is_taxable: boolean;
    approval_required: boolean;
    approval_reasons: string[];
    reason: string | null;
};

export type QuotePartySnapshot = {
    id: number;
    selling_organization_name: string;
    selling_organization_slug: string;
    company_id: number;
    customer_company_name: string;
    customer_number: string | null;
    primary_contact_id: number | null;
    contact_name: string | null;
    contact_email: string | null;
    contact_phone: string | null;
    billing_address: QuoteAddress | null;
    service_address: QuoteAddress | null;
    salesperson_name: string | null;
    salesperson_email: string | null;
    preparer_name: string | null;
    preparer_email: string | null;
    customer_po_reference: string | null;
};

export type QuoteRevisionSummary = {
    id: number;
    quote_id: number;
    revision_number: number;
    source_revision_id: number | null;
    status: string;
    is_draft: boolean;
    lock_version: number;
    currency_code: string;
    issue_date: string | null;
    expiration_date: string | null;
    pretax_total: string;
    pretax_total_cents: number;
    approval_required: boolean;
    tax_calculation_status: string;
    tax_unresolved: boolean;
    tax_pending: boolean;
    totals_are_pretax: boolean;
    tax: string;
    tax_cents: number;
    /** Null until the tax engine resolves a position; a total with unknown tax is not a total. */
    grand_total: string | null;
    grand_total_cents: number | null;
    tax_message: string | null;
    created_at: string | null;
};

export type QuoteCostSummary = {
    total_cost: string;
    margin_amount: string;
    margin_percent: string | null;
    covers_all_lines: boolean;
};

export type QuoteRevisionDetail = QuoteRevisionSummary & {
    introduction: string | null;
    terms_text: string | null;
    customer_notes: string | null;
    internal_notes: string | null;
    subtotal: string;
    discount_total: string;
    provisional_taxable_amount: string;
    requested_deposit: string | null;
    approval_reasons: string[];
    lines: QuoteLine[];
    adjustments: QuoteAdjustment[];
    party_snapshot: QuotePartySnapshot | null;
    cost_summary?: QuoteCostSummary;
};

export type QuoteSummary = {
    id: number;
    quote_number: string;
    lifecycle_status: string;
    deal_id: number;
    current_revision_id: number | null;
    accepted_revision_id: number | null;
    lock_version: number;
    current_revision: QuoteRevisionSummary | null;
    created_at: string | null;
    can_update: boolean;
    can_void: boolean;
};

export type QuoteDetail = QuoteSummary & {
    deal: { id: number; name: string } | null;
    revisions: QuoteRevisionSummary[];
};

export type TaxRate = {
    id: number;
    jurisdiction_code: string;
    display_name: string;
    country: string;
    state: string | null;
    county: string | null;
    city: string | null;
    postal_code: string | null;
    rate_ppm: number;
    rate_percent: string;
    effective_from: string;
    effective_through: string | null;
    is_active: boolean;
    source_note: string | null;
    created_at: string | null;
};

export type TaxProfile = {
    id: number;
    default_country: string;
    default_state: string | null;
    sourcing_strategy: string;
    sourcing_strategy_label: string;
    registration_reference: string | null;
    tax_calculation_enabled: boolean;
    is_active: boolean;
    configuration_version: number;
    updated_at: string | null;
};

/**
 * Certificate number, evidence reference, internal notes, and rejection reason are
 * absent entirely — not null — without certificate view authority.
 */
export type TaxCertificate = {
    id: number;
    organization_company_id: number;
    exemption_category: string;
    exemption_category_label: string;
    jurisdiction_state: string;
    certificate_form_type: string;
    verification_status: string;
    verification_status_label: string;
    effective_date: string;
    expiration_date: string | null;
    verified_at: string | null;
    certificate_reference: string;
    has_evidence: boolean;
    has_rejection_reason: boolean;
    is_editable: boolean;
    can_support_exemption: boolean;
    certificate_number?: string | null;
    evidence_reference?: string | null;
    internal_notes?: string | null;
    rejection_reason?: string | null;
};

export type TaxCalculation = {
    id: number;
    calculation_version: number;
    outcome: string;
    is_resolved: boolean;
    taxable_basis: string;
    taxable_basis_cents: number;
    rate_ppm: number | null;
    rate_percent: string | null;
    tax: string;
    tax_cents: number;
    jurisdiction: Record<string, string | null> | null;
    source: string;
    is_override: boolean;
    override_reason: string | null;
    certificate_reference: string | null;
    calculator_version: string;
    calculated_at: string;
};

export type QuoteTaxPanel = {
    status: string;
    is_resolved: boolean;
    /** Why the engine could not resolve a position, when it could not. */
    review_reasons: string[];
    current: TaxCalculation | null;
    history: TaxCalculation[];
    profile: TaxProfile | null;
    rates: TaxRate[];
    certificates: TaxCertificate[];
    service_address: QuoteAddress | null;
    billing_address: QuoteAddress | null;
    can_calculate: boolean;
    can_override: boolean;
    disclaimer: string;
};

export type ApprovalRequest = {
    id: number;
    quote_id: number;
    quote_revision_id: number;
    request_version: number;
    status: string;
    is_open: boolean;
    reasons: string[];
    explanations: Record<string, string>;
    threshold_basis: string;
    threshold_basis_cents: number;
    requested_by: string | null;
    requested_by_membership_id: number;
    requested_at: string;
    age_days: number;
    resolved_at: string | null;
    quote_number: string | null;
    quote_lock_version: number | null;
    revision_number: number | null;
    revision_status: string | null;
    revision_lock_version: number | null;
    pretax_total: string;
    pretax_total_cents: number;
    tax_calculation_status: string | null;
};

export type QuoteApprovalPanel = {
    status: string;
    approval_required: boolean;
    reasons: string[];
    explanations: Record<string, string>;
    current_request: ApprovalRequest | null;
    reason_catalog: Record<string, string>;
    can_evaluate: boolean;
    can_submit: boolean;
    can_withdraw: boolean;
    can_return_to_draft: boolean;
    can_decide: boolean;
    blocked_by_tax: boolean;
};

export type CatalogOption = {
    id: number;
    display_name: string;
    sku: string;
    unit_of_measure: string;
    currency_code: string;
    allow_price_override: boolean;
    unit_selling_price: string | null;
    minimum_price?: string;
};
