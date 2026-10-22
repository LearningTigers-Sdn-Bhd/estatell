import { DateText } from '@/components/pms/date-text';
import { MoneyText } from '@/components/pms/money-text';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import type { CheckOutState } from './use-check-out-form';

/**
 * The early departure block of the check-out Dialog: the booked departure, the nights removed and the fee, then Do not
 * charge and the reason. Renders nothing for a plain check-out.
 */
export function EarlyDepartureSection({
    state,
    currency,
}: {
    state: CheckOutState;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const { early, form, figures, leftYesterday } = state;
    const { data, errors } = form;

    if (early === null) {
        return null;
    }

    return (
        <>
            <div className="flex flex-col gap-1 text-sm">
                <div className="flex justify-between gap-4">
                    <span className="text-muted-foreground">
                        Booked departure
                    </span>
                    <DateText date={early.departure} />
                </div>
                <div className="flex justify-between gap-4">
                    <span className="text-muted-foreground">
                        Nights removed
                    </span>
                    <span>{early.nightsRemoved}</span>
                </div>
                <div className="flex justify-between gap-4">
                    <span className="text-muted-foreground">
                        Early departure fee
                    </span>
                    <span className="font-medium">
                        {figures.hasFee ? (
                            <>
                                <MoneyText
                                    amount={early.fee}
                                    currency={currency}
                                />{' '}
                                <span className="font-normal text-muted-foreground">
                                    + tax
                                </span>
                            </>
                        ) : (
                            'No charge'
                        )}
                    </span>
                </div>
            </div>
            <FieldError>{errors.expected_fee}</FieldError>
            {figures.showWaive && (
                <FieldLabel htmlFor="check-out-do_not_charge">
                    <Field orientation="horizontal">
                        <Checkbox
                            id="check-out-do_not_charge"
                            checked={data.do_not_charge}
                            onCheckedChange={(checked) =>
                                form.setData('do_not_charge', checked)
                            }
                        />
                        <FieldContent>
                            <span className="text-sm font-medium">
                                Do not charge
                            </span>
                            <FieldDescription>
                                The fee is not posted to the folio. This is
                                logged with your name.
                            </FieldDescription>
                        </FieldContent>
                    </Field>
                </FieldLabel>
            )}
            <Field data-invalid={!!errors.reason}>
                <FieldLabel htmlFor="check-out-reason">
                    {leftYesterday ? 'Reason (required)' : 'Reason'}
                </FieldLabel>
                <Textarea
                    id="check-out-reason"
                    value={data.reason}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                    maxLength={500}
                    rows={2}
                    placeholder={
                        figures.waived
                            ? 'Required with Do not charge'
                            : leftYesterday
                              ? 'e.g. Guest left late last night and did not check out'
                              : 'e.g. Flight changed, guest leaves a day early'
                    }
                    aria-invalid={!!errors.reason}
                />
                <FieldError>{errors.reason}</FieldError>
            </Field>
        </>
    );
}
