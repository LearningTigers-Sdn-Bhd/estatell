import { router, useForm } from '@inertiajs/react';
import { Bus, Pencil } from 'lucide-react';
import { useState } from 'react';
import { formatDate } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { transferPrice } from '@/components/pms/transfer-price';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { clear, set } from '@/routes/reservations/units/transfers';

type Row = App.Data.Transfers.UnitTransferRowData;
type Route = App.Data.Transfers.TransferRouteData;

/** The Type select's value for "no transfer": saving it removes the transfer. */
const NONE = '';

function TransferDialog(props: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    row: Row;
    routes: Route[];
    modes: EnumOption[];
    currency: App.Data.Property.CurrencyData | null;
}) {
    return <TransferForm key={useFreshOnOpen(props.open)} {...props} />;
}

function TransferForm({
    open,
    onOpenChange,
    reservationId,
    row,
    routes,
    modes,
    currency,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    row: Row;
    routes: Route[];
    modes: EnumOption[];
    currency: App.Data.Property.CurrencyData | null;
}) {
    const directionRoutes = routes.filter(
        (route) => route.direction === row.direction,
    );
    const form = useForm({
        mode: (row.mode ?? NONE) as string,
        transfer_route_id:
            row.routeId === null
                ? directionRoutes.length === 1
                    ? String(directionRoutes[0].id)
                    : ''
                : String(row.routeId),
        pickup_time: row.pickupTime ?? '',
        flight_number: row.flightNumber ?? '',
        bags: row.bags === null ? '' : String(row.bags),
        note: row.note ?? '',
    });
    const route = directionRoutes.find(
        (candidate) => String(candidate.id) === form.data.transfer_route_id,
    );
    const params = {
        reservation: reservationId,
        unit: row.unitId,
        direction: row.direction,
    };

    const submit = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        if (form.data.mode === NONE) {
            if (row.mode === null) {
                onOpenChange(false);

                return;
            }

            router.delete(clear.url(params), options);

            return;
        }

        form.put(set.url(params), options);
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title={`${row.directionLabel} · ${row.unitLabel}`}
            description={formatDate(row.date)}
            errors={form.errors}
            dirty={form.isDirty}
            onSubmit={submit}
            submitLabel={form.data.mode === NONE ? 'Save' : 'Save transfer'}
            processing={form.processing}
        >
            <Field data-invalid={!!form.errors.mode}>
                <FieldLabel htmlFor="transfer-mode">Type</FieldLabel>
                <EnumSelect
                    id="transfer-mode"
                    name="mode"
                    options={[
                        { value: NONE, label: 'No transfer' },
                        ...modes.map((mode) => ({
                            ...mode,
                            description: transferPrice(
                                route,
                                mode.value,
                                currency,
                            ),
                        })),
                    ]}
                    value={form.data.mode}
                    onValueChange={(value) => form.setData('mode', value)}
                    aria-invalid={!!form.errors.mode}
                />
                <FieldError>{form.errors.mode}</FieldError>
            </Field>
            {form.data.mode !== NONE && (
                <>
                    <Field data-invalid={!!form.errors.transfer_route_id}>
                        <FieldLabel htmlFor="transfer-route">Route</FieldLabel>
                        <EnumSelect
                            id="transfer-route"
                            name="transfer_route_id"
                            placeholder="Pick a route"
                            options={directionRoutes.map((candidate) => ({
                                value: String(candidate.id),
                                label: candidate.name,
                                description: candidate.place,
                            }))}
                            value={form.data.transfer_route_id}
                            onValueChange={(value) =>
                                form.setData('transfer_route_id', value)
                            }
                            aria-invalid={!!form.errors.transfer_route_id}
                        />
                        {directionRoutes.length === 0 && (
                            <FieldDescription>
                                No routes for this direction yet. Add one in
                                Settings, Transfers.
                            </FieldDescription>
                        )}
                        <FieldError>{form.errors.transfer_route_id}</FieldError>
                    </Field>
                    <div className="grid grid-cols-2 gap-4">
                        <Field data-invalid={!!form.errors.pickup_time}>
                            <FieldLabel htmlFor="transfer-time">
                                Pick-up time
                            </FieldLabel>
                            <Input
                                id="transfer-time"
                                type="time"
                                value={form.data.pickup_time}
                                onChange={(event) =>
                                    form.setData(
                                        'pickup_time',
                                        event.target.value,
                                    )
                                }
                                aria-invalid={!!form.errors.pickup_time}
                            />
                            <FieldError>{form.errors.pickup_time}</FieldError>
                        </Field>
                        <Field data-invalid={!!form.errors.flight_number}>
                            <FieldLabel htmlFor="transfer-flight">
                                Flight (optional)
                            </FieldLabel>
                            <Input
                                id="transfer-flight"
                                maxLength={10}
                                placeholder="e.g. AK 6231"
                                value={form.data.flight_number}
                                onChange={(event) =>
                                    form.setData(
                                        'flight_number',
                                        event.target.value,
                                    )
                                }
                                aria-invalid={!!form.errors.flight_number}
                            />
                            <FieldError>{form.errors.flight_number}</FieldError>
                        </Field>
                        <Field data-invalid={!!form.errors.bags}>
                            <FieldLabel htmlFor="transfer-bags">
                                Bags (optional)
                            </FieldLabel>
                            <Input
                                id="transfer-bags"
                                type="number"
                                inputMode="numeric"
                                min={0}
                                max={99}
                                value={form.data.bags}
                                onChange={(event) =>
                                    form.setData('bags', event.target.value)
                                }
                                aria-invalid={!!form.errors.bags}
                            />
                            <FieldError>{form.errors.bags}</FieldError>
                        </Field>
                    </div>
                    <Field data-invalid={!!form.errors.note}>
                        <FieldLabel htmlFor="transfer-note">
                            Note (optional)
                        </FieldLabel>
                        <Input
                            id="transfer-note"
                            maxLength={200}
                            placeholder="e.g. 2 surfboards"
                            value={form.data.note}
                            onChange={(event) =>
                                form.setData('note', event.target.value)
                            }
                            aria-invalid={!!form.errors.note}
                        />
                        <FieldError>{form.errors.note}</FieldError>
                    </Field>
                </>
            )}
        </FormOverlay>
    );
}

