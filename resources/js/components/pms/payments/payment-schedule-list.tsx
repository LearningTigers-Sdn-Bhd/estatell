import { MoneyText } from '@/components/pms/money-text';
import { Badge } from '@/components/ui/badge';

const statusLabels: Record<string, string> = {
    paid: 'Paid',
    proof: 'Proof received',
    due: 'Due',
    overdue: 'Overdue',
};

/**
 * The stages of an agent payment schedule: each with its amount, due time and status. The reservation page and the
 * portal booking share it.
 */
export function PaymentScheduleList({
    schedule,
    currency,
}: {
    schedule: App.Data.Reservations.PaymentScheduleData;
    currency: App.Data.Property.CurrencyData | null;
}) {
    return (
        <div className="flex flex-col gap-2 text-sm">
            {Number(schedule.paid) > 0 && (
                <p className="flex flex-wrap justify-between gap-3 rounded-md bg-muted px-3 py-2">
                    <span>
                        Paid{' '}
                        <MoneyText amount={schedule.paid} currency={currency} />
                    </span>
                    <span>
                        Left to pay{' '}
                        <MoneyText amount={schedule.left} currency={currency} />
                    </span>
                </p>
            )}
            <ul className="flex flex-col gap-1.5">
                {schedule.stages.map((stage) => (
                    <li
                        key={stage.label}
                        className="flex items-start justify-between gap-3"
                    >
                        <span className="flex flex-col">
                            <span>{stage.label}</span>
                            <span className="text-xs text-muted-foreground">
                                by{' '}
                                {new Date(stage.dueAt).toLocaleString(
                                    undefined,
                                    {
                                        day: 'numeric',
                                        month: 'short',
                                        hour: '2-digit',
                                        minute: '2-digit',
                                    },
                                )}
                            </span>
                        </span>
                        <span className="flex flex-col items-end gap-0.5">
                            <MoneyText
                                amount={stage.amount}
                                currency={currency}
                            />
                            <Badge
                                variant={
                                    stage.status === 'overdue'
                                        ? 'destructive'
                                        : stage.status === 'paid'
                                          ? 'secondary'
                                          : 'outline'
                                }
                            >
                                {statusLabels[stage.status] ?? stage.status}
                            </Badge>
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
