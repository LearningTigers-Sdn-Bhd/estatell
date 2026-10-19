import { Link } from '@inertiajs/react';
import { ArrowUpRight, Tag } from 'lucide-react';
import { EmptyState } from '@/components/pms/empty-state';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { edit } from '@/routes/settings/rate-plans/companies';

type Rate = App.Data.CityLedger.CompanyRateData;

/**
 * The rate plans this company can use and where (front desk, portal). Read only: each rate is set on the plan's
 * Companies tab, which the plan name opens for staff with `settings.manage`.
 */
export function CompanyRatesTable({
    rates,
    canViewRatePlans,
}: {
    rates: Rate[];
    canViewRatePlans: boolean;
}) {
    if (rates.length === 0) {
        return (
            <EmptyState
                icon={Tag}
                title="No company rates yet."
                description="Set them on a rate plan's Companies tab."
            />
        );
    }

    const yes = (on: boolean) =>
        on ? 'Yes' : <span className="text-muted-foreground">—</span>;

    return (
        <section className="flex flex-col gap-3">
            <p className="text-sm text-muted-foreground">
                The rates this company can use. Set them on each rate plan's
                Companies tab.
            </p>
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
                                        <Link
                                            href={edit({
                                                ratePlan: rate.ratePlanId,
                                            })}
                                            className="inline-flex items-center gap-1 underline-offset-4 hover:underline"
                                        >
                                            {rate.name}
                                            <ArrowUpRight
                                                className="size-3.5"
                                                aria-hidden
                                            />
                                        </Link>
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
