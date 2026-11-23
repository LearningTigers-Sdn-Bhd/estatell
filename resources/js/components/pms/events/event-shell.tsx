import { Form, router } from '@inertiajs/react';
import {
    Ban,
    CalendarCheck,
    CalendarDays,
    ClipboardList,
    CreditCard,
    Hash,
    LayoutGrid,
    MoreHorizontal,
    Pencil,
    Phone,
    Printer,
    User,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { formatDate } from '@/components/pms/date-text';
import { EventFormDialog } from '@/components/pms/events/event-form-dialog';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { TabLinks, withTabCrumb } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';
import { Workspace } from '@/components/pms/workspace';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import {
    bookingSummary,
    bookings,
    cancel,
    confirm,
    index,
    show,
} from '@/routes/events';

type Event = App.Data.Events.EventData;

export type EventTab = 'folio' | 'bookings';

/** What every tab of the event page receives for its header, summary and Edit event Dialog. */
export type EventShellProps = {
    event: Event;
    canViewCompany: boolean;
    companies?: App.Data.CityLedger.CompanyOptionData[];
};

/** "20 Oct 2026", or "11 Dec 2026 – 12 Dec 2026" for more than one day. */
export function eventDates(startsOn: string, endsOn: string): string {
    return startsOn === endsOn
        ? formatDate(startsOn)
        : `${formatDate(startsOn)} – ${formatDate(endsOn)}`;
}

/** The tabs of the event page, for the tab bar and the breadcrumbs. */
export function eventTabs(eventId: number): TabLink[] {
    return [
        {
            value: 'folio',
            label: 'Folio',
            icon: CreditCard,
            href: show.url({ event: eventId }),
        },
        {
            value: 'bookings',
            label: 'Bookings',
            icon: LayoutGrid,
            href: bookings.url({ event: eventId }),
        },
    ];
}

/** Events › {event} › {tab}. */
export function eventBreadcrumbs(props: { event: Event }, activeTab: EventTab) {
    return withTabCrumb(
        [
            { title: 'Events', href: index() },
            { title: props.event.name, href: show({ event: props.event.id }) },
        ],
        eventTabs(props.event.id),
        activeTab,
    );
}

function SummaryRow({
    icon: Icon,
    label,
    children,
}: {
    icon: LucideIcon;
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="flex items-baseline justify-between gap-3 text-sm">
            <dt className="flex shrink-0 items-center gap-2 text-muted-foreground">
                <Icon aria-hidden className="size-4 shrink-0 self-center" />
                {label}
            </dt>
            <dd className="min-w-0 text-right wrap-break-word">{children}</dd>
        </div>
    );
}

function EventSummary({ event }: { event: Event }) {
    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-center gap-3 pt-2">
                <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                    <ClipboardList aria-hidden className="size-4" />
                </span>
                <h2 className="text-base font-medium">Summary</h2>
            </div>
            <Separator />
            <dl className="flex flex-col gap-2">
                <SummaryRow icon={User} label="Payer">
                    {event.payerName}
                </SummaryRow>
                <SummaryRow icon={Phone} label="Phone">
                    {event.payerPhone ?? (
                        <span className="text-muted-foreground">None</span>
                    )}
                </SummaryRow>
                <SummaryRow icon={CalendarDays} label="Dates">
                    <span className="tabular-nums">
                        {eventDates(event.startsOn, event.endsOn)}
                    </span>
                </SummaryRow>
                <SummaryRow icon={Hash} label="Event no.">
                    <span className="tabular-nums">{event.number}</span>
                </SummaryRow>
            </dl>
            {event.notes && (
                <>
                    <Separator />
                    <p className="text-sm whitespace-pre-line text-muted-foreground">
                        {event.notes}
                    </p>
                </>
            )}
        </div>
    );
}

