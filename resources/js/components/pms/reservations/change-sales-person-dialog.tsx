import { useForm } from '@inertiajs/react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { RecordSelectField } from '@/components/pms/reservations/record-select-field';
import {
    KEEP_OPTIONS,
    useFormOptions,
} from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { update } from '@/routes/reservations/sales-person';

type Reservation = App.Data.Reservations.ReservationData;
type SalesPerson = App.Data.Reservations.SalesPersonOptionData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservation: Reservation;
    salesPeople?: SalesPerson[];
};

function ChangeSalesPersonForm({
    open,
    onOpenChange,
    reservation,
    salesPeople,
}: Props) {
    useFormOptions(open, salesPeople !== undefined, 'salesPeople');

    const form = useForm({
        sales_person_id: reservation.salesPersonId
            ? String(reservation.salesPersonId)
            : '',
    });
    const { data, errors } = form;

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
            title="Change sales person"
            description={reservation.reference}
            errors={errors}
            onSubmit={submit}
            submitLabel="Save sales person"
            processing={form.processing}
            loading={salesPeople === undefined}
        >
            <RecordSelectField
                id="change-sales_person_id"
                label="Sales person"
                value={data.sales_person_id}
                onValueChange={(value) =>
                    form.setData('sales_person_id', value)
                }
                records={salesPeople ?? []}
                current={
                    reservation.salesPersonId !== null
                        ? {
                              id: reservation.salesPersonId,
                              name: reservation.salesPersonName ?? '',
                          }
                        : null
                }
                error={errors.sales_person_id}
            />
        </FormOverlay>
    );
}

/**
 * Sets or clears the staff member who sold the booking. It works on closed reservations too. The staff list loads
 * when the Dialog opens.
 */
export function ChangeSalesPersonDialog(props: Props) {
    return (
        <ChangeSalesPersonForm key={useFreshOnOpen(props.open)} {...props} />
    );
}
