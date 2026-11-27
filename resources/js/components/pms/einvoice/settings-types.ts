/** The saved e-invoice settings, as Settings › E-invoicing receives them. */
export type EInvoiceSetting = {
    enabled: boolean;
    api_environment: string;
    client_id: string;
    has_client_secret: boolean;
    supplier_name: string | null;
    supplier_tin: string | null;
    supplier_brn: string | null;
    supplier_sst_registration: string | null;
    supplier_ttx_registration: string | null;
    supplier_msic_code: string | null;
    supplier_business_description: string | null;
    supplier_email: string | null;
    supplier_phone: string | null;
    supplier_address_line1: string | null;
    supplier_address_line2: string | null;
    supplier_city: string | null;
    supplier_postal_code: string | null;
    supplier_state_code: string | null;
    auto_submit_on_checkout: boolean;
    consolidation_enabled: boolean;
    effective_from: string | null;
};

/** The property's own details, shown in a Business details field the settings leave empty. */
export type EInvoicePropertyDefaults = {
    brn: string;
    legal_name: string | null;
    tin: string | null;
    msic_code: string | null;
    business_description: string | null;
    address_line1: string | null;
    address_line2: string | null;
    city: string | null;
    postal_code: string | null;
    state_code: string | null;
    contact_email: string | null;
    contact_phone: string | null;
};

export const ENVIRONMENT_LABEL: Record<string, string> = {
    mock: 'Test (offline)',
    sandbox: 'LHDN test site',
    production: 'LHDN (live)',
};
