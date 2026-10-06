import { useForm } from '@inertiajs/react';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { KEEP_OPTIONS } from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { useRetainedValue } from '@/components/pms/use-retained-value';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { store } from '@/routes/reservations/folios/transfers';

type Row = App.Data.Folio.FolioRowData;
type Option = App.Data.Folio.FolioOptionData;

type FormProps = {
    open: boolean;
    onClose: () => void;
    reservationId: number;
    folioId: number;
    row: Row;
    targets: Option[];
};

function TransferForm({
    open,
    onClose,
    reservationId,
    folioId,
    row,
    targets,
}: FormProps) {
    const form = useForm({ target_folio_id: '' });
    const { data, errors } = form;

    const submit = () => {
        form.transform((values) => ({
            line_ids: [row.lineId],
            target_folio_id: values.target_folio_id,
        }));
        form.post(store.url({ reservation: reservationId, folio: folioId }), {
            preserveScroll: true,
            except: KEEP_OPTIONS,
            onSuccess: onClose,
        });
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={(next) => !next && onClose()}
            title={`Move ${row.description}`}
            description="The charge is reversed here and posted again on the other folio, with its tax and discount lines."
            errors={errors}
            onSubmit={submit}
            submitLabel="Move charge"
            processing={form.processing}
            submitDisabled={data.target_folio_id === ''}
        >
            <Field data-invalid={!!errors.target_folio_id}>
                <FieldLabel htmlFor="transfer-target">Move to</FieldLabel>
                <EnumSelect
                    id="transfer-target"
                    options={targets.map((one) => ({
                        value: String(one.id),
                        label: one.label,
                    }))}
                    placeholder="Choose a folio"
                    value={data.target_folio_id}
                    onValueChange={(value) =>
                        form.setData('target_folio_id', value)
                    }
                    aria-invalid={!!errors.target_folio_id}
                />
                <FieldError>{errors.target_folio_id}</FieldError>
            </Field>
        </FormOverlay>
    );
}

/**
 * Moves one charge, with its tax and discount lines, to another Open folio of the reservation.
 */
export function TransferLineDialog({
    reservationId,
    folioId,
    row,
    targets,
    onClose,
}: {
    reservationId: number;
    folioId: number;
    row: Row | null;
    targets: Option[];
    onClose: () => void;
}) {
    const open = row !== null;
    const kept = useRetainedValue(row);
    const fresh = useFreshOnOpen(open);

    if (kept === null) {
        return null;
    }

    return (
        <TransferForm
            key={`${kept.lineId}-${fresh}`}
            open={open}
            onClose={onClose}
            reservationId={reservationId}
            folioId={folioId}
            row={kept}
            targets={targets}
        />
    );
}
