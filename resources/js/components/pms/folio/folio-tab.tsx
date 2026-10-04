import { Form } from '@inertiajs/react';
import {
    BadgePercent,
    Banknote,
    ChevronDown,
    CreditCard,
    Landmark,
    Lock,
    LockOpen,
    PanelRightClose,
    PanelRightOpen,
    Plus,
    Printer,
    Split,
    UserPlus,
} from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { ApplyDiscountDialog } from '@/components/pms/folio/apply-discount-dialog';
import { CloseFolioDialog } from '@/components/pms/folio/close-folio-dialog';
import { FolioRowsTable } from '@/components/pms/folio/folio-rows-table';
import { PostChargeDialog } from '@/components/pms/folio/post-charge-dialog';
import {
    PrintVoucherDialog,
    type VoucherOptions,
} from '@/components/pms/folio/print-voucher-dialog';
import { RecordPaymentDialog } from '@/components/pms/folio/record-payment-dialog';
import { SettleCityLedgerDialog } from '@/components/pms/folio/settle-city-ledger-dialog';
import { RefundPaymentDialog } from '@/components/pms/folio/refund-payment-dialog';
import { RemoveDiscountDialog } from '@/components/pms/folio/remove-discount-dialog';
import { ReverseLineDialog } from '@/components/pms/folio/reverse-line-dialog';
import { RoutingRulesSheet } from '@/components/pms/folio/routing-rules-sheet';
import { ThirdPartyFolioDialog } from '@/components/pms/folio/third-party-folio-dialog';
import { TransferLineDialog } from '@/components/pms/folio/transfer-line-dialog';
import { MoneyText } from '@/components/pms/money-text';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { KEEP_OPTIONS } from '@/components/pms/reservations/use-form-options';
import { reopen } from '@/routes/reservations/folios';
import { cn } from '@/lib/utils';

function Figure({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-1 rounded-lg bg-muted px-3 py-2">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="font-medium">{children}</dd>
        </div>
    );
}

/**
 * The Folio tab: the reservation's routing actions, the folio rail (hidden for one entry) and the open folio with its
 * Balance, Projected total and rows. Everything is worked out on the server, including which actions a row offers.
 */
