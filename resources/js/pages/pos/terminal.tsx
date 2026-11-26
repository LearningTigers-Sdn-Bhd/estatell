import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowLeftRight,
    Banknote,
    BedDouble,
    ChevronRight,
    Clock,
    Monitor,
    Percent,
    Plus,
    Printer,
    Receipt,
    Send,
    SlidersHorizontal,
    Store,
    Trash2,
    UserRound,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { EnumOption } from '@/components/pms/enum-select';
import { EmptyState } from '@/components/pms/empty-state';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyText } from '@/components/pms/money-text';
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
import { PosNavbar } from '@/components/pms/pos/pos-navbar';
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
import { StatusBadge } from '@/components/pms/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { clock as clockRoute, index as posIndex, terminal } from '@/routes/pos';
import { show as cfdShow, state as cfdState } from '@/routes/pos/cfd';
import {
    changePayment,
    diningOption,
    fire,
    prebill as prebillRoute,
    refund,
    store as storeOrder,
    voidMethod as voidEmptyOrder,
} from '@/routes/pos/orders';
import {
    store as storeLine,
    update as updateLine,
    voidMethod as voidLineRoute,
} from '@/routes/pos/orders/lines';
import { unlessRefused } from '@/components/pms/pos/pos-unless-refused';

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
        source?: string;
        draftCount?: number;
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
    const [switchKey, setSwitchKey] = useState(0);
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
    const [mobilePane, setMobilePane] = useState<'menu' | 'order'>('menu');
    const [busyAction, setBusyAction] = useState<string | null>(null);
    const [overridePrompt, setOverridePrompt] = useState<{
        title: string;
        description: string;
        url: string;
        payload: Record<string, unknown>;
    } | null>(null);

    const trading = shift !== null && shift.status === 'open';
    const cfdChannel = useRef<BroadcastChannel | null>(null);

    // Notify same-device customer display instantly; separate tablets pick up state on poll.
    useEffect(() => {
        if (typeof BroadcastChannel === 'undefined' || register === null) {
            return;
        }

        const channel = new BroadcastChannel(`pos-cfd-${register.id}`);
        cfdChannel.current = channel;

        return () => {
            channel.close();
            cfdChannel.current = null;
        };
    }, [register]);

    function syncCustomerDisplay(
        orderId: number | null,
        checkoutActive: boolean,
    ) {
        if (register === null) {
            return;
        }

        const token = document.cookie
            .split('; ')
            .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
            ?.slice('XSRF-TOKEN='.length);

        void fetch(cfdState.url({ register: register.id }), {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}),
            },
            body: JSON.stringify({
                order_id: orderId,
                checkout_active: checkoutActive,
            }),
        })
            .then(() => {
                cfdChannel.current?.postMessage({ orderId, checkoutActive });
            })
            .catch(() => undefined);
    }

    const openingOrder = useRef<LineChoice[] | null>(null);
    const menuPane = useRef<HTMLElement>(null);
    const openOrderId = order?.id;

    // Opening a check removes the button that had focus. Move focus to the menu, not back to the top of the page.
    useEffect(() => {
        if (openOrderId && document.activeElement === document.body) {
            menuPane.current?.focus({ preventScroll: true });
        }
    }, [openOrderId]);
    const activeOrderId = order?.id ?? null;
    const cfdSignature = `${order?.id ?? 'none'}|${order?.lines.length ?? 0}|${order?.grandTotal ?? ''}|${order?.discountTotal ?? ''}|${order?.diningOptionId ?? ''}|${receipt?.id ?? ''}`;

    useEffect(() => {
        if (activeOrderId !== null) {
            syncCustomerDisplay(activeOrderId, paying);
        }
    }, [activeOrderId, paying]);

    useEffect(() => {
        cfdChannel.current?.postMessage({ signature: cfdSignature });
    }, [cfdSignature]);

    const draftCount =
        order?.lines.filter((row) => row.isDraft && !row.isVoided).length ?? 0;
    const hasDraft = draftCount > 0;
    const hasSent =
        order?.lines.some((row) => !row.isDraft && !row.isVoided) ?? false;
    const liveItemCount =
        order?.lines
            .filter(
                (row) =>
                    !row.isVoided && !row.isPackagingFee && !row.isComboPart,
            )
            .reduce(
                (sum, row) => sum + (row.isWeighed ? 1 : Number(row.quantity)),
                0,
            ) ?? 0;
    const hasLines = order?.lines.some((row) => !row.isVoided) ?? false;
    const selectedDining = menu.diningOptions.find(
        (option) => option.id === (order?.diningOptionId ?? diningId),
    );
    const showFloorBoard =
        outlet.payLater &&
        order === null &&
        (selectedDining === undefined || selectedDining.kind === 'dine_in');

    function addLine(
        orderId: number,
        choice: LineChoice,
        onFinish?: () => void,
    ) {
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
            { preserveScroll: true, onFinish },
        );
    }

    /** Add queued lines sequentially so Inertia visits do not cancel each other. */
    function addLines(orderId: number, queue: LineChoice[]) {
        const [first, ...rest] = queue;

        if (first === undefined) {
            return;
        }

        addLine(orderId, first, () => addLines(orderId, rest));
    }

    function pick(choice: LineChoice) {
        setLine(null);

        if (order !== null) {
            addLine(order.id, choice);

            return;
        }

        if (openingOrder.current !== null) {
            openingOrder.current.push(choice);

            return;
        }

        openingOrder.current = [choice];
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
                        addLines(id, openingOrder.current ?? []);
                    }
                },
                onFinish: () => {
                    openingOrder.current = null;
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
                onStart: () => setBusyAction('prebill'),
                onFinish: () => setBusyAction(null),
                onSuccess: unlessRefused(() => setPrebillOpen(true)),
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

    const pendingQrOrders = openOrders.filter(
        (entry) =>
            (entry.source === 'table_qr' || entry.source === 'room_qr') &&
            (entry.draftCount ?? 0) > 0,
    );

    if (shift === null || register === null) {
        return (
            <>
                <Head title={outlet.name} />
                <PosNavbar
                    title={outlet.name}
                    titleAs="h1"
                    back={{ href: posIndex.url(), label: 'Registers' }}
                    showUser={!locked}
                />
                <main className="flex flex-1 flex-col items-center justify-center bg-muted/40 p-6">
                    <Card className="w-full max-w-md">
                        <CardContent className="py-10">
                            <EmptyState
                                icon={Store}
                                title="This register has no open shift"
                                description="Open a shift on the register launcher before taking orders."
                                action={
                                    <Link
                                        href={posIndex.url()}
                                        className={`${buttonVariants()} min-h-11`}
                                    >
                                        Back to registers
                                    </Link>
                                }
                            />
                        </CardContent>
                    </Card>
                </main>
            </>
        );
    }

    const shiftMeta = [
        register.name,
        `Shift ${shift.shiftNumber}`,
        businessDate,
    ]
        .filter(Boolean)
        .join(' · ');

    const diningSwitcher =
        menu.diningOptions.length > 0 ? (
            <div
                className="inline-flex max-w-full snap-x items-center gap-0.5 overflow-x-auto rounded-lg bg-muted p-1"
                role="group"
                aria-label="Dining option"
            >
                {menu.diningOptions.map((option) => {
                    const selected =
                        (order?.diningOptionId ?? diningId) === option.id;
                    const optionDisabled =
                        order !== null && hasLines && order.status !== 'open';

                    return (
                        <button
                            key={option.id}
                            type="button"
                            aria-pressed={selected}
                            disabled={optionDisabled}
                            onClick={() => handleDiningOption(option.id)}
                            className={cn(
                                'inline-flex min-h-11 shrink-0 snap-start items-center rounded-md px-3.5 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-foreground disabled:opacity-50',
                                selected
                                    ? 'bg-card font-semibold text-foreground shadow-xs'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {option.name}
                        </button>
                    );
                })}
            </div>
        ) : undefined;

    return (
        <div
            data-pos-register
            className="flex min-h-dvh flex-col bg-background lg:h-dvh lg:overflow-hidden"
        >
            <Head title={`${outlet.name} · ${register.name}`} />

            <PosNavbar
                title={outlet.name}
                subtitle={shiftMeta}
                titleAs="h1"
                back={{ href: posIndex.url(), label: 'Registers' }}
                leftExtra={
                    !trading ? (
                        <StatusBadge
                            status={{
                                value: shift.status,
                                label: shift.statusLabel,
                                tone: 'warning',
                            }}
                        />
                    ) : undefined
                }
                center={diningSwitcher}
                right={
                    <>
                        <Link
                            href={cfdShow.url({ register: register.id })}
                            target="_blank"
                            rel="noopener noreferrer"
                            className={buttonVariants({
                                variant: 'ghost',
                                className: 'min-h-11 px-2.5',
                            })}
                        >
                            <Monitor
                                data-icon="inline-start"
                                aria-hidden="true"
                            />
                            <span className="hidden xl:inline">
                                Customer display
                            </span>
                            <span className="sr-only xl:hidden">
                                Customer display
                            </span>
                        </Link>
                        {clock.required && (
                            <Button
                                type="button"
                                variant={
                                    clock.clockedIn ? 'secondary' : 'ghost'
                                }
                                className="min-h-11 px-2.5"
                                disabled={busyAction !== null}
                                focusableWhenDisabled
                                onClick={() =>
                                    router.post(
                                        clockRoute.url(),
                                        {
                                            outlet_id: outlet.id,
                                            action: clock.clockedIn
                                                ? 'out'
                                                : 'in',
                                        },
                                        {
                                            preserveScroll: true,
                                            onStart: () =>
                                                setBusyAction('clock'),
                                            onFinish: () => setBusyAction(null),
                                        },
                                    )
                                }
                            >
                                {busyAction === 'clock' ? (
                                    <Spinner data-icon="inline-start" />
                                ) : (
                                    <Clock
                                        data-icon="inline-start"
                                        aria-hidden="true"
                                    />
                                )}
                                {clock.clockedIn ? 'Clock out' : 'Clock in'}
                            </Button>
                        )}
                        {locked ? (
                            <Button
                                type="button"
                                variant="ghost"
                                className="min-h-11 px-2.5"
                                onClick={() => {
                                    setSwitchKey((k) => k + 1);
                                    setSwitching(true);
                                }}
                            >
                                <UserRound
                                    data-icon="inline-start"
                                    aria-hidden="true"
                                />
                                <span className="max-w-32 truncate">
                                    {actor.name}
                                </span>
                            </Button>
                        ) : (
                            <span className="hidden items-center gap-1.5 px-2 text-xs font-medium text-muted-foreground 2xl:inline-flex">
                                <UserRound
                                    className="size-3.5"
                                    aria-hidden="true"
                                />
                                <span className="max-w-32 truncate">
                                    {actor.name}
                                </span>
                            </span>
                        )}
                        {can.shift && (
                            <>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    className="min-h-11 px-2.5"
                                    onClick={() => setMoving(true)}
                                >
                                    <Banknote
                                        data-icon="inline-start"
                                        aria-hidden="true"
                                    />
                                    Cash
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="min-h-11 bg-card px-3"
                                    onClick={() => setClosing(true)}
                                >
                                    Close shift
                                </Button>
                            </>
                        )}
                    </>
                }
            />

            {pendingQrOrders.length > 0 && (
                <div
                    role="status"
                    className="flex shrink-0 flex-wrap items-center gap-2 border-b border-warning/40 bg-warning/10 px-4 py-2 text-sm sm:px-5"
                >
                    <span className="font-medium text-warning-text">
                        Incoming QR orders ({pendingQrOrders.length}): review
                        and send to kitchen.
                    </span>
                    {pendingQrOrders.map((entry) => (
                        <Button
                            key={entry.id}
                            type="button"
                            size="sm"
                            variant={
                                order?.id === entry.id ? 'default' : 'outline'
                            }
                            className="min-h-11 bg-card"
                            onClick={() =>
                                router.get(
                                    terminal.url(
                                        { outlet: outlet.id },
                                        {
                                            query: {
                                                register: register.id,
                                                order: entry.id,
                                            },
                                        },
                                    ),
                                )
                            }
                        >
                            {entry.tableCode
                                ? `Table ${entry.tableCode}`
                                : entry.source === 'room_qr'
                                  ? 'Room QR'
                                  : `#${entry.id}`}{' '}
                            · {entry.draftCount} new
                        </Button>
                    ))}
                </div>
            )}

            <div
                className="grid shrink-0 grid-cols-2 gap-1 border-b bg-muted/40 p-2 lg:hidden"
                role="group"
                aria-label="Register view"
            >
                <button
                    type="button"
                    aria-pressed={mobilePane === 'menu'}
                    onClick={() => setMobilePane('menu')}
                    className={cn(
                        'min-h-11 rounded-lg text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-foreground',
                        mobilePane === 'menu'
                            ? 'bg-card font-semibold text-foreground shadow-xs'
                            : 'text-muted-foreground',
                    )}
                >
                    {showFloorBoard ? 'Floor plan' : 'Menu'}
                </button>
                <button
                    type="button"
                    aria-pressed={mobilePane === 'order'}
                    onClick={() => setMobilePane('order')}
                    className={cn(
                        'flex min-h-11 items-center justify-between rounded-lg px-3.5 text-sm font-medium tabular-nums transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-foreground',
                        mobilePane === 'order'
                            ? 'bg-card font-semibold text-foreground shadow-xs'
                            : 'text-muted-foreground',
                    )}
                >
                    <span>
                        Order{liveItemCount > 0 ? ` (${liveItemCount})` : ''}
                    </span>
                    {order && (
                        <MoneyText
                            amount={order.grandTotal}
                            currency={currency}
                        />
                    )}
                </button>
            </div>

            <main className="grid min-h-0 flex-1 grid-cols-1 lg:grid-cols-[minmax(0,1fr)_24rem] xl:grid-cols-[minmax(0,1fr)_26rem]">
                <section
                    ref={menuPane}
                    tabIndex={-1}
                    aria-label="Menu and floor"
                    className={cn(
                        'min-h-0 flex-col gap-3 bg-muted/40 p-4 outline-none sm:p-5',
                        mobilePane === 'menu' ? 'flex' : 'hidden lg:flex',
                    )}
                >
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
                                <div className="flex flex-wrap items-center justify-between gap-2">
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
                                            className: 'min-h-11 bg-card',
                                        })}
                                    >
                                        <ArrowLeft
                                            data-icon="inline-start"
                                            aria-hidden="true"
                                        />
                                        Floor plan
                                    </Link>
                                    <div
                                        className="inline-flex items-center gap-0.5 rounded-lg bg-muted p-1"
                                        role="group"
                                        aria-label="Course"
                                    >
                                        {[1, 2, 3, 4].map((number) => (
                                            <button
                                                key={number}
                                                type="button"
                                                aria-pressed={course === number}
                                                onClick={() =>
                                                    setCourse(number)
                                                }
                                                className={cn(
                                                    'min-h-11 rounded-md px-3 text-sm font-medium tabular-nums transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-foreground',
                                                    course === number
                                                        ? 'bg-card font-semibold text-foreground shadow-xs'
                                                        : 'text-muted-foreground hover:text-foreground',
                                                )}
                                            >
                                                Course {number}
                                            </button>
                                        ))}
                                    </div>
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

                <aside
                    aria-label="Active order ticket"
                    className={cn(
                        'min-h-0 flex-col bg-card lg:border-l',
                        mobilePane === 'order' ? 'flex' : 'hidden lg:flex',
                    )}
                >
                    {order !== null && openOrders.length > 0 && (
                        <nav
                            aria-label="Open checks"
                            className="flex shrink-0 snap-x items-center gap-1.5 overflow-x-auto border-b bg-muted/40 px-3 py-2"
                        >
                            {openOrders.map((open) => {
                                const isCurrent = order.id === open.id;

                                return (
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
                                        aria-current={
                                            isCurrent ? 'page' : undefined
                                        }
                                        className={cn(
                                            'inline-flex min-h-11 shrink-0 snap-start flex-col justify-center rounded-lg px-3 py-1 text-left transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-foreground',
                                            isCurrent
                                                ? 'border bg-card font-semibold text-foreground shadow-xs'
                                                : 'text-muted-foreground hover:bg-card/70 hover:text-foreground',
                                        )}
                                    >
                                        <span className="text-xs font-semibold tabular-nums">
                                            {open.tableCode !== null
                                                ? `${open.tableCode} · `
                                                : ''}
                                            {open.orderNumber ?? `#${open.id}`}
                                        </span>
                                        <span className="text-xs text-muted-foreground tabular-nums">
                                            <MoneyText
                                                amount={open.grandTotal}
                                                currency={currency}
                                            />
                                        </span>
                                    </Link>
                                );
                            })}
                        </nav>
                    )}

                    <div className="flex shrink-0 flex-wrap items-center justify-between gap-2 border-b px-4 py-3.5">
                        <div className="flex min-w-0 items-center gap-2">
                            <h2 className="truncate font-heading text-base font-semibold text-foreground">
                                {order
                                    ? `Order ${order.orderNumber ?? `#${order.id}`}`
                                    : openOrders.length > 0
                                      ? 'Open checks'
                                      : 'New order'}
                            </h2>
                            {order?.tableCode ? (
                                <Badge variant="secondary">
                                    Table {order.tableCode}
                                </Badge>
                            ) : (
                                order === null &&
                                openOrders.length > 0 && (
                                    <Badge
                                        variant="secondary"
                                        className="tabular-nums"
                                    >
                                        {openOrders.length}
                                    </Badge>
                                )
                            )}
                        </div>
                        {order !== null && (
                            <Link
                                href={terminal.url(
                                    { outlet: outlet.id },
                                    { query: { register: register.id } },
                                )}
                                className={buttonVariants({
                                    variant: 'ghost',
                                    size: 'sm',
                                    className: 'min-h-11 px-2.5',
                                })}
                            >
                                <Plus
                                    data-icon="inline-start"
                                    aria-hidden="true"
                                />
                                New
                            </Link>
                        )}
                    </div>

                    {order && (
                        <div className="flex shrink-0 flex-wrap items-center gap-1.5 border-b bg-muted/20 px-4 py-2">
                            <Button
                                type="button"
                                variant={
                                    order.roomLabel ? 'secondary' : 'ghost'
                                }
                                size="sm"
                                className="min-h-11 px-2.5"
                                onClick={() => setLinkingRoom(true)}
                            >
                                <BedDouble
                                    data-icon="inline-start"
                                    aria-hidden="true"
                                />
                                {order.roomLabel
                                    ? `Room: ${order.roomLabel}`
                                    : 'Link room / guest'}
                            </Button>
                            {can.shift && registers.length > 1 && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="min-h-11 px-2.5"
                                    onClick={() => setTransferring(true)}
                                >
                                    <ArrowLeftRight
                                        data-icon="inline-start"
                                        aria-hidden="true"
                                    />
                                    Transfer
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
                    ) : openOrders.length > 0 ? (
                        <div className="flex min-h-0 flex-1 flex-col">
                            <ul
                                role="list"
                                className="flex min-h-0 flex-1 flex-col divide-y overflow-y-auto"
                            >
                                {openOrders.map((open) => (
                                    <li key={open.id}>
                                        <Link
                                            href={terminal.url(
                                                { outlet: outlet.id },
                                                {
                                                    query: {
                                                        register: register.id,
                                                        order: open.id,
                                                    },
                                                },
                                            )}
                                            className="flex min-h-16 items-center justify-between gap-3 px-4 py-3 transition-colors hover:bg-muted/50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-foreground"
                                        >
                                            <div className="flex min-w-0 items-center gap-3">
                                                <span
                                                    className={cn(
                                                        'flex h-10 min-w-11 shrink-0 items-center justify-center rounded-lg px-2 font-heading text-sm font-bold tabular-nums',
                                                        open.tableCode !== null
                                                            ? 'bg-brand-forest text-primary-foreground'
                                                            : 'bg-muted text-foreground',
                                                    )}
                                                >
                                                    {open.tableCode ?? 'Walk'}
                                                </span>
                                                <div className="flex min-w-0 flex-col gap-0.5">
                                                    <span className="truncate text-sm font-semibold text-foreground tabular-nums">
                                                        {open.orderNumber ??
                                                            `Check #${open.id}`}
                                                    </span>
                                                    <span className="text-xs text-muted-foreground tabular-nums">
                                                        Opened{' '}
                                                        {new Date(
                                                            open.openedAt,
                                                        ).toLocaleTimeString(
                                                            [],
                                                            {
                                                                hour: '2-digit',
                                                                minute: '2-digit',
                                                            },
                                                        )}
                                                        {(open.draftCount ??
                                                            0) > 0
                                                            ? ` · ${open.draftCount} unsent`
                                                            : ''}
                                                    </span>
                                                </div>
                                            </div>
                                            <div className="flex shrink-0 items-center gap-1.5">
                                                <span className="font-heading text-sm font-bold text-foreground tabular-nums">
                                                    <MoneyText
                                                        amount={open.grandTotal}
                                                        currency={currency}
                                                    />
                                                </span>
                                                <ChevronRight
                                                    className="size-4 text-muted-foreground"
                                                    aria-hidden="true"
                                                />
                                            </div>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                            <p className="border-t bg-muted/25 px-4 py-3 text-xs text-muted-foreground">
                                {showFloorBoard
                                    ? 'Select a table or open check to add items and settle.'
                                    : 'Tap a menu item or select an open check to continue.'}
                            </p>
                        </div>
                    ) : (
                        <div className="flex flex-1 flex-col items-center justify-center gap-3 px-6 py-12 text-center">
                            <span
                                aria-hidden="true"
                                className="flex size-12 items-center justify-center rounded-xl border bg-muted/40 text-muted-foreground"
                            >
                                <Receipt className="size-5" />
                            </span>
                            <div className="flex flex-col gap-1">
                                <p className="font-heading text-base font-semibold text-foreground">
                                    Ready for the next order
                                </p>
                                <p className="max-w-xs text-xs text-muted-foreground">
                                    {showFloorBoard
                                        ? 'Select a table on the floor plan or start a counter check.'
                                        : 'Tap a menu item or scan a barcode to open a check.'}
                                </p>
                            </div>
                        </div>
                    )}

                    {order && (
                        <div className="flex shrink-0 flex-col gap-2.5 border-t bg-card p-3.5">
                            <div className="grid grid-cols-2 gap-1.5">
                                <Button
                                    type="button"
                                    variant={hasDraft ? 'secondary' : 'outline'}
                                    className={cn(
                                        'min-h-11',
                                        outlet.payLater &&
                                            order.status === 'open' &&
                                            'col-span-2',
                                    )}
                                    disabled={!hasDraft || busyAction !== null}
                                    focusableWhenDisabled
                                    onClick={() =>
                                        router.post(
                                            fire.url({ order: order.id }),
                                            {},
                                            {
                                                preserveScroll: true,
                                                onStart: () =>
                                                    setBusyAction('fire'),
                                                onFinish: () =>
                                                    setBusyAction(null),
                                            },
                                        )
                                    }
                                >
                                    {busyAction === 'fire' ? (
                                        <Spinner data-icon="inline-start" />
                                    ) : (
                                        <Send
                                            data-icon="inline-start"
                                            aria-hidden="true"
                                        />
                                    )}
                                    {hasDraft ? `Send (${draftCount})` : 'Send'}
                                </Button>
                                {outlet.payLater && order.status === 'open' && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="min-h-11"
                                        onClick={() => setCheckActions(true)}
                                    >
                                        <SlidersHorizontal
                                            data-icon="inline-start"
                                            aria-hidden="true"
                                        />
                                        Check actions
                                    </Button>
                                )}
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="min-h-11"
                                    disabled={!hasLines || busyAction !== null}
                                    focusableWhenDisabled
                                    onClick={handlePrebill}
                                >
                                    {busyAction === 'prebill' ? (
                                        <Spinner data-icon="inline-start" />
                                    ) : (
                                        <Receipt
                                            data-icon="inline-start"
                                            aria-hidden="true"
                                        />
                                    )}
                                    Pre-bill
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="min-h-11"
                                    disabled={!hasLines}
                                    onClick={() => setDiscounting(true)}
                                >
                                    <Percent
                                        data-icon="inline-start"
                                        aria-hidden="true"
                                    />
                                    Discount
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="min-h-11"
                                    disabled={busyAction !== null}
                                    focusableWhenDisabled
                                    onClick={() =>
                                        hasLines
                                            ? setVoiding({ line: null })
                                            : router.post(
                                                  voidEmptyOrder.url({
                                                      order: order.id,
                                                  }),
                                                  {},
                                                  {
                                                      preserveScroll: true,
                                                      onStart: () =>
                                                          setBusyAction(
                                                              'discard',
                                                          ),
                                                      onFinish: () =>
                                                          setBusyAction(null),
                                                  },
                                              )
                                    }
                                >
                                    {busyAction === 'discard' ? (
                                        <Spinner data-icon="inline-start" />
                                    ) : (
                                        <Trash2
                                            data-icon="inline-start"
                                            aria-hidden="true"
                                        />
                                    )}
                                    {hasLines ? 'Void order' : 'Discard check'}
                                </Button>
                            </div>

                            <Button
                                type="button"
                                className="h-14 w-full text-base font-semibold tabular-nums"
                                disabled={!hasLines || !trading}
                                onClick={() => setPaying(true)}
                            >
                                {Number(order.grandTotal) === 0 ? (
                                    'Settle check'
                                ) : (
                                    <>
                                        Pay{' '}
                                        <MoneyText
                                            amount={order.grandTotal}
                                            currency={currency}
                                        />
                                    </>
                                )}
                            </Button>

                            {hasSent && order.prebillPrintCount > 0 && (
                                <p className="text-xs text-muted-foreground">
                                    Pre-bill printed {order.prebillPrintCount}×.
                                    Changes now need a manager.
                                </p>
                            )}
                        </div>
                    )}
                </aside>
            </main>

            {mobilePane === 'menu' && order !== null && hasLines && (
                <div className="sticky bottom-0 flex shrink-0 items-center justify-between gap-3 border-t bg-card px-4 py-3 lg:hidden">
                    <div className="flex flex-col">
                        <span className="text-xs text-muted-foreground tabular-nums">
                            {liveItemCount}{' '}
                            {liveItemCount === 1 ? 'item' : 'items'}
                        </span>
                        <span className="font-heading text-lg font-semibold tabular-nums">
                            <MoneyText
                                amount={order.grandTotal}
                                currency={currency}
                            />
                        </span>
                    </div>
                    <Button
                        type="button"
                        className="min-h-11 px-5"
                        onClick={() => setMobilePane('order')}
                    >
                        Review &amp; pay
                    </Button>
                </div>
            )}

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
                                    <Printer
                                        data-icon="inline-start"
                                        aria-hidden="true"
                                    />
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
                    cancelLabel="Close"
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
                            className="min-h-11"
                            onClick={() => window.print()}
                        >
                            <Printer
                                data-icon="inline-start"
                                aria-hidden="true"
                            />
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
                    requiresApproval={!can.refund}
                />
            )}

            <Dialog open={switching} onOpenChange={setSwitching}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Switch user</DialogTitle>
                    </DialogHeader>
                    <PosPinPad
                        key={switchKey}
                        staff={staff}
                        onDone={() => setSwitching(false)}
                    />
                </DialogContent>
            </Dialog>
        </div>
    );
}
