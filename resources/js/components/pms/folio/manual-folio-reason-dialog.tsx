import { useForm } from '@inertiajs/react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import { start } from '@/routes/reservations/folios/manual';

const OTHER: App.Enums.ManualFolioReason = 'other';

/** The reason as the dialog holds it: a picked option, and the typed details for Other. */
export type ManualReason = { option: string; details: string };

/** The reason the batch keeps: the typed details for Other, else the option's label. */
export function reasonText(
    reason: ManualReason,
    options: EnumOption[],
): string {
    return reason.option === OTHER
        ? reason.details.trim()
        : (options.find((one) => one.value === reason.option)?.label ?? '');
}

/** The dialog's choice for a reason the server sent back as text: the option with that label, else Other with it. */
export function reasonFromText(
    text: string | null,
    options: EnumOption[],
): ManualReason {
    if (!text) {
        return { option: '', details: '' };
    }

    const option = options.find(
        (one) => one.value !== OTHER && one.label === text,
    );

    return option
        ? { option: option.value, details: '' }
        : { option: OTHER, details: text };
}

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The reasons to pick from (`ManualFolioReason::options()`). */
    options: EnumOption[];
    /** The reason picked before, when the dialog edits it. */
    initial?: ManualReason;
    /** Called with the reason on Continue. Without it the dialog starts the manual page for `folio` on the server. */
    onConfirm?: (reason: ManualReason) => void;
    reservationId: number;
    folioId: number;
    /** The date lines post on and the folio they go to, for the sentence under the title. */
    businessDate?: string | null;
};

function ReasonForm({
    open,
    onOpenChange,
    options,
    initial = { option: '', details: '' },
    onConfirm,
    reservationId,
    folioId,
    businessDate,
}: Props) {
    const form = useForm({
        reason_option: initial.option,
        reason_details: initial.details,
    });
    const errors = form.errors as Partial<Record<string, string>>;
    const isOther = form.data.reason_option === OTHER;
    const ready =
        form.data.reason_option !== '' &&
        (!isOther || form.data.reason_details.trim() !== '');

    const submit = () => {
        if (onConfirm) {
            onConfirm({
                option: form.data.reason_option,
                details: isOther ? form.data.reason_details.trim() : '',
            });
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
            submitDisabled={!ready}
        >
            <Field data-invalid={!!errors.reason_option}>
                <FieldLabel htmlFor="manual-reason">Reason</FieldLabel>
                <EnumSelect
                    id="manual-reason"
                    name="reason_option"
                    options={options}
                    placeholder="Choose a reason"
                    value={form.data.reason_option}
                    onValueChange={(value) =>
                        form.setData('reason_option', value)
                    }
                    aria-invalid={!!errors.reason_option}
                />
                <FieldError>{errors.reason_option}</FieldError>
            </Field>
            {isOther && (
                <Field data-invalid={!!errors.reason_details}>
                    <FieldLabel htmlFor="manual-reason-details">
                        Reason details
                    </FieldLabel>
                    <Textarea
                        id="manual-reason-details"
                        name="reason_details"
                        rows={3}
                        maxLength={500}
                        placeholder="e.g. Guest paid cash to the night staff"
                        value={form.data.reason_details}
                        onChange={(event) =>
                            form.setData('reason_details', event.target.value)
                        }
                        aria-invalid={!!errors.reason_details}
                        autoFocus
                    />
                    <FieldError>{errors.reason_details}</FieldError>
                </Field>
            )}
        </FormOverlay>
    );
}

/**
 * Asks why the folio is managed by hand, then opens the manual folio page. The reason is picked from a list; Other
 * shows a field for the details. On the page the same Dialog edits the reason. Nothing is written until the first
 * post.
 */
export function ManualFolioReasonDialog(props: Props) {
    return <ReasonForm key={useFreshOnOpen(props.open)} {...props} />;
}