export function FolioTab({
    reservationId,
    units,
    folio,
    chargeItems,
    chargeCodes,
    folioDiscounts,
    paymentOptions,
    canManageRouting,
    routingRules,
    voucher,
    summaryHidden,
    onToggleSummary,
    currency,
}: {
    reservationId: number;
    units: App.Data.Reservations.ReservationUnitOptionData[];
    folio: App.Data.Folio.FolioData | null;
    chargeItems?: App.Data.Reservations.ChargeItemOptionData[];
    chargeCodes?: App.Data.Reservations.ChargeCodeOptionData[];
    folioDiscounts?: App.Data.Folio.FolioDiscountOptionData[];
    paymentOptions?: App.Data.Payments.PaymentFormOptionsData;
    canManageRouting: boolean;
    routingRules?: App.Data.Folio.RoutingRulesData | null;
    voucher: VoucherOptions;
    summaryHidden: boolean;
    onToggleSummary: () => void;
    currency: App.Enums.Currency | null;
}) {
    const [creatingThirdParty, setCreatingThirdParty] = useState(false);
    const [routing, setRouting] = useState(false);
    const [posting, setPosting] = useState(false);
    const [paying, setPaying] = useState(false);
    const [refunding, setRefunding] =
        useState<App.Data.Folio.FolioRowData | null>(null);
    const [discounting, setDiscounting] = useState(false);
    const [closing, setClosing] = useState(false);
    const [settling, setSettling] = useState(false);
    const [voucherOpen, setVoucherOpen] = useState(false);
    const [voucherInitial, setVoucherInitial] = useState<string[]>([]);
    const [removing, setRemoving] =
        useState<App.Data.Folio.FolioRowData | null>(null);
    const [reversing, setReversing] =
        useState<App.Data.Folio.FolioRowData | null>(null);
    const [transferring, setTransferring] =
        useState<App.Data.Folio.FolioRowData | null>(null);

    const summaryLabel = summaryHidden ? 'Show summary' : 'Hide summary';

    const routingButtons = canManageRouting && (
        <DropdownMenu>
            <DropdownMenuTrigger render={<Button variant="outline" />}>
                <Split data-icon="inline-start" />
                Routing
                <ChevronDown data-icon="inline-end" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start">
                <DropdownMenuItem onClick={() => setRouting(true)}>
                    <Split />
                    Routing rules
                </DropdownMenuItem>
                <DropdownMenuItem onClick={() => setCreatingThirdParty(true)}>
                    <UserPlus />
                    Third-party folio
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );

    const routingOverlays = canManageRouting && (
        <>
            <ThirdPartyFolioDialog
                open={creatingThirdParty}
                onOpenChange={setCreatingThirdParty}
                reservationId={reservationId}
            />
            <RoutingRulesSheet
                open={routing}
                onOpenChange={setRouting}
                reservationId={reservationId}
                units={units}
                routing={routingRules}
                chargeCodes={chargeCodes}
            />
        </>
    );

    if (folio === null) {
        return (
            <div className="flex flex-col gap-4">
                <div className="flex flex-wrap gap-2">{routingButtons}</div>
                {routingOverlays}
                <EmptyState
                    icon={CreditCard}
                    title="No folio yet"
                    description="Charges appear here once a unit has nights or an add-on."
                />
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-4">
            {routingOverlays}
            <div className="flex flex-col gap-4">
                <div className="flex flex-col gap-4">
                    <div className="flex flex-wrap items-center justify-between gap-2 ps-3 pt-2">
                        <div className="flex flex-col gap-1">
                            <div className="flex items-center gap-2">
                                <h2 className="font-heading text-base font-medium">
                                    {folio.name}
                                </h2>
                                {folio.status && (
                                    <StatusBadge status={folio.status} />
                                )}
                            </div>
                            {folio.number && (
                                <p className="text-sm text-muted-foreground">
                                    Folio {folio.number}
                                    {folio.invoiceNumber &&
                                        ` · Invoice ${folio.invoiceNumber}`}
                                </p>
                            )}
                        </div>
                        <div className="flex items-center gap-2">
                            {folio.canReopen && (
                                <Form
                                    {...reopen.form({
                                        reservation: reservationId,
                                        folio: Number(folio.key),
                                    })}
                                    options={{
                                        preserveScroll: true,
                                        except: KEEP_OPTIONS,
                                    }}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            disabled={processing}
                                            focusableWhenDisabled
                                        >
                                            {processing ? (
                                                <Spinner data-icon="inline-start" />
                                            ) : (
                                                <LockOpen data-icon="inline-start" />
                                            )}
                                            Reopen folio
                                        </Button>
                                    )}
                                </Form>
                            )}
                            {folio.canPrintVoucher && (
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        setVoucherInitial([]);
                                        setVoucherOpen(true);
                                    }}
                                >
                                    <Printer data-icon="inline-start" />
                                    Print voucher
                                </Button>
                            )}
                            {folio.canClose && (
                                <Button
                                    variant="outline"
                                    onClick={() => setClosing(true)}
                                >
                                    <Lock data-icon="inline-start" />
                                    Close folio
                                </Button>
                            )}
                            <Tooltip>
                                <TooltipTrigger
                                    render={
                                        <Button
                                            variant="outline"
                                            size="icon"
                                            aria-label={summaryLabel}
                                            aria-pressed={summaryHidden}
                                            onClick={onToggleSummary}
                                        />
                                    }
                                >
                                    {summaryHidden ? (
                                        <PanelRightOpen />
                                    ) : (
                                        <PanelRightClose />
                                    )}
                                </TooltipTrigger>
                                <TooltipContent>{summaryLabel}</TooltipContent>
                            </Tooltip>
                        </div>
                    </div>
                    <dl className="grid gap-2 sm:grid-cols-3">
                        <Figure label="Balance">
                            {folio.balance === null ? (
                                <span className="text-muted-foreground">
                                    Not started
                                </span>
                            ) : (
                                <span
                                    className={cn(
                                        Number(folio.balance) > 0 &&
                                            'text-destructive',
                                    )}
                                >
                                    <MoneyText
                                        amount={folio.balance}
                                        currency={currency}
                                    />
                                </span>
                            )}
                        </Figure>
                        <Figure label="Projected total">
                            <MoneyText
                                amount={folio.projectedTotal}
                                currency={currency}
                            />
                        </Figure>
                        <Figure label="Posted lines">
                            <span className="tabular-nums">
                                {folio.lineCount}
                            </span>
                        </Figure>
                    </dl>
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex flex-wrap items-center gap-2">
                            {routingButtons}
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            {folio.canApplyDiscount && (
                                <Button
                                    variant="outline"
                                    onClick={() => setDiscounting(true)}
                                >
                                    <BadgePercent data-icon="inline-start" />
                                    Apply discount
                                </Button>
                            )}
                            {folio.canRecordPayment && (
                                <Button
                                    variant={
                                        folio.canPostCharge
                                            ? 'outline'
                                            : 'default'
                                    }
                                    onClick={() => setPaying(true)}
                                >
                                    <Banknote data-icon="inline-start" />
                                    Record payment
                                </Button>
                            )}
                            {folio.canSettleToCityLedger && (
                                <Button
                                    variant="outline"
                                    onClick={() => setSettling(true)}
                                >
                                    <Landmark data-icon="inline-start" />
                                    Settle to City ledger
                                </Button>
                            )}
                            {folio.canPostCharge && (
                                <Button onClick={() => setPosting(true)}>
                                    <Plus data-icon="inline-start" />
                                    Post charge
                                </Button>
                            )}
                        </div>
                    </div>
                    {folio.rows.length === 0 ? (
                        <EmptyState
                            icon={CreditCard}
                            title="Nothing on this folio"
                            description="Posted and scheduled charges appear here."
                        />
                    ) : (
                        <FolioRowsTable
                            reservationId={reservationId}
                            folioId={Number(folio.key)}
                            rows={folio.rows}
                            currency={currency}
                            onPrintVoucher={(row) => {
                                const at = folio.rows.indexOf(row);
                                const children: string[] = [];

                                if (row.level === 0) {
                                    for (
                                        let next = at + 1;
                                        folio.rows[next]?.level > 0 &&
                                        folio.rows[next].state === row.state;
                                        next++
                                    ) {
                                        if (folio.rows[next].printable) {
                                            children.push(folio.rows[next].key);
                                        }
                                    }
                                }

                                setVoucherInitial([row.key, ...children]);
                                setVoucherOpen(true);
                            }}
                            onAction={(row, action) => {
                                if (action === 'reverse') {
                                    setReversing(row);
                                } else if (action === 'transfer') {
                                    setTransferring(row);
                                } else if (action === 'refund') {
                                    setRefunding(row);
                                } else {
                                    setRemoving(row);
                                }
                            }}
                        />
                    )}
                </div>
                {folio.canPostCharge && (
                    <PostChargeDialog
                        open={posting}
                        onOpenChange={setPosting}
                        reservationId={reservationId}
                        folio={folio}
                        chargeItems={chargeItems}
                        currency={currency}
                    />
                )}
                {folio.canRecordPayment && (
                    <RecordPaymentDialog
                        open={paying}
                        onOpenChange={setPaying}
                        reservationId={reservationId}
                        folio={folio}
                        options={paymentOptions}
                        currency={currency}
                    />
                )}
                <RefundPaymentDialog
                    reservationId={reservationId}
                    folioId={Number(folio.key)}
                    row={refunding}
                    currency={currency}
                    onClose={() => setRefunding(null)}
                />
                {folio.canPrintVoucher && (
                    <PrintVoucherDialog
                        key={voucherInitial.join(',')}
                        open={voucherOpen}
                        onOpenChange={setVoucherOpen}
                        reservationId={reservationId}
                        folioId={
                            folio.kind === 'not_started'
                                ? null
                                : Number(folio.key)
                        }
                        unitId={folio.paymentUnitId}
                        rows={folio.rows.filter((row) => row.printable)}
                        options={voucher}
                        initial={voucherInitial}
                        currency={currency}
                    />
                )}
                {folio.canSettleToCityLedger && (
                    <SettleCityLedgerDialog
                        open={settling}
                        onOpenChange={setSettling}
                        reservationId={reservationId}
                        folio={folio}
                        currency={currency}
                    />
                )}
                {folio.canClose && (
                    <CloseFolioDialog
                        open={closing}
                        onOpenChange={setClosing}
                        reservationId={reservationId}
                        folio={folio}
                    />
                )}
                {folio.canApplyDiscount && (
                    <ApplyDiscountDialog
                        open={discounting}
                        onOpenChange={setDiscounting}
                        reservationId={reservationId}
                        folioId={Number(folio.key)}
                        discounts={folioDiscounts}
                        currency={currency}
                    />
                )}
                <RemoveDiscountDialog
                    reservationId={reservationId}
                    folioId={Number(folio.key)}
                    row={removing}
                    onClose={() => setRemoving(null)}
                />
                <ReverseLineDialog
                    reservationId={reservationId}
                    folioId={Number(folio.key)}
                    row={reversing}
                    onClose={() => setReversing(null)}
                />
                <TransferLineDialog
                    reservationId={reservationId}
                    folioId={Number(folio.key)}
                    row={transferring}
                    targets={folio.transferTargets}
                    onClose={() => setTransferring(null)}
                />
            </div>
        </div>
    );
}
