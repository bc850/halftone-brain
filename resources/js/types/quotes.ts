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
