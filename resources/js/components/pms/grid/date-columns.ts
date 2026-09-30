export type DateColumn = {
    /** `Y-m-d`. */
    date: string;
    /** Day of the month, 1 to 31. */
    day: number;
    /** Short weekday name such as "Mon". */
    weekday: string;
    /** Monday is 1 and Sunday is 7, as in the pricing form. */
    isoWeekday: number;
    isToday: boolean;
};

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

/**
 * One column for each `Y-m-d` date. Dates are read as calendar dates, so a time zone never shifts a day.
 */
export function buildDateColumns(dates: string[], today: string): DateColumn[] {
    return dates.map((date) => {
        const [year, month, day] = date.split('-').map(Number);
        const weekday = new Date(year, month - 1, day).getDay();

        return {
            date,
            day,
            weekday: WEEKDAYS[weekday],
            isoWeekday: weekday === 0 ? 7 : weekday,
            isToday: date === today,
        };
    });
}
