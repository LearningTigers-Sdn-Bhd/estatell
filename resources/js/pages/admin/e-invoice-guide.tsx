import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowUpRight,
    BookOpen,
    Building2,
    Calendar,
    CheckCircle2,
    Clock,
    FileText,
    Key,
    Receipt,
    ShieldCheck,
} from 'lucide-react';
import { PageHeader } from '@/components/pms/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { buttonVariants } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { dashboard, eInvoiceGuide } from '@/routes/admin';

export default function EInvoiceGuide() {
    return (
        <>
            <Head title="LHDN MyInvois E-Invoice Guideline" />

            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="LHDN MyInvois E-Invoice Guideline"
                    description="Official regulatory reference, implementation timeline, digital signing specifications, and hospitality rules for Malaysia Continuous Transaction Control (CTC)."
                    badge={
                        <Badge variant="secondary">LHDN MyInvois v4.9</Badge>
                    }
                />

                {/* Quick Notice */}
                <Alert className="border-primary/20 bg-primary/5">
                    <ShieldCheck className="text-primary" />
                    <AlertTitle className="font-semibold text-primary">
                        Property-Scoped Feature Toggle
                    </AlertTitle>
                    <AlertDescription className="text-sm text-muted-foreground">
                        E-invoicing is disabled by default for all properties.
                        Platform administrators can toggle it per property under{' '}
                        <strong>
                            Properties &rarr; [Property] &rarr; Features
                        </strong>{' '}
                        once the property’s legal TIN, SSM (BRN), and LHDN API
                        credentials have been verified.
                    </AlertDescription>
                </Alert>

                {/* Section 1: Rollout Timeline & Exemptions */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <Calendar className="size-5 text-primary" />
                                <CardTitle>
                                    Implementation Timeline & Revenue Thresholds
                                </CardTitle>
                            </div>
                            <a
                                href="https://www.hasil.gov.my/en/e-invois/rujukan-pelaksanaan-e-invois/garis-panduan/"
                                target="_blank"
                                rel="noreferrer"
                                className={buttonVariants({
                                    variant: 'outline',
                                    size: 'sm',
                                })}
                            >
                                Read More <ArrowUpRight className="size-3.5" />
                            </a>
                        </div>
                        <CardDescription>
                            Mandatory phased rollout established by the Inland
                            Revenue Board of Malaysia (LHDN / IRBM).
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="rounded-md border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Phase</TableHead>
                                        <TableHead>
                                            Annual Revenue / Turnover
                                        </TableHead>
                                        <TableHead>Mandatory Date</TableHead>
                                        <TableHead>
                                            Hospitality Scope & Applicability
                                        </TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow>
                                        <TableCell className="font-semibold">
                                            Phase 1
                                        </TableCell>
                                        <TableCell>
                                            &gt; RM100,000,000
                                        </TableCell>
                                        <TableCell>1 August 2024</TableCell>
                                        <TableCell>
                                            Large hotel chains, publicly listed
                                            hospitality groups, and luxury
                                            resort operators.
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant="outline"
                                                className="border-emerald-300 bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40"
                                            >
                                                Active
                                            </Badge>
                                        </TableCell>
                                    </TableRow>
                                    <TableRow>
                                        <TableCell className="font-semibold">
                                            Phase 2
                                        </TableCell>
                                        <TableCell>
                                            RM25,000,000 – RM100,000,000
                                        </TableCell>
                                        <TableCell>1 January 2025</TableCell>
                                        <TableCell>
                                            Mid-to-large hotels, multi-property
                                            operators, and high-volume leisure
                                            resorts.
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant="outline"
                                                className="border-emerald-300 bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40"
                                            >
                                                Active
                                            </Badge>
                                        </TableCell>
                                    </TableRow>
                                    <TableRow>
                                        <TableCell className="font-semibold">
                                            Phase 3
                                        </TableCell>
                                        <TableCell>
                                            RM5,000,000 – RM25,000,000
                                        </TableCell>
                                        <TableCell>1 July 2025</TableCell>
                                        <TableCell>
                                            Independent boutique hotels,
                                            serviced apartments, and medium
                                            holiday parks.
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant="secondary">
                                                Mandated
                                            </Badge>
                                        </TableCell>
                                    </TableRow>
                                    <TableRow>
                                        <TableCell className="font-semibold">
                                            Phase 4
                                        </TableCell>
                                        <TableCell>
                                            All remaining taxpayers (up to
                                            RM5,000,000)
                                        </TableCell>
                                        <TableCell>
                                            <div>1 January 2026</div>
                                            <div className="text-xs text-muted-foreground">
                                                Grace period until 31 Dec 2027
                                                <br />
                                                Full penalties start 1 Jan 2028
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            Budget hotels, homestays, bed &
                                            breakfasts, and small accommodation
                                            providers. During the extended
                                            transition period through 31
                                            December 2027, consolidated
                                            e-invoices are permitted, but single
                                            stays over RM10,000 must be
                                            individually e-invoiced in real
                                            time.
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant="secondary">
                                                Grace Period
                                            </Badge>
                                        </TableCell>
                                    </TableRow>
                                    <TableRow className="bg-muted/40 font-medium">
                                        <TableCell className="font-bold text-primary">
                                            Exemption
                                        </TableCell>
                                        <TableCell className="font-bold">
                                            &lt; RM3,000,000 Annual Turnover
                                        </TableCell>
                                        <TableCell>Ongoing Exemption</TableCell>
                                        <TableCell>
                                            Micro-enterprises with annual
                                            revenue below RM3M are formally
                                            exempted from mandatory e-invoice
                                            issuance. However, they may opt in
                                            voluntarily, and must provide an
                                            e-invoice if requested by a B2B
                                            corporate customer.
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant="outline"
                                                className="border-amber-300 bg-amber-50 text-amber-700 dark:bg-amber-950/40"
                                            >
                                                Exempted
                                            </Badge>
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>

                {/* Section 2: Hotel Operating Models */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <Building2 className="size-5 text-primary" />
                                <CardTitle>
                                    Hotel Operations: Individual vs.
                                    Consolidated Invoicing
                                </CardTitle>
                            </div>
                            <a
                                href="https://www.hasil.gov.my/wp-content/uploads/IRBM-e-Invoice-Specific-Guideline.pdf"
                                target="_blank"
                                rel="noreferrer"
                                className={buttonVariants({
                                    variant: 'outline',
                                    size: 'sm',
                                })}
                            >
                                Read More <ArrowUpRight className="size-3.5" />
                            </a>
                        </div>
                        <CardDescription>
                            How property checkouts, B2B corporate billing, and
                            walk-in retail transactions are handled under LHDN
                            regulations.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-4 md:grid-cols-2">
                        <div className="space-y-3 rounded-lg border bg-card p-4">
                            <div className="flex items-center gap-2">
                                <Receipt className="size-4 text-primary" />
                                <h4 className="text-sm font-semibold">
                                    Individual E-Invoice (Per Stay)
                                </h4>
                            </div>
                            <p className="text-xs leading-relaxed text-muted-foreground">
                                Issued upon check-out for corporate accounts
                                (City Ledger) or when requested by an individual
                                guest for tax relief or business expense
                                reimbursement.
                            </p>
                            <ul className="list-disc space-y-1.5 pl-4 text-xs text-muted-foreground">
                                <li>
                                    <strong>Domestic Guests:</strong> Must
                                    provide real Tax Identification Number (TIN)
                                    or NRIC.
                                </li>
                                <li>
                                    <strong>Foreign Guests:</strong> Must be
                                    filed using LHDN Foreign Buyer TIN
                                    placeholder <code>EI00000000020</code> plus
                                    passport number and country code.
                                </li>
                                <li>
                                    <strong>Corporate Direct-Bill:</strong> Must
                                    provide Company TIN and SSM (BRN).
                                </li>
                                <li>
                                    <strong>
                                        LHDN Rejection Rule (ERR228):
                                    </strong>{' '}
                                    General Public TIN (
                                    <code>EI00000000010</code>) is{' '}
                                    <em>strictly forbidden</em> on individual
                                    invoices.
                                </li>
                            </ul>
                        </div>

                        <div className="space-y-3 rounded-lg border bg-card p-4">
                            <div className="flex items-center gap-2">
                                <FileText className="size-4 text-primary" />
                                <h4 className="text-sm font-semibold">
                                    Monthly Consolidated E-Invoice (B2C)
                                </h4>
                            </div>
                            <p className="text-xs leading-relaxed text-muted-foreground">
                                For individual/walk-in guests who do not require
                                an official e-invoice, the hotel issues standard
                                PMS vouchers/receipts at check-out.
                            </p>
                            <ul className="list-disc space-y-1.5 pl-4 text-xs text-muted-foreground">
                                <li>
                                    <strong>7-Calendar-Day Window:</strong> All
                                    unrequested stays concluded in a calendar
                                    month must be batched and submitted by the{' '}
                                    <strong>
                                        7th calendar day of the following month
                                    </strong>
                                    .
                                </li>
                                <li>
                                    <strong>General Public Identifiers:</strong>{' '}
                                    Buyer TIN is set to{' '}
                                    <code>EI00000000010</code>, state code set
                                    to <code>17</code> (Not Applicable), and
                                    classification code <code>004</code>{' '}
                                    (Consolidated e-Invoice).
                                </li>
                                <li>
                                    <strong>RM10,000 High-Value Cap:</strong>{' '}
                                    Transactions exceeding RM10,000 cannot be
                                    consolidated and must be submitted as
                                    individual e-invoices.
                                </li>
                                <li>
                                    <strong>Batch Sizing:</strong> Batched in
                                    chunks of &le; 100 stays per document
                                    payload to avoid timeout limits.
                                </li>
                            </ul>
                        </div>

                        <div className="space-y-1 rounded-lg border border-amber-200/60 bg-amber-50/50 p-4 text-xs text-muted-foreground md:col-span-2 dark:bg-amber-950/20">
                            <p className="flex items-center gap-1.5 font-semibold text-foreground">
                                <Clock className="size-3.5 text-amber-600" />
                                Security Deposits &amp; Cancellation Fees Rule
                            </p>
                            <p>
                                Refundable security deposits collected at
                                check-in do not generate an e-invoice at the
                                time of collection. E-invoicing only applies to
                                finalized accommodation revenue upon check-out,
                                or when non-refundable room deposits and
                                cancellation penalties are retained.
                            </p>
                        </div>
                    </CardContent>
                </Card>

                {/* Section 3: Document Signing */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <Key className="size-5 text-primary" />
                                <CardTitle>
                                    Digital Document Signing (Version 1.0 vs.
                                    1.1)
                                </CardTitle>
                            </div>
                            <a
                                href="https://sdk.myinvois.hasil.gov.my/signature/"
                                target="_blank"
                                rel="noreferrer"
                                className={buttonVariants({
                                    variant: 'outline',
                                    size: 'sm',
                                })}
                            >
                                Read More <ArrowUpRight className="size-3.5" />
                            </a>
                        </div>
                        <CardDescription>
                            Cryptographic verification standards, X.509
                            certificate profiles, and payload signing workflows.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="space-y-2 rounded-lg border bg-card p-4">
                                <div className="flex items-center justify-between">
                                    <h4 className="text-sm font-semibold">
                                        Version 1.0 (Unsigned)
                                    </h4>
                                    <Badge variant="outline">
                                        Transitional
                                    </Badge>
                                </div>
                                <p className="text-xs leading-relaxed text-muted-foreground">
                                    Digital signature validation is not enforced
                                    by LHDN API on Version 1.0 payloads.
                                    Taxpayers can submit JSON/UBL documents
                                    using API client credentials only.
                                </p>
                                <p className="rounded bg-muted p-2 font-mono text-xs text-muted-foreground">
                                    InvoiceTypeCode: &quot;01&quot; |
                                    listVersionID: &quot;1.0&quot;
                                </p>
                            </div>

                            <div className="space-y-2 rounded-lg border bg-card p-4">
                                <div className="flex items-center justify-between">
                                    <h4 className="text-sm font-semibold">
                                        Version 1.1 (Signed)
                                    </h4>
                                    <Badge variant="default">
                                        Full Compliance
                                    </Badge>
                                </div>
                                <p className="text-xs leading-relaxed text-muted-foreground">
                                    Enforces cryptographic signing using an
                                    X.509 certificate issued by an accredited
                                    Malaysian Certification Authority (CA),
                                    verifying payload integrity and
                                    non-repudiation.
                                </p>
                                <p className="rounded bg-muted p-2 font-mono text-xs text-muted-foreground">
                                    InvoiceTypeCode: &quot;01&quot; |
                                    listVersionID: &quot;1.1&quot;
                                </p>
                            </div>
                        </div>

                        <div className="space-y-3 rounded-md border bg-muted/20 p-4">
                            <h4 className="text-xs font-semibold tracking-wider text-foreground uppercase">
                                Required X.509 Certificate Profile
                                Specifications
                            </h4>
                            <div className="grid grid-cols-1 gap-3 text-xs md:grid-cols-3">
                                <div className="rounded border bg-background p-2.5">
                                    <span className="block font-medium text-foreground">
                                        Accredited Malaysian CA
                                    </span>
                                    <span className="text-muted-foreground">
                                        Pos Digicert, MSC Trustgate, Amanah, or
                                        Telekom Applied Business.
                                    </span>
                                </div>
                                <div className="rounded border bg-background p-2.5">
                                    <span className="block font-medium text-foreground">
                                        Subject DN Mappings
                                    </span>
                                    <span className="text-muted-foreground">
                                        TIN in <code>2.5.4.97</code> (Org ID)
                                        and SSM/BRN in <code>2.5.4.5</code>{' '}
                                        (Serial Number).
                                    </span>
                                </div>
                                <div className="rounded border bg-background p-2.5">
                                    <span className="block font-medium text-foreground">
                                        Key Usages &amp; EKU
                                    </span>
                                    <span className="text-muted-foreground">
                                        Key Usage: Non-Repudiation; EKU:
                                        Document Signing (
                                        <code>1.3.6.1.4.1.311.10.3.12</code>).
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div className="space-y-2 rounded-md border bg-muted/20 p-4 text-xs text-muted-foreground">
                            <h4 className="text-xs font-semibold tracking-wider text-foreground uppercase">
                                Cryptographic Signing Pipeline in Estatell
                            </h4>
                            <p>
                                1. Remove any signature blocks and whitespace to
                                produce a canonical JSON string.
                                <br />
                                2. Compute the{' '}
                                <strong>SHA-256 Document Digest</strong> (
                                <code>DocDigest</code>).
                                <br />
                                3. Sign the digest with the hotel&apos;s RSA
                                private key using <strong>RSA-SHA256</strong>.
                                <br />
                                4. Compute the SHA-256 hash of the X.509
                                certificate (<code>CertDigest</code>) and inject
                                signed properties into the UBLExtensions node.
                            </p>
                        </div>
                    </CardContent>
                </Card>

                {/* Section 4: 72-Hour Cancellation & Corrections */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <Clock className="size-5 text-primary" />
                                <CardTitle>
                                    72-Hour Cancellation Window &amp; Credit
                                    Notes
                                </CardTitle>
                            </div>
                            <a
                                href="https://sdk.myinvois.hasil.gov.my/api/02-documents/"
                                target="_blank"
                                rel="noreferrer"
                                className={buttonVariants({
                                    variant: 'outline',
                                    size: 'sm',
                                })}
                            >
                                Read More <ArrowUpRight className="size-3.5" />
                            </a>
                        </div>
                        <CardDescription>
                            Handling errors, refunds, post-checkout charge
                            adjustments, and cancellation rules.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="space-y-2 rounded-lg border bg-card p-4">
                                <div className="flex items-center gap-2">
                                    <CheckCircle2 className="size-4 text-emerald-600" />
                                    <h4 className="text-sm font-semibold">
                                        Within 72 Hours of Validation
                                    </h4>
                                </div>
                                <p className="text-xs leading-relaxed text-muted-foreground">
                                    The document status can be updated to{' '}
                                    <strong>Cancelled</strong> directly through
                                    LHDN&apos;s state API (
                                    <code>
                                        PUT /api/v1.0/documents/state/{'{uuid}'}
                                        /state
                                    </code>
                                    ) with a reason.
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    <em>Condition:</em> The cancellation is only
                                    accepted if no active Credit Note or Debit
                                    Note already references this document.
                                </p>
                            </div>

                            <div className="space-y-2 rounded-lg border bg-card p-4">
                                <div className="flex items-center gap-2">
                                    <AlertTriangle className="size-4 text-amber-600" />
                                    <h4 className="text-sm font-semibold">
                                        After 72 Hours (Credit Note)
                                    </h4>
                                </div>
                                <p className="text-xs leading-relaxed text-muted-foreground">
                                    Once 72 hours have elapsed, LHDN rejects
                                    direct cancellation with HTTP 400. The
                                    original invoice remains permanently on
                                    record.
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    <em>Correction Mechanism:</em> The hotel
                                    must issue an official{' '}
                                    <strong>Credit Note (Type 02)</strong>{' '}
                                    containing a <code>BillingReference</code>{' '}
                                    node referencing the original invoice&apos;s
                                    UUID and invoice number.
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Section 5: Credible Resources Hub */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <BookOpen className="size-5 text-primary" />
                            <CardTitle>
                                Official Documentation &amp; Reference Hub
                            </CardTitle>
                        </div>
                        <CardDescription>
                            Primary sources, technical endpoints, developer
                            sandbox, and regulatory portals.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <a
                                href="https://www.hasil.gov.my/wp-content/uploads/IRBM-e-Invoice-Guideline.pdf"
                                target="_blank"
                                rel="noreferrer"
                                className="group rounded-lg border p-4 transition-colors hover:border-primary/50 hover:bg-muted/40"
                            >
                                <div className="mb-1 flex items-center justify-between">
                                    <span className="flex items-center gap-1.5 text-sm font-semibold group-hover:text-primary">
                                        IRBM e-Invoice Guideline (PDF){' '}
                                        <ArrowUpRight className="size-3.5" />
                                    </span>
                                    <Badge variant="outline">General</Badge>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Official general guideline covering scope,
                                    implementation phases, continuous
                                    transaction control workflow, mandatory data
                                    fields, and exemptions.
                                </p>
                            </a>

                            <a
                                href="https://www.hasil.gov.my/wp-content/uploads/IRBM-e-Invoice-Specific-Guideline.pdf"
                                target="_blank"
                                rel="noreferrer"
                                className="group rounded-lg border p-4 transition-colors hover:border-primary/50 hover:bg-muted/40"
                            >
                                <div className="mb-1 flex items-center justify-between">
                                    <span className="flex items-center gap-1.5 text-sm font-semibold group-hover:text-primary">
                                        IRBM e-Invoice Specific Guideline (PDF){' '}
                                        <ArrowUpRight className="size-3.5" />
                                    </span>
                                    <Badge variant="outline">Specific</Badge>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Detailed treatments for hospitality and
                                    tourism, consolidated monthly invoicing,
                                    disbursements vs. reimbursements, and
                                    cross-border transactions.
                                </p>
                            </a>

                            <a
                                href="https://www.hasil.gov.my/en/e-invois/rujukan-pelaksanaan-e-invois/garis-panduan/"
                                target="_blank"
                                rel="noreferrer"
                                className="group rounded-lg border p-4 transition-colors hover:border-primary/50 hover:bg-muted/40"
                            >
                                <div className="mb-1 flex items-center justify-between">
                                    <span className="flex items-center gap-1.5 text-sm font-semibold group-hover:text-primary">
                                        LHDN Guidelines Portal (Hub){' '}
                                        <ArrowUpRight className="size-3.5" />
                                    </span>
                                    <Badge variant="outline">
                                        Official Hub
                                    </Badge>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Official repository of all published IRBM
                                    e-Invoice guidelines, appendices, circulars,
                                    and sector-specific FAQs.
                                </p>
                            </a>

                            <a
                                href="https://sdk.myinvois.hasil.gov.my/"
                                target="_blank"
                                rel="noreferrer"
                                className="group rounded-lg border p-4 transition-colors hover:border-primary/50 hover:bg-muted/40"
                            >
                                <div className="mb-1 flex items-center justify-between">
                                    <span className="flex items-center gap-1.5 text-sm font-semibold group-hover:text-primary">
                                        LHDN MyInvois Developer SDK{' '}
                                        <ArrowUpRight className="size-3.5" />
                                    </span>
                                    <Badge variant="outline">
                                        Technical API
                                    </Badge>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Full API documentation, authentication
                                    OAuth2 specs, UBL 2.1 JSON/XML schemas,
                                    error code definitions, and Postman
                                    collections.
                                </p>
                            </a>

                            <a
                                href="https://sdk.myinvois.hasil.gov.my/signature/"
                                target="_blank"
                                rel="noreferrer"
                                className="group rounded-lg border p-4 transition-colors hover:border-primary/50 hover:bg-muted/40"
                            >
                                <div className="mb-1 flex items-center justify-between">
                                    <span className="flex items-center gap-1.5 text-sm font-semibold group-hover:text-primary">
                                        Digital Signature Specifications{' '}
                                        <ArrowUpRight className="size-3.5" />
                                    </span>
                                    <Badge variant="outline">
                                        Security / X.509
                                    </Badge>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Cryptographic standards for X.509
                                    certificates, Non-Repudiation, Document
                                    Signing EKU, and RSA-SHA256 digital
                                    signatures.
                                </p>
                            </a>

                            <a
                                href="https://preprod.myinvois.hasil.gov.my/"
                                target="_blank"
                                rel="noreferrer"
                                className="group rounded-lg border p-4 transition-colors hover:border-primary/50 hover:bg-muted/40"
                            >
                                <div className="mb-1 flex items-center justify-between">
                                    <span className="flex items-center gap-1.5 text-sm font-semibold group-hover:text-primary">
                                        LHDN Pre-production (Sandbox){' '}
                                        <ArrowUpRight className="size-3.5" />
                                    </span>
                                    <Badge variant="outline">Testing</Badge>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Sandbox environment for taxpayers and ERP
                                    developers to validate document submission,
                                    verify TINs, and test QR verification links.
                                </p>
                            </a>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

EInvoiceGuide.layout = {
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'E-invoice guide', href: eInvoiceGuide() },
    ],
};
