import {
    Combobox,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
} from '@/components/ui/combobox';

type Company = App.Data.CityLedger.CompanyOptionData;

/**
 * A searchable company picker over the Active companies the server sent. The list is bounded, so the browser
 * filters it. The clear button unsets the company.
 */
export function CompanySelect({
    id,
    value,
    onValueChange,
    companies,
    invalid,
}: {
    id: string;
    /** The company id as text, or an empty string when none is chosen. */
    value: string;
    onValueChange: (value: string) => void;
    companies: Company[];
    invalid?: boolean;
}) {
    const selected =
        companies.find((company) => String(company.id) === value) ?? null;

    return (
        <Combobox
            items={companies}
            value={selected}
            onValueChange={(next) =>
                onValueChange(next ? String((next as Company).id) : '')
            }
            itemToStringLabel={(item) => item.name}
            itemToStringValue={(item) => String(item.id)}
        >
            <ComboboxInput
                id={id}
                placeholder="No company"
                showClear
                aria-invalid={invalid}
                className="w-full"
            />
            <ComboboxContent>
                <ComboboxEmpty>No matching companies.</ComboboxEmpty>
                <ComboboxList>
                    {(item: Company) => (
                        <ComboboxItem key={item.id} value={item}>
                            {item.name}
                            {item.marketLabel && (
                                <span className="text-muted-foreground">
                                    {' '}
                                    ({item.marketLabel})
                                </span>
                            )}
                        </ComboboxItem>
                    )}
                </ComboboxList>
            </ComboboxContent>
        </Combobox>
    );
}
