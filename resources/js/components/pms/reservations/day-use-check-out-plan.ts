import type { EnumOption } from '@/components/pms/enum-select';

type Preview = App.Data.Reservations.CheckOutAsDayUseData;

/** The Plan select value of **No plan · enter price**. */
export const NO_PLAN = 'none';

/** The plan fields of a request. Until staff touch the select nothing is sent, so each preview picks the default again. */
export function planPayload(choice: string): Record<string, string> {
    if (choice === '') {
        return {};
    }

    return choice === NO_PLAN ? { no_plan: '1' } : { rate_plan_id: choice };
}

/** The picked plan, else the plan the preview chose by default. */
export function planSelectValue(
    choice: string,
    preview: Pick<Preview, 'ratePlanId'> | null,
): string {
    if (choice !== '') {
        return choice;
    }

    return preview?.ratePlanId != null ? String(preview.ratePlanId) : NO_PLAN;
}

/** The Day use plans, a plan that cannot price the stay disabled with its reason, then **No plan · enter price**. */
export function checkOutPlanOptions(
    preview: Pick<Preview, 'planOptions' | 'perNightPlanName'>,
): EnumOption[] {
    return [
        ...preview.planOptions.map((plan) => ({
            value: String(plan.id),
            label: plan.name,
            group: 'Day use plans',
            disabled: plan.disabledReason !== null,
            description: plan.disabledReason ?? undefined,
        })),
        {
            value: NO_PLAN,
            label: 'No plan · enter price',
            description:
                preview.perNightPlanName === null
                    ? undefined
                    : `Keeps ${preview.perNightPlanName}`,
        },
    ];
}

/** The errors to show: a missing amount is only an error once staff press save. */
export function visibleErrors(
    errors: Record<string, string>,
    refusal: string | null | undefined,
    saveAttempted: boolean,
): Record<string, string> {
    if (refusal !== 'amount_required' || saveAttempted) {
        return errors;
    }

    return Object.fromEntries(
        Object.entries(errors).filter(([field]) => field !== 'amount'),
    );
}
