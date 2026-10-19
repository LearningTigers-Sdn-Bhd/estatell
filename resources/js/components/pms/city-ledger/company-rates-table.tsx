import { ExternalLink, Tag } from 'lucide-react';
import { EmptyState } from '@/components/pms/empty-state';
import { buttonVariants } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { index as ratePlans } from '@/routes/settings/rate-plans';
import { edit } from '@/routes/settings/rate-plans/companies';

type Rate = App.Data.CityLedger.CompanyRateData;

/**
 * The rate plans this company can use and where (front desk, portal). Read only: each rate is set on the plan's
 * Companies tab, which the plan name opens in a new tab for staff with `settings.manage`. **Manage rate plans** opens the Settings rate
 * plan list in a new tab, like the other area links.
 */
export function CompanyRatesTable({
    rates,
    canViewRatePlans,
}: {
    rates: Rate[];
    canViewRatePlans: boolean;
}) {
    const manage = canViewRatePlans && (
        <a
            href={ratePlans.url()}
            target="_blank"
            rel="noopener noreferrer"
            className={buttonVariants({ variant: 'outline' })}
        >
            Manage rate plans
            <ExternalLink aria-hidden />
            <span className="sr-only">(opens in a new tab)</span>
        </a>
    );

    if (rates.length === 0) {
        return (
            <EmptyState
                icon={Tag}
                title="No company rates yet."
                description="Set them on a rate plan's Companies tab."
                action={manage || undefined}
            />
        );
    }

    const yes = (on: boolean) =>
        on ? 'Yes' : <span className="text-muted-foreground">—</span>;

    return (
        <section className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-sm text-muted-foreground">
                    The rates this company can use. Set them on each rate plan's
                    Companies tab.
                </p>
                {manage}
            </div>
            <div className="overflow-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Rate plan</TableHead>
                            <TableHead>Pricing</TableHead>
                            <TableHead>Front desk</TableHead>
                            <TableHead>Portal</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rates.map((rate) => (
                            <TableRow key={rate.ratePlanId}>
                                <TableCell className="font-medium">
                                    {canViewRatePlans ? (
                                        <a
                                            href={edit.url({
                                                ratePlan: rate.ratePlanId,
                                            })}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="inline-flex items-center gap-1 underline-offset-4 hover:underline"
                                        >
                                            {rate.name}
                                            <ExternalLink
                                                className="size-3.5 text-muted-foreground"
                                                aria-hidden
                                            />
                                            <span className="sr-only">
                                                (opens in a new tab)
                                            </span>
                                        </a>
                                    ) : (
                                        rate.name
                                    )}
                                </TableCell>
                                <TableCell>{rate.pricingLabel}</TableCell>
                                <TableCell>{yes(rate.atDesk)}</TableCell>
                                <TableCell>{yes(rate.inPortal)}</TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </section>
    );
}
