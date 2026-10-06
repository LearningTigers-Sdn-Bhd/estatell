import { useForm } from '@inertiajs/react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { GuestPicker } from '@/components/pms/guest-picker';
import { KEEP_OPTIONS } from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { store } from '@/routes/reservations/folios/third-party';

type Guest = App.Data.Guests.GuestOptionData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
};

function ThirdPartyForm({ open, onOpenChange, reservationId }: Props) {
    const form = useForm<{ payer: Guest | null }>({ payer: null });
    const { data } = form;
    const errors = form.errors as Partial<Record<string, string>>;

    const submit = () => {
        form.transform((values) => ({
            payer_guest_id: values.payer?.id ?? null,
        }));
        form.post(store.url({ reservation: reservationId }), {
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
            title="New third-party folio"
            description="A folio with its own payer, for example a travel agent that pays the diving charges. Add a routing rule to send charges to it."
            errors={errors}
            onSubmit={submit}
            submitLabel="Create folio"
            processing={form.processing}
            submitDisabled={data.payer === null}
        >
            <Field data-invalid={!!errors.payer_guest_id}>
                <FieldLabel htmlFor="third-party-payer">Payer</FieldLabel>
                <GuestPicker
                    id="third-party-payer"
                    value={data.payer}
                    onChange={(guest) => form.setData('payer', guest)}
                    invalid={!!errors.payer_guest_id}
                />
                <FieldDescription>
                    A guest of the property. A company payer comes with
                    Companies.
                </FieldDescription>
                <FieldError>{errors.payer_guest_id}</FieldError>
            </Field>
        </FormOverlay>
    );
}

/**
 * Creates a third-party folio paid by a guest. The new folio opens on the folio tab.
 */
export function ThirdPartyFolioDialog(props: Props) {
    return <ThirdPartyForm key={useFreshOnOpen(props.open)} {...props} />;
}
