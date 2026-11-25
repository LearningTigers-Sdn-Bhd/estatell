import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeftRight,
    Banknote,
    BedDouble,
    Percent,
    Plus,
    Printer,
    Receipt,
    Send,
    Store,
    Trash2,
    UserRound,
} from 'lucide-react';
import { useState } from 'react';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import {
    PosDiscountDialog,
    PosManagerOverrideDialog,
    PosTransferRegisterDialog,
    PosVoidDialog,
} from '@/components/pms/pos/pos-action-dialogs';
import { PosChangeTenderDialog } from '@/components/pms/pos/pos-change-tender-dialog';
import { PosCheckActionsDialog } from '@/components/pms/pos/pos-check-actions';
import { PosCheckoutSheet } from '@/components/pms/pos/pos-checkout-sheet';
import { PosFloorBoard } from '@/components/pms/pos/pos-floor-board';
import type { Floor } from '@/components/pms/pos/pos-floor-board';
import { PosLineDialog } from '@/components/pms/pos/pos-line-dialog';
import type { LineChoice } from '@/components/pms/pos/pos-line-dialog';
import { PosMenuPanel } from '@/components/pms/pos/pos-menu-panel';
import { PosOrderTicket } from '@/components/pms/pos/pos-order-ticket';
import { PosPinPad } from '@/components/pms/pos/pos-pin-pad';
import { PosRefundDialog } from '@/components/pms/pos/pos-refund-dialog';
import { PosRoomLinkDialog } from '@/components/pms/pos/pos-room-link-dialog';
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
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { clock as clockRoute, index as posIndex, terminal } from '@/routes/pos';
import {
    changePayment,
    diningOption,
    fire,
    prebill as prebillRoute,
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
    businessDate: string | null;
    prebill: boolean;
    outlet: {
        id: number;
        name: string;
        printReceiptAfterPayment: boolean;
        roomChargeLimited: boolean;
        roomChargeLimit: string | null;
        payLater: boolean;
        roundingMode: string;
        roundingPolicy: string;
        safeClearingCodeId: number | null;
        requireReprintPin: boolean;
        requireRoomChargeSignature: boolean;
        requireMealRedemptionConfirmation: boolean;
    };
    floors: Floor[];
    floorVersion: string | null;
    clock: { required: boolean; clockedIn: boolean };
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
        tableId: number | null;
        openedAt: string;
    }[];
    voidReasons: PosVoidReason[];
    transactionCodes: EnumOption[];
    cashMovementKinds: EnumOption[];
    tenderTypes: EnumOption[];
    discountTypes: EnumOption[];
    staff: PosStaff[];
    actor: PosStaff;
    can: PosCan;
    locked: boolean;
};

