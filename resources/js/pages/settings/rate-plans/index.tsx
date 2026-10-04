import { Head, Link, router } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    ArrowDown,
    ArrowUp,
    BedDouble,
    BedSingle,
    Ellipsis,
    GitBranch,
    GitBranchPlus,
    Package,
    Pencil,
    Tag,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FilterBar } from '@/components/pms/filter-bar';
import { PageHeader } from '@/components/pms/page-header';
import { NewDerivedRatePlanSheet } from '@/components/pms/rate-plans/new-derived-rate-plan-sheet';
import { NewRatePlanSheet } from '@/components/pms/rate-plans/new-rate-plan-sheet';
import { RoomTypePills } from '@/components/pms/rate-plans/room-type-pills';
import { StatusBadge } from '@/components/pms/status-badge';
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
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { FieldError } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    archive,
    edit,
    index,
    reorder,
    restore,
} from '@/routes/settings/rate-plans';

type RatePlan = App.Data.Rates.RatePlanData;

const CHECK_CHILD_PRICES: App.Data.Shared.BadgeData = {
    value: 'check_child_prices',
    label: 'Check child prices',
    tone: 'warning',
};

const pricingIcons: Record<string, LucideIcon> = {
    per_room: BedDouble,
    per_guest: Users,
    per_bed: BedSingle,
    package: Package,
};

