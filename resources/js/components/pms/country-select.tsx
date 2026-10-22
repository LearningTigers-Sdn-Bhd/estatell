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
    /** Input placeholder. Defaults to "Search countries". */
    placeholder?: string;
    /** Text when the search matches nothing. Defaults to "No matching countries." */
    emptyText?: string;
};

/**
 * A searchable picker for a short code list such as countries (or currencies, with `placeholder`). Type to filter the list; the clear button unsets the country.
 * The first match is highlighted as you type, so Enter picks it. Enter never submits the surrounding form.
 */
export function CountrySelect({
    id,
    name,
    value,
    onValueChange,
    options,
    invalid,
    placeholder = 'Search countries',
    emptyText = 'No matching countries.',
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
            autoHighlight
        >
            <ComboboxInput
                id={id}
                placeholder={placeholder}
                showClear
                aria-invalid={invalid}
                className="w-full"
                onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                    }
                }}
            />
            <ComboboxContent>
                <ComboboxEmpty>{emptyText}</ComboboxEmpty>
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
