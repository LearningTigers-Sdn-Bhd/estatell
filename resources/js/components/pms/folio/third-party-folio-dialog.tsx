import { useForm } from '@inertiajs/react';
import { GuestPicker } from '@/components/pms/guest-picker';
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
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/reservations/folios/third-party';

type Guest = App.Data.Guests.GuestOptionData;

function ThirdPartyForm({
    reservationId,
    onDone,
}: {
    reservationId: number;
    onDone: () => void;
}) {
    const form = useForm<{ payer: Guest | null }>({ payer: null });
    const { data } = form;
    const errors = form.errors as Partial<Record<string, string>>;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((values) => ({
            payer_guest_id: values.payer?.id ?? null,
        }));
        form.post(store.url({ reservation: reservationId }), {
            preserveScroll: true,
            except: KEEP_OPTIONS,
            onSuccess: onDone,
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <FieldGroup>
                <FormError errors={errors} />
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
            </FieldGroup>
            <DialogFooter>
                <DialogClose render={<Button variant="outline" />}>
                    Cancel
                </DialogClose>
                <Button
                    type="submit"
                    disabled={form.processing || data.payer === null}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Create folio
                </Button>
            </DialogFooter>
        </form>
    );
}

/**
 * Creates a third-party folio paid by a guest. The new folio opens on the folio tab.
 */
export function ThirdPartyFolioDialog({
    open,
    onOpenChange,
    reservationId,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>New third-party folio</DialogTitle>
                    <DialogDescription>
                        A folio with its own payer, for example a travel agent
                        that pays the diving charges. Add a routing rule to send
                        charges to it.
                    </DialogDescription>
                </DialogHeader>
                {open && (
                    <ThirdPartyForm
                        reservationId={reservationId}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}