function ArchiveRatePlan({
    ratePlan,
    open,
    onOpenChange,
}: {
    ratePlan: RatePlan;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | undefined>();

    return (
        <AlertDialog
            open={open}
            onOpenChange={(next) => {
                onOpenChange(next);
                setError(undefined);
            }}
        >
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Archive {ratePlan.name}?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        It leaves the active list and cannot be used for new
                        bookings. You can restore it later.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <FieldError>{error}</FieldError>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <Button
                        variant="destructive"
                        disabled={processing}
                        focusableWhenDisabled
                        onClick={() =>
                            router.post(
                                archive.url({ ratePlan: ratePlan.id }),
                                {},
                                {
                                    preserveScroll: true,
                                    onStart: () => setProcessing(true),
                                    onFinish: () => setProcessing(false),
                                    onError: (errors) =>
                                        setError(errors.rate_plan),
                                    onSuccess: () => onOpenChange(false),
                                },
                            )
                        }
                    >
                        {processing && <Spinner data-icon="inline-start" />}
                        Archive
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

/** An active plan that does not follow another plan can be a parent. A package can have package followers. */
function canBeParent(ratePlan: RatePlan): boolean {
    return !ratePlan.archived && !ratePlan.isDerived;
}

function RatePlanActions({
    ratePlan,
    position,
    count,
    canReorder,
    onMove,
    onCreateDerived,
}: {
    ratePlan: RatePlan;
    position: number;
    count: number;
    canReorder: boolean;
    onMove: (from: number, to: number) => void;
    onCreateDerived: (ratePlan: RatePlan) => void;
}) {
    const [archiving, setArchiving] = useState(false);

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger
                    render={<Button variant="ghost" size="icon-sm" />}
                    aria-label={`Actions for ${ratePlan.name}`}
                >
                    <Ellipsis />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-44">
                    <DropdownMenuItem
                        render={
                            <Link href={edit.url({ ratePlan: ratePlan.id })} />
                        }
                    >
                        <Pencil />
                        Edit
                    </DropdownMenuItem>
                    {canBeParent(ratePlan) && (
                        <DropdownMenuItem
                            onClick={() => onCreateDerived(ratePlan)}
                        >
                            <GitBranchPlus />
                            {ratePlan.pricingBasis === 'package'
                                ? 'Create derived package'
                                : 'Create derived plan'}
                        </DropdownMenuItem>
                    )}
                    {canReorder && !ratePlan.archived && (
                        <>
                            <DropdownMenuItem
                                disabled={position === 0}
                                onClick={() => onMove(position, position - 1)}
                            >
                                <ArrowUp />
                                Move up
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                disabled={position === count - 1}
                                onClick={() => onMove(position, position + 1)}
                            >
                                <ArrowDown />
                                Move down
                            </DropdownMenuItem>
                        </>
                    )}
                    <DropdownMenuSeparator />
                    {ratePlan.archived ? (
                        <DropdownMenuItem
                            onClick={() =>
                                router.post(
                                    restore.url({ ratePlan: ratePlan.id }),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <ArchiveRestore />
                            Restore
                        </DropdownMenuItem>
                    ) : (
                        <DropdownMenuItem
                            variant="destructive"
                            onClick={() => setArchiving(true)}
                        >
                            <Archive />
                            Archive
                        </DropdownMenuItem>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>
            {!ratePlan.archived && (
                <ArchiveRatePlan
                    ratePlan={ratePlan}
                    open={archiving}
                    onOpenChange={setArchiving}
                />
            )}
        </>
    );
}

/** `active` by default, `''` for all. Read from and written to `?status=` without a request. */
function initialStatus(): string {
    const status = new URLSearchParams(window.location.search).get('status');

    return status === 'all' ? '' : (status ?? 'active');
}

function writeStatus(status: string) {
    const url = new URL(window.location.href);

    if (status === 'active') {
        url.searchParams.delete('status');
    } else {
        url.searchParams.set('status', status === '' ? 'all' : status);
    }

    window.history.replaceState(window.history.state, '', url);
}

export default function RatePlansIndex({
    ratePlans,
    statuses,
    pricingBases,
    packageUnits,
    roomTypes,
    derivations,
    currency,
    companies,
}: {
    ratePlans: RatePlan[];
    statuses: EnumOption[];
    pricingBases: EnumOption[];
    packageUnits: EnumOption[];
    roomTypes: App.Data.Rates.NewRatePlanRoomTypeData[];
    derivations: EnumOption[];
    currency: App.Enums.Currency;
    companies: App.Data.CityLedger.CompanyOptionData[];
}) {
    const [status, setStatus] = useState(initialStatus);
    const [search, setSearch] = useState('');
    const [derivingFrom, setDerivingFrom] = useState<RatePlan | null>(null);

    const active = ratePlans.filter((ratePlan) => !ratePlan.archived);
    const term = search.trim().toLowerCase();
    const visible = ratePlans.filter(
        (ratePlan) =>
            (status === '' || ratePlan.status.value === status) &&
            ratePlan.name.toLowerCase().includes(term),
    );
    const canReorder = status === 'active' && term === '';

    const changeStatus = (next: string) => {
        setStatus(next);
        writeStatus(next);
    };

    const clearFilters = () => {
        setSearch('');
        changeStatus('active');
    };

    const move = (from: number, to: number) => {
        const order = active.map((ratePlan) => ratePlan.id);
        order.splice(to, 0, ...order.splice(from, 1));

        router.put(reorder.url(), { order }, { preserveScroll: true });
    };

    const newRatePlan = (
        <NewRatePlanSheet
            pricingBases={pricingBases}
            packageUnits={packageUnits}
            roomTypes={roomTypes}
        />
    );

    return (
        <>
            <Head title="Rate plans" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Rate plans"
                    description="How room types are priced and sold. Prices are set per date on the rate calendar."
                    actions={newRatePlan}
                />

                {ratePlans.length === 0 ? (
                    <EmptyState
                        icon={Tag}
                        title="No rate plans yet"
                        description="A rate plan is how you sell a room type, such as Basic Rate or Breakfast."
                        action={newRatePlan}
                    />
                ) : (
                    <>
                        <FilterBar
                            search={search}
                            onSearchChange={setSearch}
                            searchPlaceholder="Search rate plans"
                        >
                            <EnumSelect
                                options={statuses}
                                value={status}
                                onValueChange={changeStatus}
                                allLabel="All"
                                aria-label="Status"
                                className="w-40"
                            />
                        </FilterBar>

                        <div className="overflow-auto rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Name</TableHead>
                                        <TableHead>Sells</TableHead>
                                        <TableHead>Pricing</TableHead>
                                        <TableHead>Room types</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {visible.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={6}>
                                                <EmptyState
                                                    icon={Tag}
                                                    title="No rate plans match these filters"
                                                    action={
                                                        <Button
                                                            variant="outline"
                                                            onClick={
                                                                clearFilters
                                                            }
                                                        >
                                                            Clear filters
                                                        </Button>
                                                    }
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        visible.map((ratePlan) => (
                                            <TableRow key={ratePlan.id}>
                                                <TableCell>
                                                    <div className="flex flex-col items-start gap-1">
                                                        <Link
                                                            href={edit.url({
                                                                ratePlan:
                                                                    ratePlan.id,
                                                            })}
                                                            className="font-medium hover:underline"
                                                        >
                                                            {ratePlan.name}
                                                        </Link>
                                                        {ratePlan.checkChildPrices && (
                                                            <StatusBadge
                                                                size="lg"
                                                                status={
                                                                    CHECK_CHILD_PRICES
                                                                }
                                                            />
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        size="lg"
                                                        status={{
                                                            value: ratePlan.family,
                                                            label: ratePlan.family,
                                                            tone: ratePlan.familyTone,
                                                        }}
                                                        dot={false}
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        size="lg"
                                                        status={{
                                                            value: ratePlan.pricingBasis,
                                                            label: ratePlan.pricingLabel,
                                                            tone: ratePlan.pricingTone,
                                                        }}
                                                        icon={
                                                            ratePlan.isDerived
                                                                ? GitBranch
                                                                : pricingIcons[
                                                                      ratePlan
                                                                          .pricingBasis
                                                                  ]
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <RoomTypePills
                                                        names={
                                                            ratePlan.roomTypeNames
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        size="lg"
                                                        status={ratePlan.status}
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex justify-end">
                                                        <RatePlanActions
                                                            ratePlan={ratePlan}
                                                            position={active.indexOf(
                                                                ratePlan,
                                                            )}
                                                            count={
                                                                active.length
                                                            }
                                                            canReorder={
                                                                canReorder
                                                            }
                                                            onMove={move}
                                                            onCreateDerived={
                                                                setDerivingFrom
                                                            }
                                                        />
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </>
                )}
            </div>
            {derivingFrom && (
                <NewDerivedRatePlanSheet
                    key={derivingFrom.id}
                    parent={derivingFrom}
                    roomTypes={roomTypes}
                    companies={companies}
                    derivations={derivations}
                    packageUnits={packageUnits}
                    currency={currency}
                    open
                    onOpenChange={(next) => !next && setDerivingFrom(null)}
                />
            )}
        </>
    );
}

RatePlansIndex.layout = {
    breadcrumbs: [{ title: 'Rate plans', href: index() }],
};
