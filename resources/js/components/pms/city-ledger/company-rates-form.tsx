import { Link, useForm } from '@inertiajs/react';
import { Tag } from 'lucide-react';
import { EmptyState } from '@/components/pms/empty-state';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { index as ratePlans } from '@/routes/settings/rate-plans';
import { update } from '@/routes/finance/city-ledger/companies/rates';

type RatePlan = App.Data.Rates.RatePlanData;

/**
 * The derived plans this company can book. A plan with no company is open to everyone, so a plan ticked here
 * disappears from the booking form until one of its companies is picked.
 */
export function CompanyRatesForm({
    companyId,
    ratePlans: plans,
    linkedPlanIds,
    readOnly,
}: {
    companyId: number;
    ratePlans: RatePlan[];
    linkedPlanIds: number[];
    readOnly: boolean;
}) {
    const form = useForm<{ rate_plan_ids: number[] }>({
        rate_plan_ids: linkedPlanIds.filter((id) =>
            plans.some((plan) => plan.id === id),
        ),
    });
    const { data, errors } = form;

    if (plans.length === 0) {
        return (
            <EmptyState
                icon={Tag}
                title="No derived rate plans yet"
                description="A negotiated rate follows another plan, for example Basic Rate -15%. Add one under Rate plans, then link it here."
                action={
                    <Button
                        variant="outline"
                        render={<Link href={ratePlans()} />}
                    >
                        Go to rate plans
                    </Button>
                }
            />
        );
    }

    const toggle = (id: number, checked: boolean) =>
        form.setData(
            'rate_plan_ids',
            checked
                ? [...data.rate_plan_ids, id]
                : data.rate_plan_ids.filter((one) => one !== id),
        );

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.put(update.url({ company: companyId }), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-5">
            <header className="space-y-1">
                <h3 className="text-base font-medium">Negotiated rates</h3>
                <p className="text-sm text-muted-foreground">
                    Tick the plans this company can book. Staff see a ticked
                    plan on the booking form only when they pick one of its
                    companies.
                </p>
            </header>
            <div className="grid gap-3 md:grid-cols-2">
                {plans.map((plan) => (
                    <FieldLabel
                        key={plan.id}
                        htmlFor={`plan-${plan.id}`}
                        className="has-[>[data-slot=field]]:rounded-md"
                    >
                        <Field orientation="horizontal">
                            <Checkbox
                                id={`plan-${plan.id}`}
                                checked={data.rate_plan_ids.includes(plan.id)}
                                disabled={readOnly}
                                onCheckedChange={(checked) =>
                                    toggle(plan.id, checked)
                                }
                            />
                            <FieldContent>
                                <span className="text-sm font-medium">
                                    {plan.name}
                                </span>
                                <FieldDescription>
                                    {plan.pricingLabel}
                                </FieldDescription>
                            </FieldContent>
                        </Field>
                    </FieldLabel>
                ))}
            </div>
            <FieldError>{errors.rate_plan_ids}</FieldError>
            {!readOnly && (
                <footer className="flex justify-end">
                    <Button
                        type="submit"
                        disabled={form.processing}
                        focusableWhenDisabled
                    >
                        {form.processing && <Spinner />}
                        Save rates
                    </Button>
                </footer>
            )}
        </form>
    );
}