function EventActions({ event, canViewCompany, companies }: EventShellProps) {
    const [editing, setEditing] = useState(false);
    const [cancelling, setCancelling] = useState(false);
    const [confirming, setConfirming] = useState(false);

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger render={<Button variant="outline" />}>
                    <MoreHorizontal data-icon="inline-start" />
                    More actions
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    {event.canEdit && (
                        <DropdownMenuItem onClick={() => setEditing(true)}>
                            <Pencil />
                            Edit event
                        </DropdownMenuItem>
                    )}
                    {event.canConfirm && (
                        <DropdownMenuItem
                            disabled={confirming}
                            onClick={() =>
                                router.post(
                                    confirm.url({ event: event.id }),
                                    {},
                                    {
                                        preserveScroll: true,
                                        onStart: () => setConfirming(true),
                                        onFinish: () => setConfirming(false),
                                    },
                                )
                            }
                        >
                            <CalendarCheck />
                            Confirm
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuItem
                        render={
                            <a
                                href={bookingSummary.url({ event: event.id })}
                                target="_blank"
                                rel="noreferrer"
                            />
                        }
                    >
                        <Printer />
                        Print booking summary
                    </DropdownMenuItem>
                    {event.payerCompanyId !== null && (
                        <DropdownMenuItem
                            render={
                                <a
                                    href={bookingSummary.url(
                                        { event: event.id },
                                        { query: { prices: 0 } },
                                    )}
                                    target="_blank"
                                    rel="noreferrer"
                                />
                            }
                        >
                            <Printer />
                            Print without prices
                        </DropdownMenuItem>
                    )}
                    {event.canCancel && (
                        <DropdownMenuItem
                            variant="destructive"
                            onClick={() => setCancelling(true)}
                        >
                            <Ban />
                            Cancel event
                        </DropdownMenuItem>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>

            {event.canEdit && (
                <EventFormDialog
                    open={editing}
                    onOpenChange={setEditing}
                    event={event}
                    canViewCompany={canViewCompany}
                    companies={companies}
                />
            )}

            <AlertDialog open={cancelling} onOpenChange={setCancelling}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Cancel {event.name}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            The folio stays open. Refund the deposit or post a
                            cancellation fee, then close the folio at 0.00.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <Form
                        {...cancel.form({ event: event.id })}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setCancelling(false)}
                    >
                        {({ processing }) => (
                            <AlertDialogFooter>
                                <AlertDialogCancel>
                                    Keep event
                                </AlertDialogCancel>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                    focusableWhenDisabled
                                >
                                    {processing && (
                                        <Spinner data-icon="inline-start" />
                                    )}
                                    Cancel event
                                </Button>
                            </AlertDialogFooter>
                        )}
                    </Form>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

/**
 * Header, tab links and workspace of the event page. Each tab is its own page and URL. The summary sits in the aside,
 * and the Folio tab can hide it. See docs/33-facilities/03-events-and-event-folios.md#event-page-detail.
 */
export function EventShell({
    activeTab,
    hideSummary = false,
    children,
    ...props
}: EventShellProps & {
    activeTab: EventTab;
    hideSummary?: boolean;
    children: ReactNode;
}) {
    const { event } = props;

    return (
        <div className="flex flex-1 flex-col gap-6 p-4">
            <PageHeader
                title={event.name}
                description={`${event.number} · ${eventDates(event.startsOn, event.endsOn)} · ${event.payerName}`}
                back={{ href: index.url(), label: 'Events' }}
                badge={<StatusBadge status={event.status} />}
                actions={<EventActions {...props} />}
            />
            <TabLinks
                tabs={eventTabs(event.id)}
                activeTab={activeTab}
                panelWidth="full"
                className="flex flex-col gap-4 lg:flex-1"
                panelClassName="flex flex-col lg:flex-1"
            >
                <Workspace
                    scroll="page"
                    asideLabel="Event summary"
                    hideAside={hideSummary}
                    aside={<EventSummary event={event} />}
                >
                    {children}
                </Workspace>
            </TabLinks>
        </div>
    );
}
