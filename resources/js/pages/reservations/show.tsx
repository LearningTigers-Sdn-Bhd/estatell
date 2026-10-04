import { Head } from '@inertiajs/react';
import { BedDouble, CreditCard, History, Receipt, Users } from 'lucide-react';
import type { EnumOption } from '@/components/pms/enum-select';
import { DateText } from '@/components/pms/date-text';
import { FolioRail } from '@/components/pms/folio/folio-rail';
import { FolioTab } from '@/components/pms/folio/folio-tab';
import { PageHeader } from '@/components/pms/page-header';
import { TabLinks } from '@/components/pms/tab-links';
import { ReservationActions } from '@/components/pms/reservations/reservation-actions';
import { ActivityTab } from '@/components/pms/reservations/activity-tab';
import { AddOnsTab } from '@/components/pms/reservations/add-ons-tab';
import { GuestsTab } from '@/components/pms/reservations/guests-tab';
import { ReservationSummary } from '@/components/pms/reservations/reservation-summary';
import { StatusByUnitPopover } from '@/components/pms/reservations/status-by-unit-popover';
import { ReservationWorkspace } from '@/components/pms/reservations/reservation-workspace';
import { UnitsList, UnitsTab } from '@/components/pms/reservations/units-tab';
import { useSummaryHidden } from '@/components/pms/reservations/use-summary-hidden';
import { Badge } from '@/components/ui/badge';
import {
    activity as activityTab,
    addOns as addOnsTab,
    folio as folioTab,
    guests as guestsTab,
    index,
    show,
} from '@/routes/reservations';

type Tab = 'units' | 'guests' | 'add-ons' | 'folio' | 'activity';

type Props = {
    reservation: App.Data.Reservations.ReservationData;
    units: App.Data.Reservations.ReservationUnitOptionData[];
    tab: Tab;
    autoAssignRooms: boolean;
    currency: App.Enums.Currency | null;
    canManage: boolean;
    billingModes: EnumOption[];
    canViewCompany: boolean;
    canOverrideCredit: boolean;
    companies?: App.Data.CityLedger.CompanyOptionData[];
    addUnitOptions?: App.Data.Reservations.AddUnitOptionsData;
    chargeItems?: App.Data.Reservations.ChargeItemOptionData[];
    chargeCodes?: App.Data.Reservations.ChargeCodeOptionData[];
    folioDiscounts?: App.Data.Folio.FolioDiscountOptionData[];
    paymentOptions?: App.Data.Payments.PaymentFormOptionsData;
    unit?: App.Data.Reservations.ReservationUnitData | null;
    guests?: App.Data.Reservations.ReservationGuestData[];
    addOns?: App.Data.Reservations.ReservationAddOnData[];
    folios?: App.Data.Folio.FolioRailItemData[];
    folio?: App.Data.Folio.FolioData | null;
    canManageRouting?: boolean;
    routingRules?: App.Data.Folio.RoutingRulesData | null;
    voucher: { kinds: { value: string; label: string }[]; maxLines: number };
    activity?: Illuminate.LengthAwarePaginator<
        number,
        App.Data.Reservations.ReservationActivityData
    >;
};

