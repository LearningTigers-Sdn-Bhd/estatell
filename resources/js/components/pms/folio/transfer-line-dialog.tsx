import { useForm } from '@inertiajs/react';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormError } from '@/components/pms/form-error';
import { KEEP_OPTIONS } from '@/components/pms/use-form-options';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/reservations/folios/transfers';

type Row = App.Data.Folio.FolioRowData;
type Option = App.Data.Folio.FolioOptionData;

function TransferForm({
    reservationId,
    folioId,
    row,
    targets,
    onDone,
}: {
    reservationId: number;
    folioId: number;
    row: Row;
    targets: Option[];
    onDone: () => void;
}) {
    const form = useForm({ target_folio_id: '' });
    const { data, errors } = form;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((values) => ({
            line_ids: [row.lineId],
            target_folio_id: values.target_folio_id,
        }));
        form.post(store.url({ reservation: reservationId, folio: folioId }), {
            preserveScroll: true,
            except: KEEP_OPTIONS,
            onSuccess: onDone,
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <FieldGroup>
                <FormError errors={errors} />
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
            </FieldGroup>
            <DialogFooter>
                <DialogClose render={<Button variant="outline" />}>
                    Cancel
                </DialogClose>
                <Button
                    type="submit"
                    disabled={form.processing || data.target_folio_id === ''}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Move charge
                </Button>
            </DialogFooter>
        </form>
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
    return (
        <Dialog open={row !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Move {row?.description}</DialogTitle>
                    <DialogDescription>
                        The charge is reversed here and posted again on the
                        other folio, with its tax and discount lines.
                    </DialogDescription>
                </DialogHeader>
                {row !== null && (
                    <TransferForm
                        reservationId={reservationId}
                        folioId={folioId}
                        row={row}
                        targets={targets}
                        onDone={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}
