import { useForm, useHttp } from '@inertiajs/react';
import { ArrowDown, ArrowRight, ArrowUp, TriangleAlert } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import {
    preview,
    update,
} from '@/routes/settings/rate-plans/pricing/derivation';

type RatePlan = App.Data.Rates.RatePlanData;
type Derivation = App.Data.Rates.RatePlanDerivationData;
type PreviewRow = App.Data.Rates.RatePlanDerivationPreviewRowData;

type Fields = {
    parent_rate_plan_id: string;
    derivation: string;
    direction: string;
    value: string;
};

/**
 * "Follow another plan": the parent, a direction, a value and a unit. A preview shows today's price per room type.
 * The plan keeps no prices of its own, so saving clears any it had. Restrictions stay.
 */
export function DerivationForm({
    ratePlan,
    derivation,
    derivations,
    currency,
    hasOwnPrices,
}: {
    ratePlan: RatePlan;
    derivation: Derivation;
    derivations: { value: string; label: string }[];
    currency: App.Enums.Currency;
    hasOwnPrices: boolean;
}) {
    const initial: Fields = {
        parent_rate_plan_id: String(
            derivation.parentRatePlanId ?? derivation.parents[0]?.id ?? '',
        ),
        derivation: derivation.derivation ?? 'percent',
        direction: derivation.direction ?? 'down',
        value: derivation.value ?? '',
    };
    const form = useForm<Fields>(initial);
    const guard = useUnsavedChanges(form.isDirty);
    const http = useHttp<Fields, PreviewRow[]>(initial);
    const [rows, setRows] = useState<PreviewRow[]>([]);
    const data = form.data;
    const valid = data.parent_rate_plan_id !== '' && data.value !== '';
    const key = JSON.stringify(data);
    const dataRef = useRef(data);
    dataRef.current = data;
    const money = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency,
    });
    const isPercent = data.derivation === 'percent';

    useEffect(() => {
        if (!valid) {
            setRows([]);

            return;
        }

        const timer = window.setTimeout(() => {
            http.transform(() => dataRef.current);
            http.post(preview.url({ ratePlan: ratePlan.id }), {
                onSuccess: (response) => setRows(response),
            }).catch(() => undefined);
        }, 300);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key]);

    const save = (onSaved?: () => void) => {
        form.put(update.url({ ratePlan: ratePlan.id }), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                form.setDefaults();
                onSaved?.();
            },
        });
    };

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                save();
            }}
            className="flex max-w-3xl flex-col gap-5"
        >
            {hasOwnPrices && derivation.parentRatePlanId === null && (
                <Alert>
                    <TriangleAlert />
                    <AlertDescription>
                        Saving removes the prices you set for this plan. Its
                        restrictions stay.
                    </AlertDescription>
                </Alert>
            )}
            <FieldGroup>
                <Field data-invalid={!!errors.parent_rate_plan_id}>
                    <FieldLabel htmlFor="derivation-parent">Follows</FieldLabel>
                    <EnumSelect
                        id="derivation-parent"
                        className="sm:max-w-72"
                        options={derivation.parents.map((parent) => ({
                            value: String(parent.id),
                            label: parent.name,
                        }))}
                        value={data.parent_rate_plan_id}
                        onValueChange={(value) =>
                            form.setData('parent_rate_plan_id', value)
                        }
                    />
                    <FieldError>{errors.parent_rate_plan_id}</FieldError>
                </Field>
                <div className="flex flex-wrap items-start gap-4">
                    <Field className="w-auto">
                        <FieldLabel>Direction</FieldLabel>
                        <ToggleGroup
                            variant="outline"
                            value={[data.direction]}
                            onValueChange={(value) =>
                                value[0] && form.setData('direction', value[0])
                            }
                            aria-label="Direction"
                        >
                            <ToggleGroupItem value="down">
                                <ArrowDown data-icon="inline-start" />
                                Down
                            </ToggleGroupItem>
                            <ToggleGroupItem value="up">
                                <ArrowUp data-icon="inline-start" />
                                Up
                            </ToggleGroupItem>
                        </ToggleGroup>
                        <FieldError>{errors.direction}</FieldError>
                    </Field>
                    <Field className="w-32" data-invalid={!!errors.value}>
                        <FieldLabel htmlFor="derivation-value">
                            Value
                        </FieldLabel>
                        <Input
                            id="derivation-value"
                            type="number"
                            min="0"
                            step="0.01"
                            inputMode="decimal"
                            placeholder="e.g. 10"
                            value={data.value}
                            onChange={(event) =>
                                form.setData('value', event.target.value)
                            }
                            aria-invalid={!!errors.value}
                        />
                        <FieldError>{errors.value}</FieldError>
                    </Field>
                    <Field className="w-40">
                        <FieldLabel htmlFor="derivation-unit">Unit</FieldLabel>
                        <EnumSelect
                            id="derivation-unit"
                            options={derivations.map((option) => ({
                                value: option.value,
                                label:
                                    option.value === 'percent'
                                        ? `${option.label} (%)`
                                        : `${option.label} (${currency})`,
                            }))}
                            value={data.derivation}
                            onValueChange={(value) =>
                                form.setData('derivation', value)
                            }
                        />
                        <FieldError>{errors.derivation}</FieldError>
                    </Field>
                </div>
            </FieldGroup>
            <section
                aria-label="Today's prices"
                aria-busy={http.processing}
                className="flex flex-col gap-2 rounded-lg border p-4"
            >
                <h3 className="flex items-center gap-2 text-sm font-medium">
                    Today's price
                    {http.processing && <Spinner />}
                </h3>
                {rows.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {valid
                            ? 'No room types to show.'
                            : 'Choose a plan and a value to see prices.'}
                    </p>
                ) : (
                    <ul className="flex flex-col gap-1 text-sm">
                        {rows.map((row) => (
                            <li
                                key={row.roomTypeId}
                                className="flex items-center gap-2"
                            >
                                <span className="font-medium">{row.name}</span>
                                {row.parentAmount === null ||
                                row.derivedAmount === null ? (
                                    <span className="text-muted-foreground">
                                        No price today on the plan it follows
                                    </span>
                                ) : (
                                    <span className="flex items-center gap-1.5 tabular-nums">
                                        {money.format(Number(row.parentAmount))}
                                        <ArrowRight className="size-3.5 text-muted-foreground" />
                                        {money.format(
                                            Number(row.derivedAmount),
                                        )}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                <p className="text-xs text-muted-foreground">
                    {isPercent
                        ? 'A percent changes every price, including guests and children.'
                        : 'An amount is added per guest, bed or room, following how this plan is priced.'}
                </p>
            </section>
            <footer className="flex justify-end">
                <Button
                    type="submit"
                    disabled={form.processing || !valid}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Save pricing
                </Button>
            </footer>
            <UnsavedChangesDialog
                guard={guard}
                name={ratePlan.name}
                saving={form.processing}
                onSave={() => save(guard.proceed)}
            />
        </form>
    );
}
