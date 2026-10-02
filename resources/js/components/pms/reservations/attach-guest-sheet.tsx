import { useForm } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import { GuestPicker } from '@/components/pms/guests/guest-picker';
import { DiscardChangesDialog } from '@/components/pms/reservations/discard-changes-dialog';
import { FormError } from '@/components/pms/reservations/form-error';
import { KEEP_OPTIONS } from '@/components/pms/reservations/use-form-options';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/reservations/units/guests';

type Unit = App.Data.Reservations.ReservationUnitOptionData;
type Guest = App.Data.Guests.GuestOptionData;

function AttachGuestForm({
    reservationId,
    units,
    initialUnitId,
    onDirty,
    onDone,
    onCancel,
}: {
    reservationId: number;
    units: Unit[];
    initialUnitId: number | null;
    onDirty: (dirty: boolean) => void;
    onDone: () => void;
    onCancel: () => void;
}) {
    const form = useForm({
        unit_id: String(initialUnitId ?? units[0]?.id ?? ''),
        guest_id: '',
    });
    const { data, errors } = form;
    const [guest, setGuest] = useState<Guest | null>(null);
    const dirty = guest !== null;

    useEffect(() => {
        onDirty(dirty);
    }, [dirty, onDirty]);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform(({ guest_id }) => ({ guest_id }));
        form.post(
            store.url({
                reservation: reservationId,
                unit: Number(data.unit_id),
            }),
            {
                preserveScroll: true,
                except: KEEP_OPTIONS,
                onSuccess: onDone,
            },
        );
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <FieldGroup className="flex-1 overflow-y-auto px-4">
                <FormError errors={errors} />
                <Field>
                    <FieldLabel htmlFor="attach-unit_id">Stays in</FieldLabel>
                    <EnumSelect
                        id="attach-unit_id"
                        options={units.map((unit) => ({
                            value: String(unit.id),
                            label: unit.label,
                        }))}
                        value={data.unit_id}
                        onValueChange={(value) =>
                            form.setData('unit_id', value)
                        }
                    />
                </Field>
                <Field data-invalid={!!errors.guest_id}>
                    <FieldLabel htmlFor="attach-guest_id">Guest</FieldLabel>
                    <GuestPicker
                        id="attach-guest_id"
                        value={guest}
                        onChange={(next) => {
                            setGuest(next);
                            form.setData(
                                'guest_id',
                                next ? String(next.id) : '',
                            );
                        }}
                        invalid={!!errors.guest_id}
                    />
                    <FieldDescription>
                        Existing guests only. Add a new guest from the Guests
                        page first.
                    </FieldDescription>
                    <FieldError>{errors.guest_id}</FieldError>
                </Field>
                {guest?.isBlacklisted && (
                    <Alert>
                        <TriangleAlert />
                        <AlertDescription>
                            {guest.fullName} is on the blacklist. You can still
                            attach them.
                        </AlertDescription>
                    </Alert>
                )}
            </FieldGroup>
            <SheetFooter>
                <Button type="button" variant="outline" onClick={onCancel}>
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={form.processing || guest === null}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Attach guest
                </Button>
            </SheetFooter>
        </form>
    );
}

/**
 * Attaches an existing guest to a unit. Only units that are still open are offered.
 */
export function AttachGuestSheet({
    open,
    onOpenChange,
    reservationId,
    units,
    initialUnitId,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    units: Unit[];
    initialUnitId: number | null;
}) {
    const dirty = useRef(false);
    const [confirmingDiscard, setConfirmingDiscard] = useState(false);
    const markDirty = useCallback((next: boolean) => {
        dirty.current = next;
    }, []);

    const close = () => {
        dirty.current = false;
        setConfirmingDiscard(false);
        onOpenChange(false);
    };

    const requestClose = (next: boolean) => {
        if (next) {
            onOpenChange(true);
        } else if (dirty.current) {
            setConfirmingDiscard(true);
        } else {
            close();
        }
    };

    return (
        <>
            <Sheet open={open} onOpenChange={requestClose}>
                <SheetContent>
                    <SheetHeader>
                        <SheetTitle>Attach guest</SheetTitle>
                        <SheetDescription>
                            Choose the unit and the guest who stays in it.
                        </SheetDescription>
                    </SheetHeader>
                    <AttachGuestForm
                        reservationId={reservationId}
                        units={units}
                        initialUnitId={initialUnitId}
                        onDirty={markDirty}
                        onDone={close}
                        onCancel={() => requestClose(false)}
                    />
                </SheetContent>
            </Sheet>

            <DiscardChangesDialog
                open={confirmingDiscard}
                onOpenChange={setConfirmingDiscard}
                title="Discard this guest?"
                onDiscard={close}
            />
        </>
    );
}
