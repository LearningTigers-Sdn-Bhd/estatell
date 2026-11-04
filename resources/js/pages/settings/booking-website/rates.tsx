import { Head } from '@inertiajs/react';
import { ExternalLink, Tag } from 'lucide-react';
import { BookingWebsiteShell } from '@/components/pms/booking-website/booking-website-shell';
import { EmptyState } from '@/components/pms/empty-state';
import { OnlineBookingSwitch } from '@/components/pms/rate-plans/online-booking-switch';
import { RoomTypePills } from '@/components/pms/rate-plans/room-type-pills';
import { buttonVariants } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { edit, index } from '@/routes/settings/rate-plans';

type RatePlan = App.Data.BookingEngine.BookingWebsiteRatePlanData;

function RatePlanRow({
    ratePlan,
    canManageRates,
}: {
    ratePlan: RatePlan;
    canManageRates: boolean;
}) {
    return (
        <TableRow>
            <TableCell className="w-24">
                <OnlineBookingSwitch ratePlan={ratePlan} />
            </TableCell>
            <TableCell className="font-medium">
                {canManageRates ? (
                    <a
                        href={edit.url({ ratePlan: ratePlan.id })}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex items-center gap-1.5 hover:underline"
                    >
                        {ratePlan.name}
                        <ExternalLink
                            aria-hidden
                            className="size-3.5 text-muted-foreground"
                        />
                        <span className="sr-only">(opens in a new tab)</span>
                    </a>
                ) : (
                    ratePlan.name
                )}
            </TableCell>
            <TableCell>
                <RoomTypePills names={ratePlan.roomTypeNames} />
            </TableCell>
        </TableRow>
    );
}

export default function BookingWebsiteRates({
    ratePlans,
    canManageRates,
}: {
    ratePlans: RatePlan[];
    canManageRates: boolean;
}) {
    return (
        <>
            <Head title="Booking website rates" />
            <BookingWebsiteShell
                description="Which rate plans guests can book online."
                activeTab="rates"
            >
                <div className="flex flex-col gap-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="flex max-w-2xl flex-col gap-1">
                            <h2 className="font-heading text-base font-semibold">
                                Online rate plans
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Turn on a rate plan to let guests book it on
                                your booking website. Guests see its name and
                                prices. Plans that are off, company-only or Day
                                use are never shown. Each switch saves at once.
                            </p>
                        </div>
                        {canManageRates && (
                            <a
                                href={index.url()}
                                target="_blank"
                                rel="noopener noreferrer"
                                className={buttonVariants({
                                    variant: 'outline',
                                })}
                            >
                                Manage rate plans
                                <ExternalLink
                                    aria-hidden
                                    data-icon="inline-end"
                                />
                                <span className="sr-only">
                                    (opens in a new tab)
                                </span>
                            </a>
                        )}
                    </div>

                    {ratePlans.length === 0 ? (
                        <EmptyState
                            icon={Tag}
                            title="No rate plans to list"
                            description="Add an active rate plan open to everyone in Settings, Rates."
                        />
                    ) : (
                        <div className="overflow-hidden rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Bookable</TableHead>
                                        <TableHead>Rate plan</TableHead>
                                        <TableHead>Room types</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {ratePlans.map((ratePlan) => (
                                        <RatePlanRow
                                            key={ratePlan.id}
                                            ratePlan={ratePlan}
                                            canManageRates={canManageRates}
                                        />
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </div>
            </BookingWebsiteShell>
        </>
    );
}