export default function ReservationShow({
    reservation,
    units,
    tab,
    autoAssignRooms,
    currency,
    canManage,
    billingModes,
    canViewCompany,
    canOverrideCredit,
    companies,
    addUnitOptions,
    chargeItems,
    chargeCodes,
    folioDiscounts,
    paymentOptions,
    unit = null,
    guests = [],
    addOns = [],
    folios = [],
    folio = null,
    canManageRouting = false,
    routingRules,
    voucher,
    activity,
}: Props) {
    const key = { reservation: reservation.id };
    const tabs = [
        {
            value: 'units',
            label: 'Units',
            icon: BedDouble,
            href: show.url(key),
        },
        {
            value: 'guests',
            label: 'Guests',
            icon: Users,
            href: guestsTab.url(key),
        },
        {
            value: 'add-ons',
            label: 'Add-ons',
            icon: Receipt,
            href: addOnsTab.url(key),
        },
        {
            value: 'folio',
            label: 'Folio',
            icon: CreditCard,
            href: folioTab.url(key),
        },
        {
            value: 'activity',
            label: 'Activity',
            icon: History,
            href: activityTab.url(key),
        },
    ];

    const [summaryHidden, toggleSummary] = useSummaryHidden();

    const list =
        tab === 'folio' && folio !== null && folios.length > 1 ? (
            <FolioRail
                reservationId={reservation.id}
                items={folios}
                activeKey={folio.key}
                currency={currency}
            />
        ) : tab === 'units' && unit !== null && units.length > 1 ? (
            <UnitsList
                reservationId={reservation.id}
                units={units}
                activeUnitId={unit.id}
                autoAssignRooms={autoAssignRooms}
            />
        ) : undefined;

    return (
        <>
            <Head title={reservation.reference} />

            <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4 lg:overflow-hidden">
                <PageHeader
                    title={`${reservation.reference} · ${reservation.title}`}
                    description={
                        <span className="flex flex-wrap items-center gap-x-2">
                            <DateText date={reservation.firstArrival} /> →{' '}
                            <DateText date={reservation.lastDeparture} />
                            <span>
                                · {reservation.unitCount}{' '}
                                {reservation.unitCount === 1 ? 'unit' : 'units'}
                            </span>
                            <span>· {reservation.sourceLabel}</span>
                            {reservation.externalReference && (
                                <span>
                                    · Ext. ref {reservation.externalReference}
                                </span>
                            )}
                        </span>
                    }
                    badge={
                        <>
                            {reservation.isGroup && (
                                <Badge variant="outline">Group</Badge>
                            )}
                            <StatusByUnitPopover
                                status={reservation.status}
                                units={units}
                            />
                        </>
                    }
                    back={{ href: index.url(), label: 'Reservations' }}
                    actions={
                        canManage ? (
                            <ReservationActions
                                reservation={reservation}
                                billingModes={billingModes}
                                addUnitOptions={addUnitOptions}
                                canViewCompany={canViewCompany}
                                canOverrideCredit={canOverrideCredit}
                                companies={companies}
                                currency={currency}
                            />
                        ) : undefined
                    }
                />

                <TabLinks
                    tabs={tabs}
                    activeTab={tab}
                    panelWidth="full"
                    className="flex flex-col gap-4 lg:min-h-0 lg:flex-1"
                    panelClassName="flex flex-col lg:min-h-0 lg:flex-1"
                >
                    <ReservationWorkspace
                        list={list}
                        hideAside={tab === 'folio' && summaryHidden}
                        aside={
                            <ReservationSummary
                                reservation={reservation}
                                canViewCompany={canViewCompany}
                                currency={currency}
                            />
                        }
                    >
                        {tab === 'guests' ? (
                            <GuestsTab
                                reservationId={reservation.id}
                                guests={guests}
                                units={units}
                                canManage={canManage}
                            />
                        ) : tab === 'add-ons' ? (
                            <AddOnsTab
                                reservationId={reservation.id}
                                addOns={addOns}
                                units={units}
                                chargeItems={chargeItems}
                                canManage={canManage}
                                currency={currency}
                            />
                        ) : tab === 'folio' ? (
                            <FolioTab
                                reservationId={reservation.id}
                                units={units}
                                folio={folio}
                                chargeItems={chargeItems}
                                chargeCodes={chargeCodes}
                                folioDiscounts={folioDiscounts}
                                paymentOptions={paymentOptions}
                                canManageRouting={canManageRouting}
                                canOverrideCredit={canOverrideCredit}
                                routingRules={routingRules}
                                voucher={voucher}
                                summaryHidden={summaryHidden}
                                onToggleSummary={toggleSummary}
                                currency={currency}
                            />
                        ) : tab === 'activity' && activity ? (
                            <ActivityTab activity={activity} />
                        ) : (
                            <UnitsTab
                                unit={unit}
                                autoAssignRooms={autoAssignRooms}
                                currency={currency}
                            />
                        )}
                    </ReservationWorkspace>
                </TabLinks>
            </div>
        </>
    );
}

ReservationShow.layout = (props: {
    reservation: App.Data.Reservations.ReservationData;
}) => ({
    variant: 'fixed',
    breadcrumbs: [
        { title: 'Reservations', href: index() },
        {
            title: props.reservation.reference,
            href: show({ reservation: props.reservation.id }),
        },
    ],
});
