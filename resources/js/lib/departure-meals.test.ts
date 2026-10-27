import { expect, test } from 'vite-plus/test';
import { departureMeals } from './departure-meals';

const mealPeriods = [
    {
        id: 1,
        name: 'Breakfast',
        startsAt: '07:00',
        endsAt: '10:00',
        active: true,
    },
    { id: 2, name: 'Lunch', startsAt: '12:00', endsAt: '14:30', active: true },
    { id: 3, name: 'Dinner', startsAt: '19:00', endsAt: '22:00', active: true },
];

test('boat in counts the meals that end after the boat arrives', () => {
    expect(departureMeals('in', '11:45', '12:30', mealPeriods)).toEqual([
        'Lunch',
        'Dinner',
    ]);
    expect(departureMeals('in', '13:45', '14:30', mealPeriods)).toEqual([
        'Dinner',
    ]);
});

test('boat out counts the meals that start before the boat leaves', () => {
    expect(departureMeals('out', '12:00', '12:45', mealPeriods)).toEqual([
        'Breakfast',
    ]);
    expect(departureMeals('out', '14:30', '15:15', mealPeriods)).toEqual([
        'Breakfast',
        'Lunch',
    ]);
});
