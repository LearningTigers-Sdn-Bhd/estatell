export type DateColumn = {
    /** `Y-m-d`. */
    date: string;
    /** Day of the month, 1 to 31. */
    day: number;
    /** Short month name such as "Sep". */
    month: string;
    /** Short weekday name such as "Mon". */
    weekday: string;
    /** Monday is 1 and Sunday is 7, as in the pricing form. */
    isoWeekday: number;
    isToday: boolean;
    /** Whether the night of this date is a weekend night, by the property's weekend nights setting. */
    isWeekend: boolean;
};

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MONTHS = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
];

/**
 * One column for each `Y-m-d` date. Dates are read as calendar dates, so a time zone never shifts a day.
 *
 * @param weekendDays ISO weekdays (Monday is 1) whose nights are weekend nights.
 */
export function buildDateColumns(
    dates: string[],
    today: string,
    weekendDays: number[] = [],
): DateColumn[] {
    return dates.map((date) => {
        const [year, month, day] = date.split('-').map(Number);
        const weekday = new Date(year, month - 1, day).getDay();
        const isoWeekday = weekday === 0 ? 7 : weekday;

        return {
            date,
            day,
            month: MONTHS[month - 1],
            weekday: WEEKDAYS[weekday],
            isoWeekday,
            isToday: date === today,
            isWeekend: weekendDays.includes(isoWeekday),
        };
    });
}