export default function PosTerminal(props: Props) {
    const {
        currency,
        businessDate,
        prebill,
        outlet,
        floors,
        floorVersion,
        clock,
        register,
        registers,
        shift,
        menu,
        order,
        receipt,
        openOrders,
        voidReasons,
        transactionCodes,
        cashMovementKinds,
        tenderTypes,
        discountTypes,
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
    const [linkingRoom, setLinkingRoom] = useState(false);
    const [transferring, setTransferring] = useState(false);
    const [diningId, setDiningId] = useState<number>(
        menu.diningOptions[0]?.id ?? 0,
    );
    const [receiptOpen, setReceiptOpen] = useState(
        outlet.printReceiptAfterPayment,
    );
    const [prebillOpen, setPrebillOpen] = useState(prebill);
    const [checkActions, setCheckActions] = useState(false);
    const [swapping, setSwapping] = useState(false);
    const [course, setCourse] = useState(1);
    const [overridePrompt, setOverridePrompt] = useState<{
        title: string;
        description: string;
        url: string;
        payload: Record<string, unknown>;
    } | null>(null);

    const trading = shift !== null && shift.status === 'open';
    const hasDraft =
        order?.lines.some((row) => row.isDraft && !row.isVoided) ?? false;
    const hasSent =
        order?.lines.some((row) => !row.isDraft && !row.isVoided) ?? false;
    const hasLines = order?.lines.some((row) => !row.isVoided) ?? false;
    const selectedDining = menu.diningOptions.find(
        (option) => option.id === (order?.diningOptionId ?? diningId),
    );
    const showFloorBoard =
        outlet.payLater &&
        order === null &&
        (selectedDining === undefined || selectedDining.kind === 'dine_in');

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
                weight: choice.weight || undefined,
                combo_selections: choice.combo_selections,
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
        const mealEligible =
            order?.roomLabel != null && item.mealPeriodId !== null;
        const simple =
            !mealEligible &&
            item.variants.length === 1 &&
            item.modifierGroups.length === 0 &&
            item.variants[0].pricingType === 'fixed' &&
            !item.soldByWeight &&
            !item.isCombo;

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

    function handlePrebill() {
        if (order === null) {
            return;
        }

        const needsPin =
            order.prebillPrintCount >= 1 &&
            outlet.requireReprintPin &&
            !can.void;

        if (needsPin) {
            setOverridePrompt({
                title: 'Approve pre-bill reprint',
                description:
                    'A pre-bill was already printed for this check. A manager must approve reprinting it.',
                url: prebillRoute.url({ order: order.id }),
                payload: {},
            });

            return;
        }

        router.post(
            prebillRoute.url({ order: order.id }),
            {},
            {
                preserveScroll: true,
                onSuccess: () => setPrebillOpen(true),
            },
        );
    }

    function handleDiningOption(optionId: number) {
        if (order === null) {
            setDiningId(optionId);

            return;
        }

        if (order.prebillPrintCount > 0 && !can.void) {
            setOverridePrompt({
                title: 'Approve dining option change',
                description:
                    'Changing the dining option after a pre-bill needs manager approval.',
                url: diningOption.url({ order: order.id }),
                payload: { pos_dining_option_id: optionId },
            });

            return;
        }

        router.post(
            diningOption.url({ order: order.id }),
            { pos_dining_option_id: optionId },
            { preserveScroll: true },
        );
    }

    const swapTenderOptions = Array.from(
        new Set(menu.paymentMethods.map((method) => method.tenderType)),
    )
        .filter((type) => type !== 'room_charge')
        .map((type) => ({
            value: type,
            label:
                tenderTypes.find((option) => option.value === type)?.label ??
                type,
        }));

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
                    {businessDate && (
                        <Badge variant="outline" className="tabular-nums">
                            {businessDate}
                        </Badge>
                    )}
                    {!trading && (
                        <Badge
                            variant="outline"
                            className="border-warning text-warning"
                        >
                            {shift.statusLabel}
                        </Badge>
                    )}
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {clock.required && (
                        <Button
                            variant={clock.clockedIn ? 'secondary' : 'outline'}
                            className="min-h-11"
                            onClick={() =>
                                router.post(
                                    clockRoute.url(),
                                    {
                                        outlet_id: outlet.id,
                                        action: clock.clockedIn ? 'out' : 'in',
                                    },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {clock.clockedIn ? 'Clock out' : 'Clock in'}
                        </Button>
                    )}
                    <Button
                        variant="outline"
                        className="min-h-11"
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
                                className="min-h-11"
                                onClick={() => setMoving(true)}
                            >
                                <Banknote data-icon="inline-start" />
                                Cash
                            </Button>
                            <Button
                                variant="outline"
                                className="min-h-11"
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
                                className="min-h-11"
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
                                onClick={() => handleDiningOption(option.id)}
                            >
                                {option.name}
                            </Button>
                        ))}
                    </div>
                    {showFloorBoard ? (
                        <PosFloorBoard
                            floors={floors}
                            floorVersion={floorVersion}
                            outletId={outlet.id}
                            registerId={register.id}
                            diningOptionId={diningId}
                            currency={currency}
                            trading={trading}
                            onNewCounterOrder={() =>
                                router.post(
                                    storeOrder.url(),
                                    {
                                        register_id: register.id,
                                        pos_dining_option_id: diningId,
                                    },
                                    { preserveScroll: true },
                                )
                            }
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
                                            className: 'min-h-11',
                                        })}
                                    >
                                        Tables
                                    </Link>
                                    {[1, 2, 3, 4].map((number) => (
                                        <Button
                                            key={number}
                                            className="min-h-11"
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
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex items-center gap-2">
                            <h2 className="font-semibold">
                                {order
                                    ? `Order ${order.orderNumber ?? `#${order.id}`}`
                                    : 'New order'}
                            </h2>
                            {order?.tableCode && (
                                <Badge variant="secondary">
                                    Table {order.tableCode}
                                </Badge>
                            )}
                        </div>
                        <div className="flex flex-wrap items-center gap-1">
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
                                        className: 'min-h-11',
                                    })}
                                >
                                    {open.tableCode !== null
                                        ? `${open.tableCode} · `
                                        : ''}
                                    {open.orderNumber ?? `#${open.id}`}
                                </Link>
                            ))}
                            {order !== null && (
                                <Link
                                    href={terminal.url(
                                        { outlet: outlet.id },
                                        { query: { register: register.id } },
                                    )}
                                    className={buttonVariants({
                                        variant: 'outline',
                                        className: 'min-h-11',
                                    })}
                                >
                                    <Plus data-icon="inline-start" />
                                    New order
                                </Link>
                            )}
                        </div>
                    </div>

                    {order && (
                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                type="button"
                                variant={
                                    order.roomLabel ? 'secondary' : 'outline'
                                }
                                className="min-h-11"
                                onClick={() => setLinkingRoom(true)}
                            >
                                <BedDouble data-icon="inline-start" />
                                {order.roomLabel
                                    ? `Room: ${order.roomLabel}`
                                    : 'Link room / guest'}
                            </Button>
                            {can.shift && registers.length > 1 && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="min-h-11"
                                    onClick={() => setTransferring(true)}
                                >
                                    <ArrowLeftRight data-icon="inline-start" />
                                    Transfer register
                                </Button>
                            )}
                        </div>
                    )}

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
                                className="min-h-11"
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
                                    className="min-h-11"
                                    onClick={() => setCheckActions(true)}
                                >
                                    Check actions
                                </Button>
                            )}
                            <Button
                                variant="outline"
                                className="min-h-11"
                                disabled={!hasLines}
                                onClick={handlePrebill}
                            >
                                <Receipt data-icon="inline-start" />
                                Pre-bill
                            </Button>
                            <Button
                                variant="outline"
                                className="min-h-11"
                                disabled={!hasLines}
                                onClick={() => setDiscounting(true)}
                            >
                                <Percent data-icon="inline-start" />
                                Discount
                            </Button>
                            <Button
                                variant="outline"
                                className="min-h-11"
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

            {order !== null && (
                <PosRoomLinkDialog
                    open={linkingRoom}
                    onClose={() => setLinkingRoom(false)}
                    orderId={order.id}
                    currentUnitId={order.reservationUnitId}
                    currentLabel={order.roomLabel}
                />
            )}

            {order !== null && can.shift && registers.length > 1 && (
                <PosTransferRegisterDialog
                    open={transferring}
                    onClose={() => setTransferring(false)}
                    orderId={order.id}
                    currentRegisterId={register.id}
                    registers={registers}
                />
            )}

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
                    vacantTables={floors.flatMap((floor) =>
                        floor.tables
                            .filter(
                                (table) =>
                                    !table.occupied &&
                                    table.id !== order.tableId,
                            )
                            .map((table) => ({
                                value: String(table.id),
                                label: `${floor.name} · ${table.code}`,
                            })),
                    )}
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
                            tenderTypes={tenderTypes}
                            staff={staff}
                            hasMealCover={order.lines.some(
                                (row) => row.isEntitlementCovered,
                            )}
                            roundingMode={outlet.roundingMode}
                            roundingPolicy={outlet.roundingPolicy}
                            roomChargeLimit={outlet.roomChargeLimit}
                            requireRoomChargeSignature={
                                outlet.requireRoomChargeSignature
                            }
                            requireMealRedemptionConfirmation={
                                outlet.requireMealRedemptionConfirmation
                            }
                            otherBills={openOrders
                                .filter(
                                    (open) =>
                                        open.id !== order.id &&
                                        open.tableId !== null &&
                                        open.tableId === order.tableId,
                                )
                                .map((open) => ({
                                    id: open.id,
                                    label:
                                        open.orderNumber ?? `Check #${open.id}`,
                                    openedAt: open.openedAt,
                                    total: open.grandTotal,
                                }))}
                        />
                    )}
                    <PosVoidDialog
                        open={voiding !== null}
                        onClose={() => setVoiding(null)}
                        staff={staff}
                        orderId={order.id}
                        line={voiding?.line ?? null}
                        reasons={voidReasons}
                        requiresApproval={
                            !can.void || order.prebillPrintCount > 0
                        }
                    />
                    <PosDiscountDialog
                        open={discounting}
                        onClose={() => setDiscounting(false)}
                        staff={staff}
                        orderId={order.id}
                        lines={order.lines}
                        discountTypes={discountTypes}
                        requiresApproval={
                            !can.discount || order.prebillPrintCount > 0
                        }
                    />
                    <Dialog
                        open={prebillOpen}
                        onOpenChange={(open) => {
                            setPrebillOpen(open);
                            if (!open && prebill) {
                                router.get(
                                    terminal.url(
                                        { outlet: outlet.id },
                                        {
                                            query: {
                                                register: register.id,
                                                order: order.id,
                                            },
                                        },
                                    ),
                                    {},
                                    { preserveState: true, replace: true },
                                );
                            }
                        }}
                    >
                        <DialogContent className="sm:max-w-md">
                            <DialogHeader>
                                <DialogTitle>Pre-bill</DialogTitle>
                            </DialogHeader>
                            <PosReceipt
                                order={order}
                                currency={currency}
                                prebill
                            />
                            <div className="flex justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="min-h-11"
                                    onClick={() => window.print()}
                                >
                                    <Printer data-icon="inline-start" />
                                    Print pre-bill
                                </Button>
                                <Button
                                    type="button"
                                    className="min-h-11"
                                    onClick={() => setPrebillOpen(false)}
                                >
                                    Done
                                </Button>
                            </div>
                        </DialogContent>
                    </Dialog>
                </>
            )}

            {overridePrompt !== null && (
                <PosManagerOverrideDialog
                    open
                    onClose={() => setOverridePrompt(null)}
                    title={overridePrompt.title}
                    description={overridePrompt.description}
                    url={overridePrompt.url}
                    payload={overridePrompt.payload}
                    staff={staff}
                />
            )}

            {shift && (
                <>
                    <PosCashMovementDialog
                        open={moving}
                        onClose={() => setMoving(false)}
                        shiftId={shift.id}
                        staff={staff}
                        codes={transactionCodes}
                        kinds={cashMovementKinds}
                        safeClearingCodeId={outlet.safeClearingCodeId}
                        canVoid={can.void}
                    />
                    <PosCloseShiftDialog
                        open={closing}
                        onClose={() => setClosing(false)}
                        shift={shift}
                        outletId={outlet.id}
                        registerId={register.id}
                        staff={staff}
                    />
                </>
            )}

            {receipt && (
                <FormOverlay
                    variant="dialog"
                    open={receiptOpen && !refunding && !swapping}
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
                    {receipt.orderType === 'sale' && (
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                className="min-h-11"
                                onClick={() => setRefunding(true)}
                            >
                                Refund items
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                className="min-h-11"
                                onClick={() => setSwapping(true)}
                            >
                                Change tender
                            </Button>
                        </div>
                    )}
                </FormOverlay>
            )}
            {receipt && (
                <PosChangeTenderDialog
                    open={swapping}
                    onClose={() => setSwapping(false)}
                    order={receipt}
                    tenders={swapTenderOptions}
                    methods={menu.paymentMethods}
                    url={changePayment.url({ order: receipt.id })}
                    staff={staff}
                    requiresApproval={!can.refund}
                />
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

            <Dialog open={switching} onOpenChange={setSwitching}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Switch user</DialogTitle>
                    </DialogHeader>
                    <PosPinPad
                        staff={staff}
                        onDone={() => setSwitching(false)}
                    />
                </DialogContent>
            </Dialog>
        </>
    );
}
