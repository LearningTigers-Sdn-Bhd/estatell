import { Head, Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Tag } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { PageHeader } from '@/components/pms/page-header';
import { NewRatePlanSheet } from '@/components/pms/rate-plans/new-rate-plan-sheet';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
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
type Option = { value: string; label: string };

function ArchiveRatePlan({ ratePlan }: { ratePlan: RatePlan }) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | undefined>();

    return (
        <AlertDialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                setError(undefined);
            }}
        >
            <AlertDialogTrigger render={<Button variant="ghost" size="sm" />}>
                Archive
            </AlertDialogTrigger>
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
                                    onSuccess: () => setOpen(false),
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

export default function RatePlansIndex({
    ratePlans,
    pricingBases,
    roomTypes,
}: {
    ratePlans: RatePlan[];
    pricingBases: Option[];
    roomTypes: App.Data.Rates.NewRatePlanRoomTypeData[];
}) {
    const active = ratePlans.filter((ratePlan) => !ratePlan.archived);
    const archived = ratePlans.filter((ratePlan) => ratePlan.archived);
    const basisLabel = (value: string) =>
        pricingBases.find((option) => option.value === value)?.label ?? value;

    const move = (from: number, to: number) => {
        const order = active.map((ratePlan) => ratePlan.id);
        order.splice(to, 0, ...order.splice(from, 1));

        router.put(reorder.url(), { order }, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Rate plans" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Rate plans"
                    description="How room types are priced and sold. Prices are set per date on the rate calendar."
                    actions={
                        <NewRatePlanSheet
                            pricingBases={pricingBases}
                            roomTypes={roomTypes}
                        />
                    }
                />

                {active.length === 0 ? (
                    <EmptyState
                        icon={Tag}
                        title="No rate plans yet"
                        description="A rate plan is how you sell a room type, such as Basic Rate or Breakfast."
                        action={
                            <NewRatePlanSheet
                                pricingBases={pricingBases}
                                roomTypes={roomTypes}
                            />
                        }
                    />
                ) : (
                    <div className="overflow-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Sells</TableHead>
                                    <TableHead>Pricing</TableHead>
                                    <TableHead>Room types</TableHead>
                                    <TableHead>
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {active.map((ratePlan, position) => (
                                    <TableRow key={ratePlan.id}>
                                        <TableCell className="font-medium">
                                            {ratePlan.name}{' '}
                                            {ratePlan.checkChildPrices && (
                                                <Badge variant="outline">
                                                    Check child prices
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell>{ratePlan.family}</TableCell>
                                        <TableCell>
                                            {basisLabel(ratePlan.pricingBasis)}
                                        </TableCell>
                                        <TableCell className="whitespace-normal">
                                            {ratePlan.roomTypeNames.length >
                                            0 ? (
                                                ratePlan.roomTypeNames.join(
                                                    ', ',
                                                )
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    None yet
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex justify-end gap-1">
                                                <Button
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    aria-label={`Move ${ratePlan.name} up`}
                                                    disabled={position === 0}
                                                    onClick={() =>
                                                        move(
                                                            position,
                                                            position - 1,
                                                        )
                                                    }
                                                >
                                                    <ArrowUp />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    aria-label={`Move ${ratePlan.name} down`}
                                                    disabled={
                                                        position ===
                                                        active.length - 1
                                                    }
                                                    onClick={() =>
                                                        move(
                                                            position,
                                                            position + 1,
                                                        )
                                                    }
                                                >
                                                    <ArrowDown />
                                                </Button>
                                                <Link
                                                    href={edit.url({
                                                        ratePlan: ratePlan.id,
                                                    })}
                                                    className={buttonVariants({
                                                        variant: 'ghost',
                                                        size: 'sm',
                                                    })}
                                                >
                                                    Edit
                                                </Link>
                                                <ArchiveRatePlan
                                                    ratePlan={ratePlan}
                                                />
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}

                {archived.length > 0 && (
                    <section className="flex flex-col gap-2">
                        <h2 className="font-heading text-sm font-medium">
                            Archived
                        </h2>
                        <div className="overflow-auto rounded-lg border">
                            <Table>
                                <TableBody>
                                    {archived.map((ratePlan) => (
                                        <TableRow key={ratePlan.id}>
                                            <TableCell className="font-medium">
                                                {ratePlan.name}{' '}
                                                <Badge variant="secondary">
                                                    Archived
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                {basisLabel(
                                                    ratePlan.pricingBasis,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        router.post(
                                                            restore.url({
                                                                ratePlan:
                                                                    ratePlan.id,
                                                            }),
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Restore
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </section>
                )}
            </div>
        </>
    );
}

RatePlansIndex.layout = {
    breadcrumbs: [{ title: 'Rate plans', href: index() }],
};