/**
 * The reservation page Transfers tab: how each room travels in and out by road. A row's edit button opens the transfer
 * Dialog; "No transfer" removes it. See docs/35-transfers/01-land-transfers.md#reservation-transfers-tab.
 */
export function ReservationTransfersTab({
    reservationId,
    rows,
    routes,
    modes,
    currency,
}: {
    reservationId: number;
    rows: Row[];
    routes: Route[];
    modes: EnumOption[];
    currency: App.Data.Property.CurrencyData | null;
}) {
    const [editing, setEditing] = useState<{ open: boolean; row: Row | null }>({
        open: false,
        row: null,
    });

    if (rows.length === 0) {
        return (
            <EmptyState
                icon={Bus}
                title="No rooms to transfer."
                description="Cancelled and no-show rooms have no transfers."
            />
        );
    }

    return (
        <>
            <div className="overflow-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Room</TableHead>
                            <TableHead>Direction</TableHead>
                            <TableHead>Type</TableHead>
                            <TableHead>Route</TableHead>
                            <TableHead>Pick-up</TableHead>
                            <TableHead>Flight</TableHead>
                            <TableHead>Vehicle · Driver</TableHead>
                            <TableHead className="w-12">
                                <span className="sr-only">Edit</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row) => (
                            <TableRow key={`${row.unitId}-${row.direction}`}>
                                <TableCell className="font-medium">
                                    {row.unitLabel}
                                </TableCell>
                                <TableCell>
                                    <div className="flex flex-col">
                                        <span>{row.directionLabel}</span>
                                        <span className="text-xs text-muted-foreground">
                                            {formatDate(row.date)}
                                        </span>
                                    </div>
                                </TableCell>
                                <TableCell
                                    className={
                                        row.mode === null
                                            ? 'text-muted-foreground'
                                            : undefined
                                    }
                                >
                                    {row.modeLabel ?? 'Not set'}
                                </TableCell>
                                <TableCell>
                                    <div className="flex flex-col">
                                        <span>{row.routeName ?? '—'}</span>
                                        {row.note && (
                                            <span className="text-xs text-muted-foreground">
                                                {row.note}
                                            </span>
                                        )}
                                    </div>
                                </TableCell>
                                <TableCell className="tabular-nums">
                                    {row.pickupTime ?? '—'}
                                </TableCell>
                                <TableCell>{row.flightNumber ?? '—'}</TableCell>
                                <TableCell>
                                    {[row.vehicle, row.driver]
                                        .filter(Boolean)
                                        .join(' · ') || '—'}
                                </TableCell>
                                <TableCell>
                                    {row.canEdit && (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Change ${row.directionLabel.toLowerCase()} of ${row.unitLabel}`}
                                            onClick={() =>
                                                setEditing({ open: true, row })
                                            }
                                        >
                                            <Pencil />
                                        </Button>
                                    )}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>

            {editing.row !== null && (
                <TransferDialog
                    open={editing.open}
                    onOpenChange={(open) =>
                        setEditing((current) => ({ ...current, open }))
                    }
                    reservationId={reservationId}
                    row={editing.row}
                    routes={routes}
                    modes={modes}
                    currency={currency}
                />
            )}
        </>
    );
}
