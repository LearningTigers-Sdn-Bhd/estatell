import { Head, Link, router } from '@inertiajs/react';
import {
    Banknote,
    Percent,
    Printer,
    Receipt,
    Send,
    Store,
    Trash2,
    UserRound,
} from 'lucide-react';
import { useState } from 'react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { PosCheckActionsDialog } from '@/components/pms/pos/pos-check-actions';
import { PosCheckoutSheet } from '@/components/pms/pos/pos-checkout-sheet';
import { PosFloorBoard } from '@/components/pms/pos/pos-floor-board';
import type { Floor } from '@/components/pms/pos/pos-floor-board';
import {
    PosDiscountDialog,
    PosVoidDialog,
} from '@/components/pms/pos/pos-action-dialogs';
import { PosLineDialog } from '@/components/pms/pos/pos-line-dialog';
import type { LineChoice } from '@/components/pms/pos/pos-line-dialog';
import { PosMenuPanel } from '@/components/pms/pos/pos-menu-panel';
import { PosOrderTicket } from '@/components/pms/pos/pos-order-ticket';
import { PosPinPad } from '@/components/pms/pos/pos-pin-pad';
import { PosRefundDialog } from '@/components/pms/pos/pos-refund-dialog';
import {
    PosCashMovementDialog,
    PosCloseShiftDialog,
} from '@/components/pms/pos/pos-shift-dialogs';
import type {
    PosCan,
    PosItem,
    PosMenu,
    PosStaff,
    PosVoidReason,
} from '@/components/pms/pos/pos-types';
import { PosReceipt } from '@/components/pms/pos-receipt';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import { index as posIndex, terminal } from '@/routes/pos';
import {
    diningOption,
    fire,
    prebill,
    refund,
    store as storeOrder,
} from '@/routes/pos/orders';
import {
    store as storeLine,
    update as updateLine,
    voidMethod as voidLineRoute,
} from '@/routes/pos/orders/lines';

type Order = App.Data.Pos.PosOrderData;
type Line = App.Data.Pos.PosOrderLineData;
type Currency = App.Data.Property.CurrencyData | null;

type Props = {
    currency: Currency;
    outlet: {
        id: number;
        name: string;
        printReceiptAfterPayment: boolean;
        roomChargeLimited: boolean;
        payLater: boolean;
    };
    floors: Floor[];
    register: { id: number; name: string } | null;
    registers: { id: number; name: string }[];
    shift: App.Data.Pos.PosShiftData | null;
    menu: PosMenu;
    order: Order | null;
    receipt: Order | null;
    openOrders: {
        id: number;
        orderNumber: string | null;
        grandTotal: string;
        tableCode: string | null;
    }[];
    voidReasons: PosVoidReason[];
    transactionCodes: { value: string; label: string }[];
    staff: PosStaff[];
    actor: PosStaff;
    can: PosCan;
    locked: boolean;
};

