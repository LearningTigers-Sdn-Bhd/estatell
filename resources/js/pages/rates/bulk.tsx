import { Head, Link, router } from '@inertiajs/react';
import { CalendarRange, Info } from 'lucide-react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { RateTabs } from '@/components/pms/rate-calendar/rate-tabs';
import { PricingForm } from '@/components/pms/rate-plans/pricing-form';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { buttonVariants } from '@/components/ui/button';
import { Field, FieldLabel } from '@/components/ui/field';
import { calendar } from '@/routes/rates';
import { bulk } from '@/routes/rates/calendar';
import { update as bulkUpdate } from '@/routes/rates/calendar/bulk';
import { index as ratePlansIndex } from '@/routes/settings/rate-plans';

type Plan = App.Data.Rates.RateBulkPlanData;

function planNote(plan: Plan): string | null {
    if (plan.derived) {
        return `${plan.name} follows ${plan.followsName}, so its price comes from that plan. Only restrictions can change here.`;
    }

    if (!plan.hasOwnPrices) {
        return 'Package prices are set on the package. Only restrictions can change here.';
    }

    return null;
}

/**
 * Bulk edit: a full page, because it has repeating price rows. Pick a plan and a date range, then change prices
 * and restrictions over the whole range. Saving returns to the calendar at the start of the range.
 */
export default function RateBulkEdit({
    plans,
    ratePlan,
    roomTypes,
    currency,
    weekendNights,
    weekendNightsOptions,
    today,
    from,
    to,
}: {
    plans: Plan[];
    ratePlan: App.Data.Rates.RatePlanData | null;
    roomTypes: App.Data.Rates.RatePricingRoomTypeData[];
    currency: App.Data.Property.CurrencyData;
    weekendNights: string;
    weekendNightsOptions: App.Data.Property.WeekendNightsOptionData[];
    today: string;
    from: string;
    to: string;
}) {
    const selected = plans.find((plan) => plan.id === ratePlan?.id);
    const note = selected ? planNote(selected) : null;
    const query = from && to ? { from, to } : {};

    return (
        <div className="flex flex-1 flex-col gap-6 p-4">
            <Head title="Bulk edit rates" />
            <RateTabs
                activeTab="bulk"
                calendarHref={calendar.url(undefined, { query })}
                bulkHref={bulk.url(undefined, {
                    query: {
                        ...(ratePlan ? { plan: ratePlan.id } : {}),
                        ...query,
                    },
                })}
                canManage
                className="flex flex-col gap-6"
                contentClassName="flex flex-col gap-6"
            >
                <PageHeader
                    title="Bulk edit rates"
                    description="Change prices and restrictions over a date range."
                />
                {!ratePlan || !selected ? (
                    <EmptyState
                        icon={CalendarRange}
                        title="No rate plans"
                        description="Add a rate plan that sells at least one room type first."
                        action={
                            <Link
                                href={ratePlansIndex.url()}
                                className={buttonVariants()}
                            >
                                Go to rate plans
                            </Link>
                        }
                    />
                ) : (
                    <>
                        <Field className="w-full sm:max-w-sm">
                            <FieldLabel htmlFor="bulk-plan">
                                Rate plan
                            </FieldLabel>
                            <EnumSelect
                                id="bulk-plan"
                                options={plans.map((plan) => ({
                                    value: String(plan.id),
                                    label: plan.name,
                                }))}
                                value={String(ratePlan.id)}
                                onValueChange={(plan) =>
                                    router.get(
                                        bulk.url(undefined, {
                                            query: { plan, ...query },
                                        }),
                                    )
                                }
                                className="w-full"
                            />
                        </Field>
                        {note && (
                            <Alert>
                                <Info />
                                <AlertDescription>{note}</AlertDescription>
                            </Alert>
                        )}
                        {roomTypes.length === 0 ? (
                            <EmptyState
                                title="No room types"
                                description="Choose the room types this plan sells before you change its rates."
                            />
                        ) : (
                            <PricingForm
                                key={ratePlan.id}
                                ratePlan={ratePlan}
                                roomTypes={roomTypes}
                                currency={currency}
                                weekendNights={weekendNights}
                                weekendNightsOptions={weekendNightsOptions}
                                today={today}
                                saveUrl={bulkUpdate.url({
                                    ratePlan: ratePlan.id,
                                })}
                                initialRange={
                                    from && to ? { from, to } : undefined
                                }
                                restrictions
                                prices={selected.hasOwnPrices}
                                submitLabel="Save changes"
                            />
                        )}
                    </>
                )}
            </RateTabs>
        </div>
    );
}

RateBulkEdit.layout = {
    breadcrumbs: [
        { title: 'Rate calendar', href: calendar() },
        { title: 'Bulk edit', href: bulk() },
    ],
};
