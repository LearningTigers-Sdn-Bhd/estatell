import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, ListChecks, Tent } from 'lucide-react';
import { DatePicker } from '@/components/pms/date-picker';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { assign, index } from '@/routes/activities';
import { facilityBookings } from '@/routes/reports';

type Slot = App.Data.Facilities.ActivitySlotData;
type Guide = App.Data.Facilities.FacilityGuideData;
type Boat = App.Data.Facilities.FacilityBoatData;

/** The Select value for "Not set". */
const NOT_SET = '';

/** A `Y-m-d` date moved by whole days, in UTC so it never shifts. */
function shiftDay(date: string, days: number): string {
    const value = new Date(`${date}T00:00:00Z`);
    value.setUTCDate(value.getUTCDate() + days);

    return value.toISOString().slice(0, 10);
}

/** The available items, plus the one already picked when it has since been turned off. */
function pickOptions(
    items: { id: number; label: string; active: boolean }[],
    pickedId: number | null,
): EnumOption[] {
    return [
        { value: NOT_SET, label: 'Not set' },
        ...items
            .filter((item) => item.active || item.id === pickedId)
            .map((item) => ({ value: String(item.id), label: item.label })),
    ];
}

const slotKey = (slot: Slot) =>
    `${slot.facilityId}|${slot.startsAt}|${slot.endsAt}`;

/**
 * Front desk › Activities: every activity session of one date, by facility and start time, with the places booked and
 * the guide and boat of each. Participants opens the Facility bookings report (the participant list) for the facility
 * and date. See docs/33-facilities/06-guides-and-boats.md#activities-run-sheet.
 */