export default function PosTerminal(props: Props) {
    const {
        currency,
        outlet,
        floors,
        register,
        shift,
        menu,
        order,
        receipt,
        openOrders,
        voidReasons,
        transactionCodes,
        staff,
        actor,
        can,
        locked,
    } = props;
    const [line, setLine] = useState<PosItem | null>(null);
    const [paying, setPaying] = useState(false);
    const [voiding, setVoiding] = useState<{ line: Line | null } | null>(null);
    const [discounting, setDiscounting] = useState(false);
    const [refunding, setRefunding] = useState(false);
    const [moving, setMoving] = useState(false);
    const [closing, setClosing] = useState(false);
    const [switching, setSwitching] = useState(false);
    const [diningId, setDiningId] = useState<number>(
        menu.diningOptions[0]?.id ?? 0,
    );
    const [receiptOpen, setReceiptOpen] = useState(true);
    const [checkActions, setCheckActions] = useState(false);
    const [course, setCourse] = useState(1);

    const trading = shift !== null && shift.status === 'open';
    const hasDraft =
        order?.lines.some((row) => row.isDraft && !row.isVoided) ?? false;
    const hasSent =
        order?.lines.some((row) => !row.isDraft && !row.isVoided) ?? false;
    const hasLines = order?.lines.some((row) => !row.isVoided) ?? false;

    function addLine(orderId: number, choice: LineChoice) {
        router.post(
            storeLine.url({ order: orderId }),
            {
                variant_id: choice.variant_id,
                quantity: choice.quantity,
                modifier_option_ids: choice.modifier_option_ids,
                note: choice.note || undefined,
                unit_price: choice.unit_price || undefined,
                cover_with_entitlement: choice.cover_with_entitlement,
                course_number: course > 1 ? course : undefined,
            },
            { preserveScroll: true },
        );
    }

    function pick(choice: LineChoice) {
        setLine(null);

        if (order !== null) {
            addLine(order.id, choice);

            return;
        }

        // No order yet: open one, then add the line to it.
        router.post(
            storeOrder.url(),
            { register_id: register?.id, pos_dining_option_id: diningId },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    const id = Number(
                        new URL(
                            page.url,
                            window.location.origin,
                        ).searchParams.get('order'),
                    );

                    if (id > 0) {
                        addLine(id, choice);
                    }
                },
            },
        );
    }

    function tap(item: PosItem) {
        const simple =
            item.variants.length === 1 &&
            item.modifierGroups.length === 0 &&
            item.variants[0].pricingType === 'fixed';

        if (simple) {
            pick({
                variant_id: item.variants[0].id,
                quantity: 1,
                modifier_option_ids: [],
                note: '',
                unit_price: '',
                cover_with_entitlement: false,
            });

            return;
        }

        setLine(item);
    }

    if (shift === null || register === null) {
        return (
            <>
                <Head title={outlet.name} />
                <div className="mx-auto flex w-full max-w-lg flex-1 flex-col items-center justify-center gap-4 p-6 text-center">
                    <Store
                        className="size-10 text-muted-foreground"
                        aria-hidden
                    />
                    <p className="text-lg font-medium">
                        This register has no open shift.
                    </p>
                    <Link href={posIndex.url()} className={buttonVariants()}>
                        Back to the registers
                    </Link>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title={`${outlet.name} · ${register.name}`} />
            <header className="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-2">
                <div className="flex flex-wrap items-center gap-3">
                    <Link
                        href={posIndex.url()}
                        className="font-semibold hover:underline"
                    >
                        {outlet.name}
                    </Link>
                    <span className="text-muted-foreground">
                        {register.name}
                    </span>
                    <Badge variant="secondary">Shift {shift.shiftNumber}</Badge>
                    {!trading && (
                        <Badge variant="outline">{shift.statusLabel}</Badge>
                    )}
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setSwitching(true)}
                        disabled={!locked}
                    >
                        <UserRound data-icon="inline-start" />
                        {actor.name}
                    </Button>
                    {can.shift && (
                        <>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setMoving(true)}
                            >
                                <Banknote data-icon="inline-start" />
                                Cash
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setClosing(true)}
                            >
                                Close shift
                            </Button>
                        </>
                    )}
                </div>
            </header>

            <main className="grid min-h-0 flex-1 gap-4 p-4 lg:grid-cols-[minmax(0,13fr)_minmax(0,7fr)]">
                <section className="flex min-h-0 flex-col gap-3">
                    <div
                        className="flex flex-wrap gap-2"
                        role="group"
                        aria-label="Dining option"
                    >
                        {menu.diningOptions.map((option) => (
                            <Button
                                key={option.id}
                                type="button"
                                variant={
                                    (order?.diningOptionId ?? diningId) ===
                                    option.id
                                        ? 'default'
                                        : 'outline'
                                }
                                disabled={
                                    order !== null &&
                                    (!hasLines
                                        ? false
                                        : order.status !== 'open')
                                }
                                onClick={() => {
                                    if (order === null) {
                                        setDiningId(option.id);
                                    } else {
                                        router.post(
                                            diningOption.url({
                                                order: order.id,
                                            }),
                                            { pos_dining_option_id: option.id },
                                            { preserveScroll: true },
                                        );
                                    }
                                }}
                            >
                                {option.name}
                            </Button>
                        ))}
                    </div>
                    {outlet.payLater && order === null ? (
                        <PosFloorBoard
                            floors={floors}
                            outletId={outlet.id}
                            registerId={register.id}
                            diningOptionId={diningId}
                            currency={currency}
                            trading={trading}
                        />
                    ) : (
                        <>
                            {outlet.payLater && (
                                <div
                                    className="flex flex-wrap items-center gap-2"
                                    role="group"
                                    aria-label="Course"
                                >
                                    <Link
                                        href={terminal.url(
                                            { outlet: outlet.id },
                                            {
                                                query: {
                                                    register: register.id,
                                                },
                                            },
                                        )}
                                        className={buttonVariants({
                                            variant: 'outline',
                                            size: 'sm',
                                        })}
                                    >
                                        Tables
                                    </Link>
                                    {[1, 2, 3, 4].map((number) => (
                                        <Button
                                            key={number}
                                            size="sm"
                                            variant={
                                                course === number
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            onClick={() => setCourse(number)}
                                        >
                                            Course {number}
                                        </Button>
                                    ))}
                                </div>
                            )}
                            <PosMenuPanel
                                categories={menu.categories}
                                currency={currency}
                                disabled={!trading}
                                onPick={tap}
                            />
                        </>
                    )}
                </section>

                <aside className="flex min-h-0 flex-col gap-3 rounded-xl border p-3">
                    <div className="flex items-center justify-between gap-2">
                        <h2 className="font-semibold">
                            {order
                                ? `Order ${order.orderNumber ?? ''}`
                                : 'New order'}
                        </h2>
                        {openOrders.length > 0 && (
                            <div className="flex flex-wrap gap-1">
                                {openOrders.map((open) => (
                                    <Link
                                        key={open.id}
                                        href={terminal.url(
                                            { outlet: outlet.id },
                                            {
                                                query: {
                                                    register: register.id,
                                                    order: open.id,
                                                },
                                            },
                                        )}
                                        className={buttonVariants({
                                            variant:
                                                order?.id === open.id
                                                    ? 'secondary'
                                                    : 'ghost',
                                            size: 'sm',
                                        })}
                                    >
                                        {open.tableCode !== null
                                            ? `${open.tableCode} · `
                                            : ''}
                                        {open.orderNumber ?? `#${open.id}`}
                                    </Link>
                                ))}
                            </div>
                        )}
                    </div>
                    {order ? (
                        <PosOrderTicket
                            order={order}
                            currency={currency}
                            onQuantity={(row, quantity) =>
                                router.patch(
                                    updateLine.url({
                                        order: order.id,
                                        line: row.id,
                                    }),
                                    { quantity },
                                    { preserveScroll: true },
                                )
                            }
                            onRemove={(row) => {
                                if (
                                    row.isDraft &&
                                    order.prebillPrintCount === 0
                                ) {
                                    router.post(
                                        voidLineRoute.url({
                                            order: order.id,
                                            line: row.id,
                                        }),
                                        {},
                                        { preserveScroll: true },
                                    );
                                } else {
                                    setVoiding({ line: row });
                                }
                            }}
                        />
                    ) : (
                        <p className="flex-1 text-sm text-muted-foreground">
                            Tap an item to open an order.
                        </p>
                    )}
                    {order && (
                        <div className="grid grid-cols-2 gap-2">
                            <Button
                                variant="outline"
                                disabled={!hasDraft}
                                onClick={() =>
                                    router.post(
                                        fire.url({ order: order.id }),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Send data-icon="inline-start" />
                                Send
                            </Button>
                            {outlet.payLater && order.status === 'open' && (
                                <Button
                                    variant="outline"
                                    onClick={() => setCheckActions(true)}
                                >
                                    Check actions
                                </Button>
                            )}
                            <Button
                                variant="outline"
                                disabled={!hasLines}
                                onClick={() =>
                                    router.post(
                                        prebill.url({ order: order.id }),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Receipt data-icon="inline-start" />
                                Pre-bill
                            </Button>
                            <Button
                                variant="outline"
                                disabled={!hasLines || !can.discount}
                                onClick={() => setDiscounting(true)}
                            >
                                <Percent data-icon="inline-start" />
                                Discount
                            </Button>
                            <Button
                                variant="outline"
                                disabled={!can.void}
                                onClick={() => setVoiding({ line: null })}
                            >
                                <Trash2 data-icon="inline-start" />
                                Void order
                            </Button>
                            <Button
                                className="col-span-2 h-14 text-base"
                                disabled={!hasLines || !trading}
                                onClick={() => setPaying(true)}
                            >
                                Pay
                            </Button>
                        </div>
                    )}
                    {order && hasSent && order.prebillPrintCount > 0 && (
                        <p className="text-xs text-muted-foreground">
                            Pre-bill printed {order.prebillPrintCount}×. Changes
                            now need a manager.
                        </p>
                    )}
                </aside>
            </main>

            <PosLineDialog
                item={line}
                currency={currency}
                canCover={order?.roomLabel != null}
                onClose={() => setLine(null)}
                onAdd={pick}
            />

            {order !== null && outlet.payLater && (
                <PosCheckActionsDialog
                    open={checkActions}
                    onClose={() => setCheckActions(false)}
                    order={order}
                    others={openOrders
                        .filter((open) => open.id !== order.id)
                        .map((open) => ({
                            id: open.id,
                            label: `${open.tableCode ?? 'No table'} · ${open.orderNumber ?? `#${open.id}`}`,
                        }))}
                />
            )}

            {order && (
                <>
                    {paying && (
                        <PosCheckoutSheet
                            key={order.id + order.grandTotal}
                            open
                            onOpenChange={setPaying}
                            order={order}
                            currency={currency}
                            methods={menu.paymentMethods}
                            staff={staff}
                            hasMealCover={order.lines.some(
                                (row) => row.isEntitlementCovered,
                            )}
                        />
                    )}
                    <PosVoidDialog
                        open={voiding !== null}
                        onClose={() => setVoiding(null)}
                        staff={staff}
                        orderId={order.id}
                        line={voiding?.line ?? null}
                        reasons={voidReasons}
                    />
                    <PosDiscountDialog
                        open={discounting}
                        onClose={() => setDiscounting(false)}
                        staff={staff}
                        orderId={order.id}
                    />
                </>
            )}

            {shift && (
                <>
                    <PosCashMovementDialog
                        open={moving}
                        onClose={() => setMoving(false)}
                        shiftId={shift.id}
                        staff={staff}
                        codes={transactionCodes}
                    />
                    <PosCloseShiftDialog
                        open={closing}
                        onClose={() => setClosing(false)}
                        shift={shift}
                        staff={staff}
                    />
                </>
            )}

            {receipt && (
                <FormOverlay
                    variant="dialog"
                    open={receiptOpen}
                    onOpenChange={(open) => {
                        setReceiptOpen(open);

                        if (!open) {
                            router.get(
                                terminal.url(
                                    { outlet: outlet.id },
                                    { query: { register: register.id } },
                                ),
                                {},
                                { preserveState: true },
                            );
                        }
                    }}
                    title={receipt.orderType === 'refund' ? 'Refund' : 'Paid'}
                    errors={{}}
                    processing={false}
                    submitLabel="New order"
                    onSubmit={() =>
                        router.get(
                            terminal.url(
                                { outlet: outlet.id },
                                { query: { register: register.id } },
                            ),
                        )
                    }
                    footerStart={
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => window.print()}
                        >
                            <Printer data-icon="inline-start" />
                            Print receipt
                        </Button>
                    }
                >
                    <PosReceipt order={receipt} currency={currency} />
                    {can.refund && receipt.orderType === 'sale' && (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setRefunding(true)}
                        >
                            Refund items
                        </Button>
                    )}
                </FormOverlay>
            )}
            {receipt && (
                <PosRefundDialog
                    open={refunding}
                    onClose={() => setRefunding(false)}
                    order={receipt}
                    url={refund.url({ order: receipt.id })}
                    staff={staff}
                />
            )}

            <FormOverlay
                variant="dialog"
                open={switching}
                onOpenChange={setSwitching}
                title="Switch user"
                errors={{}}
                processing={false}
                submitLabel="Close"
                onSubmit={() => setSwitching(false)}
            >
                <PosPinPad staff={staff} onDone={() => setSwitching(false)} />
            </FormOverlay>
        </>
    );
}
