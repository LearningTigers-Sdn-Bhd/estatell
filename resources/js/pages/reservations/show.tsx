import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { BedDouble, CreditCard, History, Receipt, Users } from 'lucide-react';
import type { EnumOption } from '@/components/pms/enum-select';
import { DateText } from '@/components/pms/date-text';
import { FolioRail } from '@/components/pms/folio/folio-rail';
import { FolioTab } from '@/components/pms/folio/folio-tab';
import { PageHeader } from '@/components/pms/page-header';
import { TabLinks } from '@/components/pms/tab-links';
import { ReservationActions } from '@/components/pms/reservations/reservation-actions';
import { ActivityTab } from '@/components/pms/reservations/activity-tab';
import { ChangeNightPriceDialog } from '@/components/pms/reservations/change-night-price-dialog';
import { AddOnsTab } from '@/components/pms/reservations/add-ons-tab';
import { GuestsRail } from '@/components/pms/reservations/guests-rail';
import { GuestsTab } from '@/components/pms/reservations/guests-tab';
import { guestGroups } from '@/components/pms/reservations/guest-rows';
import { useGuestsView } from '@/components/pms/reservations/use-guests-view';
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
    money: App.Data.Reservations.ReservationMoneyData;
    units: App.Data.Reservations.ReservationUnitOptionData[];
    tab: Tab;
    autoAssignRooms: boolean;
    currency: App.Data.Property.CurrencyData | null;
    canManage: boolean;
    canCheckIn: boolean;
    canCheckOutAll: boolean;
    receiptPaymentIds: number[];
    canCancelReservation: boolean;
    hasUnitToCheckIn: boolean;
    singleUnit: App.Data.Reservations.ReservationUnitData | null;
    canWaiveFees: boolean;
    canOverrideRestrictions: boolean;
    billingModes: EnumOption[];
    manualReasons: EnumOption[];
    canViewCompany: boolean;
    canOverrideCredit: boolean;
    companies?: App.Data.CityLedger.CompanyOptionData[];
    addUnitOptions?: App.Data.Reservations.AddUnitOptionsData;
    chargeItems?: App.Data.Reservations.ChargeItemOptionData[];
    chargeCodes?: App.Data.Reservations.ChargeCodeOptionData[];
    folioDiscounts?: App.Data.Folio.FolioDiscountOptionData[];
    paymentOptions?: App.Data.Payments.PaymentFormOptionsData;
    hasBoats?: boolean;
    boatDepartures?: App.Data.Boats.BoatDepartureOptionData[];
    boatSetup?: App.Data.Boats.BoatSetupUnitData[];
    unit?: App.Data.Reservations.ReservationUnitData | null;
    guests?: App.Data.Reservations.ReservationGuestData[];
    canManageGuests?: boolean;
    countries?: EnumOption[];
    genders?: EnumOption[];
    locales?: EnumOption[];
    addOns?: App.Data.Reservations.ReservationAddOnData[];
    folios?: App.Data.Folio.FolioRailItemData[];
    folio?: App.Data.Folio.FolioData | null;
    canManageRouting?: boolean;
    routingRules?: App.Data.Folio.RoutingRulesData | null;
    creditInvoice?: App.Data.CityLedger.ArInvoiceData | null;
    voucher: { kinds: { value: string; label: string }[]; maxLines: number };
    activity?: Illuminate.LengthAwarePaginator<
        number,
        App.Data.Reservations.ReservationActivityData
    >;
};