export default function ActivitiesIndex({
    date,
    today,
    slots,
    canAssign,
    canViewList,
    guides,
    boats,
}: {
    date: string;
    today: string;
    slots: Slot[];
    canAssign: boolean;
    canViewList: boolean;
    guides: Guide[];
    boats: Boat[];
}) {
    const visit = (next: string) =>
        router.get(
            index.url(undefined, { query: { date: next } }),
            {},
            { preserveScroll: true },
        );
    const guideItems = guides.map((guide) => ({
        id: guide.id,
        label: guide.name,
        active: guide.active,
    }));
    const boatItems = boats.map((boat) => ({
        id: boat.id,
        label:
            boat.seats === null
                ? boat.name
                : `${boat.name} · ${boat.seats} seats`,
        active: boat.active,
    }));
    const nameOf = (
        items: { id: number; label: string }[],
        id: number | null,
    ) => items.find((item) => item.id === id)?.label ?? '—';

    const save = (slot: Slot, guideId: number | null, boatId: number | null) =>
        router
            .optimistic<{ slots: Slot[] }>((props) => ({
                slots: props.slots.map((item) =>
                    slotKey(item) === slotKey(slot)
                        ? {
                              ...item,
                              guideId,
                              boatId,
                              boatSeats:
                                  boats.find((boat) => boat.id === boatId)
                                      ?.seats ?? null,
                          }
                        : item,
                ),
            }))
            .put(
                assign.url({ facility: slot.facilityId }),
                {
                    date,
                    starts_at: slot.startsAt,
                    ends_at: slot.endsAt,
                    facility_guide_id: guideId,
                    facility_boat_id: boatId,
                },
                { preserveScroll: true },
            );

    const people = slots.reduce((sum, slot) => sum + slot.booked, 0);
    const booked = slots.filter((slot) => slot.bookings > 0).length;

    return (
        <>
            <Head title="Activities" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Activities"
                    description={
                        slots.length === 0
                            ? 'Activity sessions of the day, with their guide and boat.'
                            : `${booked} of ${slots.length} ${slots.length === 1 ? 'session' : 'sessions'} booked · ${people} ${people === 1 ? 'person' : 'people'}`
                    }
                    actions={
                        <div className="flex items-center gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                aria-label="Previous day"
                                onClick={() => visit(shiftDay(date, -1))}
                            >
                                <ChevronLeft />
                            </Button>
                            <DatePicker
                                value={date}
                                onChange={(next) => next && visit(next)}
                                className="w-auto"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                aria-label="Next day"
                                onClick={() => visit(shiftDay(date, 1))}
                            >
                                <ChevronRight />
                            </Button>
                            {date !== today && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => visit(today)}
                                >
                                    Today
                                </Button>
                            )}
                        </div>
                    }
                />

                {slots.length === 0 ? (
                    <EmptyState
                        icon={Tent}
                        title="No activity sessions on this day."
                        description="Activities are Person facilities. Add sessions to them in Settings › Facilities, or book one for a guest."
                    />
                ) : (
                    <div className="overflow-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Time</TableHead>
                                    <TableHead>Activity</TableHead>
                                    <TableHead className="text-right">
                                        Booked
                                    </TableHead>
                                    <TableHead>Guide</TableHead>
                                    <TableHead>Boat</TableHead>
                                    {canViewList && (
                                        <TableHead className="w-12">
                                            <span className="sr-only">
                                                Participants
                                            </span>
                                        </TableHead>
                                    )}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {slots.map((slot) => {
                                    const full = slot.booked > slot.capacity;
                                    const overBoat =
                                        slot.boatSeats !== null &&
                                        slot.booked > slot.boatSeats;

                                    return (
                                        <TableRow key={slotKey(slot)}>
                                            <TableCell className="font-medium tabular-nums">
                                                {slot.startsAt}–{slot.endsAt}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    {slot.facility}
                                                    {!slot.isSession && (
                                                        <Badge variant="outline">
                                                            Other time
                                                        </Badge>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <div className="flex flex-col items-end">
                                                    <span
                                                        className={
                                                            full
                                                                ? 'font-medium text-warning tabular-nums'
                                                                : 'tabular-nums'
                                                        }
                                                    >
                                                        {slot.booked} /{' '}
                                                        {slot.capacity}{' '}
                                                        {slot.unit}
                                                    </span>
                                                    {overBoat && (
                                                        <span className="text-xs text-warning">
                                                            Boat seats{' '}
                                                            {slot.boatSeats}
                                                        </span>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                {canAssign ? (
                                                    <EnumSelect
                                                        aria-label={`Guide for ${slot.facility} ${slot.startsAt}`}
                                                        className="w-44"
                                                        options={pickOptions(
                                                            guideItems,
                                                            slot.guideId,
                                                        )}
                                                        value={
                                                            slot.guideId ===
                                                            null
                                                                ? NOT_SET
                                                                : String(
                                                                      slot.guideId,
                                                                  )
                                                        }
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            save(
                                                                slot,
                                                                value ===
                                                                    NOT_SET
                                                                    ? null
                                                                    : Number(
                                                                          value,
                                                                      ),
                                                                slot.boatId,
                                                            )
                                                        }
                                                    />
                                                ) : (
                                                    nameOf(
                                                        guideItems,
                                                        slot.guideId,
                                                    )
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {canAssign ? (
                                                    <EnumSelect
                                                        aria-label={`Boat for ${slot.facility} ${slot.startsAt}`}
                                                        className="w-48"
                                                        options={pickOptions(
                                                            boatItems,
                                                            slot.boatId,
                                                        )}
                                                        value={
                                                            slot.boatId === null
                                                                ? NOT_SET
                                                                : String(
                                                                      slot.boatId,
                                                                  )
                                                        }
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            save(
                                                                slot,
                                                                slot.guideId,
                                                                value ===
                                                                    NOT_SET
                                                                    ? null
                                                                    : Number(
                                                                          value,
                                                                      ),
                                                            )
                                                        }
                                                    />
                                                ) : (
                                                    nameOf(
                                                        boatItems,
                                                        slot.boatId,
                                                    )
                                                )}
                                            </TableCell>
                                            {canViewList && (
                                                <TableCell>
                                                    {slot.bookings > 0 && (
                                                        <Link
                                                            href={facilityBookings.url(
                                                                undefined,
                                                                {
                                                                    query: {
                                                                        from: date,
                                                                        to: date,
                                                                        facilities:
                                                                            String(
                                                                                slot.facilityId,
                                                                            ),
                                                                    },
                                                                },
                                                            )}
                                                            className={buttonVariants(
                                                                {
                                                                    variant:
                                                                        'ghost',
                                                                    size: 'icon',
                                                                },
                                                            )}
                                                            aria-label={`Participants of ${slot.facility} ${slot.startsAt}`}
                                                        >
                                                            <ListChecks />
                                                        </Link>
                                                    )}
                                                </TableCell>
                                            )}
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </div>
        </>
    );
}

ActivitiesIndex.layout = {
    breadcrumbs: [{ title: 'Activities', href: index() }],
};
