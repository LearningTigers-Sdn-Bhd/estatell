import type { EnumOption } from '@/components/pms/enum-select';

type Source = App.Data.Reservations.BookingSourceOptionData;

/**
 * The booking sources as Select options, grouped by type. An inactive source (only in the list filter) says so.
 */
export function sourceSelectOptions(sources: Source[]): EnumOption[] {
    return sources.map((source) => ({
        value: String(source.id),
        label: source.isActive ? source.name : `${source.name} (inactive)`,
        group: source.typeLabel,
    }));
}