export default function ReservationShow({
    reservation,
    money,
    units,
    tab,
    autoAssignRooms,
    currency,
    canManage,
    canCheckIn,
    canCheckOutAll,
    receiptPaymentIds,
    canCancelReservation,
    hasUnitToCheckIn,
    singleUnit,
    canWaiveFees,
    canOverrideRestrictions,
    billingModes,
    manualReasons,
    canViewCompany,
    canOverrideCredit,
    companies,
    addUnitOptions,
    chargeItems,
    chargeCodes,
    folioDiscounts,
    paymentOptions,
    hasBoats = false,
    boatDepartures,
    boatSetup,
    unit = null,
    guests = [],
    canManageGuests = false,
    countries = [],
    genders = [],
    locales = [],
    addOns = [],
    folios = [],
    folio = null,
    canManageRouting = false,
    routingRules,
    creditInvoice,
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
    const [guestsView, setGuestsView] = useGuestsView();
    const [pickedGroup, setPickedGroup] = useState<string | null>(null);
    const groups = guestGroups(guests, units);
    const grouped =
        tab === 'guests' && guestsView === 'grouped' && units.length > 1;
    const activeGroup =
        groups.find((group) => group.key === pickedGroup)?.key ??
        groups[0]?.key ??
        'none';
    const [correcting, setCorrecting] = useState<{
        unitId: number;
        nightId: number;
    } | null>(null);
    const [correctingOpen, setCorrectingOpen] = useState(false);
    const correctPrice = (row: App.Data.Folio.FolioRowData) => {
        if (row.unitId === null || row.unitNightId === null) {
            return;
        }

        setCorrecting({ unitId: row.unitId, nightId: row.unitNightId });
        setCorrectingOpen(true);
    };

    const list =
        tab === 'folio' && folio !== null && folios.length > 1 ? (
            <FolioRail
                reservationId={reservation.id}
                items={folios}
                activeKey={folio.key}
                currency={currency}
            />
        ) : grouped && groups.length > 1 ? (
            <GuestsRail
                groups={groups}
                activeKey={activeGroup}
                onSelect={setPickedGroup}
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
                            <span>· {reservation.sourceName}</span>
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
                        <ReservationActions
                            canManage={canManage}
                            reservation={reservation}
                            billingModes={billingModes}
                            addUnitOptions={addUnitOptions}
                            canViewCompany={canViewCompany}
                            canCheckIn={canCheckIn}
                            canCheckOutAll={canCheckOutAll}
                            canCancelReservation={canCancelReservation}
                            hasUnitToCheckIn={hasUnitToCheckIn}
                            singleUnit={singleUnit}
                            canWaiveFees={canWaiveFees}
                            canOverrideRestrictions={canOverrideRestrictions}
                            paymentOptions={paymentOptions}
                            hasBoats={hasBoats}
                            boatDepartures={boatDepartures}
                            boatSetup={boatSetup}
                            receiptPaymentIds={receiptPaymentIds}
                            canOverrideCredit={canOverrideCredit}
                            companies={companies}
                            currency={currency}
                        />
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
                        hideAside={
                            (tab === 'folio' || tab === 'guests') &&
                            summaryHidden
                        }
                        aside={
                            <ReservationSummary
                                reservation={reservation}
                                money={money}
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
                                canManageGuests={canManageGuests}
                                countries={countries}
                                genders={genders}
                                locales={locales}
                                view={guestsView}
                                onViewChange={setGuestsView}
                                activeGroup={activeGroup}
                                summaryHidden={summaryHidden}
                                onToggleSummary={toggleSummary}
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
                                manualReasons={manualReasons}
                                paymentOptions={paymentOptions}
                                canViewCompany={canViewCompany}
                                companies={companies}
                                canManageRouting={canManageRouting}
                                routingRules={routingRules}
                                creditInvoice={creditInvoice}
                                voucher={voucher}
                                summaryHidden={summaryHidden}
                                onToggleSummary={toggleSummary}
                                onCorrectPrice={correctPrice}
                                currency={currency}
                            />
                        ) : tab === 'activity' && activity ? (
                            <ActivityTab activity={activity} />
                        ) : (
                            <UnitsTab
                                reservationId={reservation.id}
                                unit={unit}
                                autoAssignRooms={autoAssignRooms}
                                canWaiveFees={canWaiveFees}
                                canOverrideRestrictions={
                                    canOverrideRestrictions
                                }
                                paymentOptions={paymentOptions}
                                currency={currency}
                                hasBoats={hasBoats}
                                boatDepartures={boatDepartures}
                            />
                        )}
                    </ReservationWorkspace>
                </TabLinks>
                {correcting !== null && (
                    <ChangeNightPriceDialog
                        open={correctingOpen}
                        onOpenChange={setCorrectingOpen}
                        reservationId={reservation.id}
                        unitId={correcting.unitId}
                        title={
                            units.find(
                                (option) => option.id === correcting.unitId,
                            )?.label ?? ''
                        }
                        initialNightIds={[correcting.nightId]}
                        currency={currency}
                    />
                )}
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
