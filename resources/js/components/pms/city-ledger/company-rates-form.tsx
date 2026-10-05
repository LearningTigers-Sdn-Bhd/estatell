import { Link, useForm } from '@inertiajs/react';
import { Tag } from 'lucide-react';
import { EmptyState } from '@/components/pms/empty-state';
import { Button } from '@/components/ui/button';
import { FieldError } from '@/components/ui/field';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
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

    const toggle = (id: number, checked: boolean) => {
        const previous = data.rate_plan_ids;
        const next = checked
            ? [...previous, id]
            : previous.filter((one) => one !== id);

        form.setData('rate_plan_ids', next);
        form.transform(() => ({ rate_plan_ids: next }));
        form.put(update.url({ company: companyId }), {
            preserveScroll: true,
            onError: () => form.setData('rate_plan_ids', previous),
        });
    };

    return (
        <section className="flex flex-col gap-5">
            <header className="space-y-1">
                <h3 className="text-base font-medium">Negotiated rates</h3>
                <p className="text-sm text-muted-foreground">
                    Switch on the plans this company can book. Changes save at
                    once. Staff see an enabled plan on the booking form only
                    when they pick one of its companies.
                </p>
            </header>
            <div className="overflow-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Available</TableHead>
                            <TableHead>Rate plan</TableHead>
                            <TableHead>Pricing</TableHead>
                            <TableHead>Room types</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {plans.map((plan) => (
                            <TableRow key={plan.id}>
                                <TableCell>
                                    <Switch
                                        size="sm"
                                        checked={data.rate_plan_ids.includes(
                                            plan.id,
                                        )}
                                        disabled={readOnly || form.processing}
                                        aria-label={`Let this company book ${plan.name}`}
                                        onCheckedChange={(checked) =>
                                            toggle(plan.id, checked)
                                        }
                                    />
                                </TableCell>
                                <TableCell className="font-medium">
                                    {plan.name}
                                </TableCell>
                                <TableCell>{plan.pricingLabel}</TableCell>
                                <TableCell>
                                    {plan.roomTypeNames.length > 0 ? (
                                        plan.roomTypeNames.join(', ')
                                    ) : (
                                        <span className="text-muted-foreground">
                                            All
                                        </span>
                                    )}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
            <FieldError>{errors.rate_plan_ids}</FieldError>
        </section>
    );
}
