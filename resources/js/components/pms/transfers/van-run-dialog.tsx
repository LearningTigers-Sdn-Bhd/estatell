import { useForm } from '@inertiajs/react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { run } from '@/routes/transfers';

/**
 * Put on a van: the picked transfers get one vehicle and driver and, when a time is given, one pick-up time, so they
 * show as one run on the Vans board. See docs/35-transfers/01-land-transfers.md#vans-board.
 */
export function VanRunDialog({
    open,
    onOpenChange,
    transferIds,
    pax,
    seatsOf,
    vehicleOptions,
    driverOptions,
    onDone,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    transferIds: number[];
    pax: number;
    seatsOf: (vehicleId: number) => number | null;
    vehicleOptions: EnumOption[];
    driverOptions: EnumOption[];
    onDone: () => void;
}) {
    const form = useForm({
        transfer_ids: transferIds,
        transfer_vehicle_id: '',
        transfer_driver_id: '',
        pickup_time: '',
    });
    const seats =
        form.data.transfer_vehicle_id === ''
            ? null
            : seatsOf(Number(form.data.transfer_vehicle_id));

    const submit = () => {
        form.transform((data) => ({
            transfer_ids: data.transfer_ids,
            transfer_vehicle_id:
                data.transfer_vehicle_id === ''
                    ? null
                    : Number(data.transfer_vehicle_id),
            transfer_driver_id:
                data.transfer_driver_id === ''
                    ? null
                    : Number(data.transfer_driver_id),
            pickup_time: data.pickup_time === '' ? null : data.pickup_time,
        }));
        form.put(run.url(), {
            preserveScroll: true,
            onSuccess: () => {
                onOpenChange(false);
                onDone();
            },
        });
    };

    return (
        <FormOverlay
            variant="dialog"
            size="sm"
            open={open}
            onOpenChange={onOpenChange}
            title="Put on a van"
            description={`${transferIds.length} ${transferIds.length === 1 ? 'transfer' : 'transfers'} · ${pax} pax`}
            errors={form.errors}
            dirty={form.isDirty}
            onSubmit={submit}
            submitLabel="Put on van"
            processing={form.processing}
        >
            <Field data-invalid={!!form.errors.transfer_vehicle_id}>
                <FieldLabel htmlFor="run-vehicle">Vehicle</FieldLabel>
                <EnumSelect
                    id="run-vehicle"
                    options={vehicleOptions}
                    value={form.data.transfer_vehicle_id}
                    onValueChange={(value) =>
                        form.setData('transfer_vehicle_id', value)
                    }
                />
                {seats !== null && pax > seats && (
                    <FieldDescription className="text-warning">
                        {pax} pax on {seats} seats.
                    </FieldDescription>
                )}
                <FieldError>{form.errors.transfer_vehicle_id}</FieldError>
            </Field>
            <Field data-invalid={!!form.errors.transfer_driver_id}>
                <FieldLabel htmlFor="run-driver">Driver</FieldLabel>
                <EnumSelect
                    id="run-driver"
                    options={driverOptions}
                    value={form.data.transfer_driver_id}
                    onValueChange={(value) =>
                        form.setData('transfer_driver_id', value)
                    }
                />
                <FieldError>{form.errors.transfer_driver_id}</FieldError>
            </Field>
            <Field data-invalid={!!form.errors.pickup_time}>
                <FieldLabel htmlFor="run-time">
                    Pick-up time (optional)
                </FieldLabel>
                <Input
                    id="run-time"
                    type="time"
                    value={form.data.pickup_time}
                    onChange={(event) =>
                        form.setData('pickup_time', event.target.value)
                    }
                    aria-invalid={!!form.errors.pickup_time}
                />
                <FieldDescription>
                    One time for everyone on the run. Leave empty to keep each
                    transfer&apos;s own time. The charge does not change.
                </FieldDescription>
                <FieldError>{form.errors.pickup_time}</FieldError>
            </Field>
        </FormOverlay>
    );
}
