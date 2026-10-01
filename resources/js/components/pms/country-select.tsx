import type { EnumOption } from '@/components/pms/enum-select';
import {
    Combobox,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
} from '@/components/ui/combobox';

type Props = {
    id: string;
    name?: string;
    /** ISO 3166-1 alpha-2 code, or an empty string when no country is chosen. */
    value: string;
    onValueChange: (value: string) => void;
    /** Country options from `CountryOptions::options()`. */
    options: EnumOption[];
    invalid?: boolean;
};

/**
 * A searchable country picker. Type to filter the list; the clear button unsets the country.
 */
export function CountrySelect({
    id,
    name,
    value,
    onValueChange,
    options,
    invalid,
}: Props) {
    const selected = options.find((option) => option.value === value) ?? null;

    return (
        <Combobox
            items={options}
            name={name}
            value={selected}
            onValueChange={(next) =>
                onValueChange((next as EnumOption | null)?.value ?? '')
            }
            itemToStringLabel={(item) => item.label}
            itemToStringValue={(item) => item.value}
        >
            <ComboboxInput
                id={id}
                placeholder="Search countries"
                showClear
                aria-invalid={invalid}
                className="w-full"
            />
            <ComboboxContent>
                <ComboboxEmpty>No matching countries.</ComboboxEmpty>
                <ComboboxList>
                    {(item: EnumOption) => (
                        <ComboboxItem key={item.value} value={item}>
                            {item.label}
                        </ComboboxItem>
                    )}
                </ComboboxList>
            </ComboboxContent>
        </Combobox>
    );
}
