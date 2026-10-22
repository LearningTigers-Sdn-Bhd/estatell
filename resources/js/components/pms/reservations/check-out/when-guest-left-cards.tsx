import { DoorOpen, Moon } from 'lucide-react';
import { ChoiceCards } from '@/components/pms/choice-cards';
import { formatMoney } from '@/components/pms/money-text';
import type { CheckOutState } from './use-check-out-form';

/**
 * The cards that ask when the guest left while the night audit is pending: the Overdue pair for a guest due to leave
 * on the business date, and the left yesterday pair (the first card disabled with its reason when the guest arrived
 * that day). The server decides which one shows.
 */
export function WhenGuestLeftCards({
    state,
    currency,
}: {
    state: CheckOutState;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const {
        preview,
        overdue,
        pendingDay,
        overdueChoice,
        setOverdueChoice,
        leftYesterday,
        setLeftYesterday,
        form,
    } = state;

    if (preview === null) {
        return null;
    }

    return (
        <>
            {overdue !== null && (
                <ChoiceCards
                    id="check-out-overdue"
                    legend={`This guest was due to leave yesterday (${pendingDay}). What happened?`}
                    choices={[
                        {
                            value: 'left',
                            label: 'They left yesterday',
                            description: `Check out on ${pendingDay}. No extra night.`,
                            icon: DoorOpen,
                        },
                        {
                            value: 'stayed',
                            label: 'They stayed last night',
                            description: `Add ${pendingDay} (${formatMoney(overdue.nightTotal, currency)}), then check out today.`,
                            icon: Moon,
                        },
                    ]}
                    value={overdueChoice}
                    onValueChange={(value) =>
                        setOverdueChoice(value as 'left' | 'stayed')
                    }
                    error={form.errors.stayed_last_night}
                />
            )}
            {(leftYesterday || preview.leftYesterdayChoice) && (
                <ChoiceCards
                    id="check-out-left-yesterday"
                    legend={`When did the guest leave? Hotel day ${pendingDay}`}
                    choices={[
                        {
                            value: 'left',
                            label: 'They left yesterday',
                            description:
                                preview.leftYesterdayBlocked ??
                                `Check out on ${pendingDay}. The night is not charged. A reason is needed.`,
                            icon: DoorOpen,
                            disabled: preview.leftYesterdayBlocked !== null,
                        },
                        {
                            value: 'stayed',
                            label: 'They stayed last night',
                            description: `Check out today. ${pendingDay} is charged.`,
                            icon: Moon,
                        },
                    ]}
                    value={leftYesterday ? 'left' : 'stayed'}
                    onValueChange={(value) =>
                        setLeftYesterday(value === 'left')
                    }
                />
            )}
        </>
    );
}
