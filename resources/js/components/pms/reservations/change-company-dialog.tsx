import { useForm } from '@inertiajs/react';
import { CreditOverrideField } from '@/components/pms/city-ledger/credit-override-field';
import { CompanySelect } from '@/components/pms/company-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { eachPaysHint } from '@/components/pms/reservations/each-pays-hint';
import {
    KEEP_OPTIONS,
    useFormOptions,
} from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { update } from '@/routes/reservations/company';

type Reservation = App.Data.Reservations.ReservationData;
type Company = App.Data.CityLedger.CompanyOptionData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservation: Reservation;
    companies?: Company[];
    canOverrideCredit: boolean;
};

function ChangeCompanyForm({
    open,
    onOpenChange,
    reservation,
    companies,
    canOverrideCredit,
}: Props) {
    useFormOptions(open, companies !== undefined, 'companies');

    const form = useForm({
        company_id: reservation.companyId ? String(reservation.companyId) : '',
        credit_override_reason: '',
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
            title="Change company"
            description={reservation.reference}
            errors={errors}
            onSubmit={submit}
            submitLabel="Save company"
            processing={form.processing}
            loading={companies === undefined}
        >
            <Field data-invalid={!!errors.company_id}>
                <FieldLabel htmlFor="change-company_id">Company</FieldLabel>
                <CompanySelect
                    id="change-company_id"
                    value={data.company_id}
                    onValueChange={(value) => form.setData('company_id', value)}
                    companies={companies ?? []}
                    invalid={!!errors.company_id}
                />
                <FieldDescription>
                    Leave empty for no company.{' '}
                    {eachPaysHint(
                        companies?.find(
                            (company) => String(company.id) === data.company_id,
                        )?.name,
                        reservation.billingMode,
                        'change',
                    ) ?? 'The company is billed through the master folio.'}
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
        </FormOverlay>
    );
}

/**
 * Sets or clears the billed company. The Active company list loads when the Dialog opens.
 */
export function ChangeCompanyDialog(props: Props) {
    return <ChangeCompanyForm key={useFreshOnOpen(props.open)} {...props} />;
}
