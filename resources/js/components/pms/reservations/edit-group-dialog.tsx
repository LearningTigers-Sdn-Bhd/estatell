import { useForm } from '@inertiajs/react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import {
    collectedTaxesHint,
    companyDefaultLabel,
    showsCollectedTaxes,
} from '@/components/pms/reservations/collected-taxes-hint';
import { eachPaysHint } from '@/components/pms/reservations/each-pays-hint';
import { KEEP_OPTIONS } from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { update } from '@/routes/reservations/group';

type Reservation = App.Data.Reservations.ReservationData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservation: Reservation;
    billingModes: EnumOption[];
    collectedTaxesPayers: EnumOption[];
};

function EditGroupForm({
    open,
    onOpenChange,
    reservation,
    billingModes,
    collectedTaxesPayers,
}: Props) {
    const form = useForm({
        group_name: reservation.groupName ?? '',
        billing_mode: reservation.billingMode,
        collected_taxes_payer: reservation.collectedTaxes?.bookingPayer ?? '',
    });
    const { data, errors } = form;
    const taxes = reservation.collectedTaxes;
    const showsTaxes =
        !!taxes &&
        showsCollectedTaxes(
            reservation.companyName,
            taxes.taxes,
            data.billing_mode,
        );

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
            title="Edit group"
            description={reservation.reference}
            errors={errors}
            onSubmit={submit}
            submitLabel="Save group"
            processing={form.processing}
        >
            <Field data-invalid={!!errors.group_name}>
                <FieldLabel htmlFor="group-group_name">Group name</FieldLabel>
                <Input
                    id="group-group_name"
                    value={data.group_name}
                    onChange={(event) =>
                        form.setData('group_name', event.target.value)
                    }
                    maxLength={150}
                    placeholder="Tan family trip"
                    autoComplete="off"
                    autoFocus
                    aria-invalid={!!errors.group_name}
                />
                <FieldDescription>
                    Leave empty to show the booker's name.
                </FieldDescription>
                <FieldError>{errors.group_name}</FieldError>
            </Field>
            {reservation.unitCount > 1 && (
                <Field data-invalid={!!errors.billing_mode}>
                    <FieldLabel htmlFor="group-billing_mode">
                        Who pays the room charges
                    </FieldLabel>
                    <EnumSelect
                        id="group-billing_mode"
                        options={billingModes}
                        value={data.billing_mode}
                        onValueChange={(value) =>
                            form.setData('billing_mode', value)
                        }
                        aria-invalid={!!errors.billing_mode}
                    />
                    {eachPaysHint(
                        reservation.companyName,
                        data.billing_mode,
                    ) && (
                        <FieldDescription>
                            {eachPaysHint(
                                reservation.companyName,
                                data.billing_mode,
                            )}
                        </FieldDescription>
                    )}
                    <FieldError>{errors.billing_mode}</FieldError>
                </Field>
            )}
            {showsTaxes && taxes && (
                <Field data-invalid={!!errors.collected_taxes_payer}>
                    <FieldLabel htmlFor="group-collected_taxes_payer">
                        Who pays the taxes at check-in
                    </FieldLabel>
                    <EnumSelect
                        id="group-collected_taxes_payer"
                        options={collectedTaxesPayers}
                        allLabel={companyDefaultLabel(
                            collectedTaxesPayers,
                            taxes.companyDefaultPays,
                        )}
                        value={data.collected_taxes_payer}
                        onValueChange={(value) =>
                            form.setData('collected_taxes_payer', value)
                        }
                        aria-invalid={!!errors.collected_taxes_payer}
                    />
                    <FieldDescription>
                        {collectedTaxesHint(
                            taxes.taxes,
                            taxes.companyName ?? 'the company',
                            data.collected_taxes_payer,
                            taxes.companyDefaultPays,
                        )}
                    </FieldDescription>
                    <FieldError>{errors.collected_taxes_payer}</FieldError>
                </Field>
            )}
        </FormOverlay>
    );
}

/**
 * Changes the group name, who pays the room charges when the reservation has 2 or more units, and who pays the taxes
 * collected at check-in when the reservation has a company and the property has such taxes.
 */
export function EditGroupDialog(props: Props) {
    return <EditGroupForm key={useFreshOnOpen(props.open)} {...props} />;
}
