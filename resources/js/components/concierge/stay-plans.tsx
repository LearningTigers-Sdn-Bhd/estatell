import { Bus, CalendarClock, Compass } from 'lucide-react';

type Plan = App.Data.Concierge.ConciergePlanData;

/** "2026-11-20" as "Fri 20 Nov", so the guest reads the day at a glance. */
const planDay = (date: string) =>
    new Date(`${date}T00:00:00`).toLocaleDateString('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });

/**
 * "Your plans": the road transfers and activities booked for the stay, by day and time, so the guest knows when and
 * where to be. Shows nothing when nothing is booked. See docs/30-concierge/01-overview.md#your-plans.
 */
export function StayPlans({ plans }: { plans: Plan[] }) {
    if (plans.length === 0) {
        return null;
    }

    return (
        <section className="flex flex-col gap-3" aria-labelledby="stay-plans">
            <h2
                id="stay-plans"
                className="flex items-center gap-2 text-lg font-semibold"
            >
                <CalendarClock
                    className="size-5 text-muted-foreground"
                    aria-hidden
                />
                Your plans
            </h2>
            <ul className="flex flex-col divide-y divide-border/40 rounded-xl border border-border/40">
                {plans.map((plan) => {
                    const Icon = plan.kind === 'transfer' ? Bus : Compass;

                    return (
                        <li
                            key={`${plan.kind}-${plan.date}-${plan.time}-${plan.title}`}
                            className="flex items-start gap-3 p-4"
                        >
                            <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted/60 text-primary">
                                <Icon className="size-5" aria-hidden />
                            </span>
                            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span className="font-medium">
                                    {plan.title}
                                </span>
                                {plan.detail && (
                                    <span className="text-sm text-muted-foreground">
                                        {plan.detail}
                                    </span>
                                )}
                            </span>
                            <span className="flex shrink-0 flex-col items-end gap-0.5 text-sm">
                                <span className="font-medium tabular-nums">
                                    {plan.time}
                                </span>
                                <span className="text-muted-foreground">
                                    {planDay(plan.date)}
                                </span>
                            </span>
                        </li>
                    );
                })}
            </ul>
            <p className="text-sm text-muted-foreground">
                Times are local. To change a plan, contact the front desk.
            </p>
        </section>
    );
}
