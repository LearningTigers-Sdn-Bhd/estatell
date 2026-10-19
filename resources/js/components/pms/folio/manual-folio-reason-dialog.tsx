import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import { start } from '@/routes/reservations/folios/manual';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The reason typed before, when the dialog edits it. */
    initial?: string;
    /** Called with the reason on Continue. Without it the dialog starts the manual page for `folio` on the server. */
    onConfirm?: (reason: string) => void;
    reservationId: number;
    folioId: number;
    /** The date lines post on and the folio they go to, for the sentence under the title. */
    businessDate?: string | null;
};

function ReasonForm({
    open,
    onOpenChange,
    initial = '',
    onConfirm,
    reservationId,
    folioId,
    businessDate,
}: Props) {
    const form = useForm({ reason: initial });
    const [local, setLocal] = useState(initial);
    const reason = onConfirm ? local : form.data.reason;
    const errors = form.errors as Partial<Record<string, string>>;

    const submit = () => {
        if (onConfirm) {
            onConfirm(local.trim());
            onOpenChange(false);

            return;
        }

        form.post(start.url({ reservation: reservationId, folio: folioId }), {
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title="Manage folio manually"
            description={`Lines you add post today${businessDate ? ` (${businessDate})` : ''} and keep the date you enter as their service date.`}
            errors={errors}
            onSubmit={submit}
            submitLabel="Continue"
            processing={form.processing}
            submitDisabled={reason.trim() === ''}
        >
            <Field data-invalid={!!errors.reason}>
                <FieldLabel htmlFor="manual-reason">Reason</FieldLabel>
                <Textarea
                    id="manual-reason"
                    name="reason"
                    rows={3}
                    maxLength={500}
                    placeholder="e.g. Entering charges from the paper folio"
                    value={reason}
                    onChange={(event) => {
                        setLocal(event.target.value);
                        form.setData('reason', event.target.value);
                    }}
                    aria-invalid={!!errors.reason}
                />
                <FieldError>{errors.reason}</FieldError>
            </Field>
        </FormOverlay>
    );
}

/**
 * Asks why the folio is managed by hand, then opens the manual folio page. On the page the same Dialog edits the
 * reason. Nothing is written until the first post.
 */
export function ManualFolioReasonDialog(props: Props) {
    return <ReasonForm key={useFreshOnOpen(props.open)} {...props} />;
}
