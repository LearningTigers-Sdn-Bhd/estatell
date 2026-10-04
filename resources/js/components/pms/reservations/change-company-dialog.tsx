import { useForm } from '@inertiajs/react';
import { CreditOverrideField } from '@/components/pms/city-ledger/credit-override-field';
import { CompanySelect } from '@/components/pms/company-select';
import { FormError } from '@/components/pms/reservations/form-error';
import {
    KEEP_OPTIONS,
    useFormOptions,
} from '@/components/pms/reservations/use-form-options';
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
import { update } from '@/routes/reservations/company';

type Reservation = App.Data.Reservations.ReservationData;
type Company = App.Data.CityLedger.CompanyOptionData;

function ChangeCompanyForm({
    reservation,
    companies,
    canOverrideCredit,
    onDone,
}: {
    reservation: Reservation;
    companies?: Company[];
    canOverrideCredit: boolean;
    onDone: () => void;
}) {
    const form = useForm({
        company_id: reservation.companyId ? String(reservation.companyId) : '',
        credit_override_reason: '',
    });
    const { data, errors } = form;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.put(update.url({ reservation: reservation.id }), {
            preserveScroll: true,
            except: KEEP_OPTIONS,
            onSuccess: onDone,
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <FieldGroup>
                <FormError errors={errors} />
                <Field data-invalid={!!errors.company_id}>
                    <FieldLabel htmlFor="change-company_id">Company</FieldLabel>
                    <CompanySelect
                        id="change-company_id"
                        value={data.company_id}
                        onValueChange={(value) =>
                            form.setData('company_id', value)
                        }
                        companies={companies ?? []}
                        invalid={!!errors.company_id}
                    />
                    <FieldDescription>
                        Leave empty for no company. The company is billed
                        through the master folio.
                    </FieldDescription>
                    <FieldError>{errors.company_id}</FieldError>
                </Field>
                <CreditOverrideField
                    id="change-credit_override_reason"
                    error={errors.credit_override_reason}
                    canOverride={canOverrideCredit}
                    value={data.credit_override_reason}
                    onChange={(value) =>
                        form.setData('credit_override_reason', value)
                    }
                />
            </FieldGroup>
            <DialogFooter>
                <DialogClose render={<Button variant="outline" />}>
                    Cancel
                </DialogClose>
                <Button
                    type="submit"
                    disabled={form.processing || companies === undefined}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Save company
                </Button>
            </DialogFooter>
        </form>
    );
}

/**
 * Sets or clears the billed company. The Active company list loads when the Dialog opens.
 */
export function ChangeCompanyDialog({
    open,
    onOpenChange,
    reservation,
    companies,
    canOverrideCredit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservation: Reservation;
    companies?: Company[];
    canOverrideCredit: boolean;
}) {
    useFormOptions(open, companies !== undefined, 'companies');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Change company</DialogTitle>
                    <DialogDescription>
                        {reservation.reference}
                    </DialogDescription>
                </DialogHeader>
                <ChangeCompanyForm
                    reservation={reservation}
                    companies={companies}
                    canOverrideCredit={canOverrideCredit}
                    onDone={() => onOpenChange(false)}
                />
            </DialogContent>
        </Dialog>
    );
}
