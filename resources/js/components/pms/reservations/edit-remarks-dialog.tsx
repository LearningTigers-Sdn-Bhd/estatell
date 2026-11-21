import { useForm } from '@inertiajs/react';
import { FormOverlay } from '@/components/pms/form-overlay';
import type { EnumOption } from '@/components/pms/enum-select';
import { RemarksFields } from '@/components/pms/reservations/remarks-fields';
import type { RemarksValue } from '@/components/pms/reservations/remarks-fields';
import { KEEP_OPTIONS } from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { update } from '@/routes/reservations/remarks';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservation: App.Data.Reservations.ReservationData;
    remarkKinds: EnumOption[];
};

function EditRemarksForm({
    open,
    onOpenChange,
    reservation,
    remarkKinds,
}: Props) {
    const form = useForm<{ remarks: RemarksValue }>({
        remarks: Object.fromEntries(
            reservation.remarks.map((remark) => [remark.kind, remark.body]),
        ),
    });

    const submit = () => {
        form.put(update.url({ reservation: reservation.id }), {
            preserveScroll: true,
            except: KEEP_OPTIONS,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title="Edit remarks"
            description={reservation.reference}
            errors={form.errors}
            dirty={form.isDirty}
            onSubmit={submit}
            submitLabel="Save remarks"
            processing={form.processing}
        >
            <RemarksFields
                idPrefix="edit-remarks"
                kinds={remarkKinds}
                value={form.data.remarks}
                onChange={(remarks) => form.setData('remarks', remarks)}
                errors={form.errors}
            />
        </FormOverlay>
    );
}

/**
 * Edits the housekeeping, dietary, package and payment remarks of the booking. It works on closed reservations too.
 * An empty box removes that remark.
 */
export function EditRemarksDialog(props: Props) {
    return <EditRemarksForm key={useFreshOnOpen(props.open)} {...props} />;
}
