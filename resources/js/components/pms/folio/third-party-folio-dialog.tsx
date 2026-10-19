import { useForm } from '@inertiajs/react';
import { Building2, User } from 'lucide-react';
import { ChoiceCards } from '@/components/pms/choice-cards';
import { CompanySelect } from '@/components/pms/company-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { GuestPicker } from '@/components/pms/guest-picker';
import {
    KEEP_OPTIONS,
    useFormOptions,
} from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { store } from '@/routes/reservations/folios/third-party';

type Guest = App.Data.Guests.GuestOptionData;
type Company = App.Data.CityLedger.CompanyOptionData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    canViewCompany: boolean;
    companies?: Company[];
    /** Where the folio is created. Defaults to the folio tab's third-party route. */
    url?: string;
};

function ThirdPartyForm({
    open,
    onOpenChange,
    reservationId,
    canViewCompany,
    companies,
    url,
}: Props) {
    useFormOptions(
        open && canViewCompany,
        companies !== undefined,
        'companies',
    );

    const form = useForm<{
        payerType: string;
        payer: Guest | null;
        company: string;
    }>({ payerType: 'guest', payer: null, company: '' });
    const { data } = form;
    const errors = form.errors as Partial<Record<string, string>>;
    const isCompany = data.payerType === 'company';

    const submit = () => {
        form.transform((values) =>
            values.payerType === 'company'
                ? { payer_company_id: values.company || null }
                : { payer_guest_id: values.payer?.id ?? null },
        );
        form.post(url ?? store.url({ reservation: reservationId }), {
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
            description="A folio with its own payer, for example a travel agent that pays the diving charges, or a company that pays a late charge. Add a routing rule to send charges to it."
            errors={errors}
            onSubmit={submit}
            submitLabel="Create folio"
            processing={form.processing}
            submitDisabled={
                isCompany ? data.company === '' : data.payer === null
            }
        >
            {canViewCompany && (
                <ChoiceCards
                    id="third-party-payer-type"
                    legend="Payer"
                    choices={[
                        {
                            value: 'guest',
                            label: 'Guest',
                            description: 'A guest of the property.',
                            icon: User,
                        },
                        {
                            value: 'company',
                            label: 'Company',
                            description: 'Settled to the city ledger.',
                            icon: Building2,
                        },
                    ]}
                    value={data.payerType}
                    onValueChange={(value) => form.setData('payerType', value)}
                />
            )}
            {isCompany ? (
                <Field data-invalid={!!errors.payer_company_id}>
                    <FieldLabel htmlFor="third-party-company">
                        Company
                    </FieldLabel>
                    <CompanySelect
                        id="third-party-company"
                        value={data.company}
                        onValueChange={(value) =>
                            form.setData('company', value)
                        }
                        companies={companies ?? []}
                        invalid={!!errors.payer_company_id}
                    />
                    <FieldError>{errors.payer_company_id}</FieldError>
                </Field>
            ) : (
                <Field data-invalid={!!errors.payer_guest_id}>
                    <FieldLabel htmlFor="third-party-payer">Guest</FieldLabel>
                    <GuestPicker
                        id="third-party-payer"
                        value={data.payer}
                        onChange={(guest) => form.setData('payer', guest)}
                        invalid={!!errors.payer_guest_id}
                    />
                    <FieldError>{errors.payer_guest_id}</FieldError>
                </Field>
            )}
        </FormOverlay>
    );
}

/**
 * Creates a third-party folio paid by a guest, or by an Active company with `company.view`. The new folio opens on
 * the folio tab.
 */
export function ThirdPartyFolioDialog(props: Props) {
    return <ThirdPartyForm key={useFreshOnOpen(props.open)} {...props} />;
}
