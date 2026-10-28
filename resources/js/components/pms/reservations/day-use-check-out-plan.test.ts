import { describe, expect, test } from 'vitest';
import {
    checkOutPlanOptions,
    NO_PLAN,
    planPayload,
    planSelectValue,
    visibleErrors,
} from './day-use-check-out-plan';

describe('planPayload', () => {
    test('sends nothing until staff pick', () => {
        expect(planPayload('')).toEqual({});
    });

    test('sends the plan or no plan', () => {
        expect(planPayload('7')).toEqual({ rate_plan_id: '7' });
        expect(planPayload(NO_PLAN)).toEqual({ no_plan: '1' });
    });
});

describe('planSelectValue', () => {
    test('follows the default of the preview until staff pick', () => {
        expect(planSelectValue('', { ratePlanId: 3 })).toBe('3');
        expect(planSelectValue('', { ratePlanId: null })).toBe(NO_PLAN);
        expect(planSelectValue('', null)).toBe(NO_PLAN);
    });

    test('keeps the staff choice', () => {
        expect(planSelectValue('9', { ratePlanId: 3 })).toBe('9');
    });
});

describe('checkOutPlanOptions', () => {
    test('disables a refused plan with its reason and ends with No plan', () => {
        const options = checkOutPlanOptions({
            perNightPlanName: 'Standard Rate',
            planOptions: [
                {
                    id: 1,
                    name: 'Promo',
                    price: null,
                    disabledReason: 'Sells 2–6 hours',
                },
                {
                    id: 2,
                    name: 'Standard',
                    price: '95.00',
                    disabledReason: null,
                },
            ],
        });

        expect(options.map((option) => option.value)).toEqual([
            '1',
            '2',
            NO_PLAN,
        ]);
        expect(options[0]).toMatchObject({
            disabled: true,
            description: 'Sells 2–6 hours',
        });
        expect(options[1].disabled).toBe(false);
        expect(options[2].description).toBe('Keeps Standard Rate');
    });
});

describe('visibleErrors', () => {
    const errors = { amount: 'Enter an amount', start: 'Bad' };

    test('hides the missing amount until save is pressed', () => {
        expect(visibleErrors(errors, 'amount_required', false)).toEqual({
            start: 'Bad',
        });
        expect(visibleErrors(errors, 'amount_required', true)).toEqual(errors);
        expect(visibleErrors(errors, 'hours_invalid', false)).toEqual(errors);
    });
});
