import {
    Combobox,
    ComboboxChip,
    ComboboxChips,
    ComboboxChipsInput,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxItem,
    ComboboxList,
    ComboboxValue,
    useComboboxAnchor,
} from '@/components/ui/combobox';

export type MultiComboboxOption = {
    value: string;
    label: string;
    /** A second line in the dropdown, for example how the item is sold or why it is disabled. */
    description?: string;
    disabled?: boolean;
};

export function MultiCombobox({
    id,
    name,
    items,
    value,
    onValueChange,
    placeholder,
    emptyLabel = 'No matching charge codes.',
    invalid,
}: {
    id: string;
    name: string;
    items: MultiComboboxOption[];
    value: string[];
    onValueChange: (value: string[]) => void;
    placeholder: string;
    emptyLabel?: string;
    invalid?: boolean;
}) {
    const anchor = useComboboxAnchor();
    const selected = items.filter((item) => value.includes(item.value));

    return (
        <Combobox
            items={items}
            multiple
            name={name}
            value={selected}
            onValueChange={(next) =>
                onValueChange(
                    (next as MultiComboboxOption[]).map((item) => item.value),
                )
            }
            itemToStringLabel={(item) => item.label}
            itemToStringValue={(item) => item.value}
        >
            <ComboboxValue>
                {(selectedItems: MultiComboboxOption[]) => (
                    <ComboboxChips ref={anchor} aria-invalid={invalid}>
                        {selectedItems.map((item) => (
                            <ComboboxChip
                                key={item.value}
                                aria-label={item.label}
                            >
                                {item.label}
                            </ComboboxChip>
                        ))}
                        <ComboboxChipsInput
                            id={id}
                            placeholder={
                                selectedItems.length === 0 ? placeholder : ''
                            }
                        />
                    </ComboboxChips>
                )}
            </ComboboxValue>
            <ComboboxContent anchor={anchor}>
                <ComboboxEmpty>{emptyLabel}</ComboboxEmpty>
                <ComboboxList>
                    {(item: MultiComboboxOption) => (
                        <ComboboxItem
                            key={item.value}
                            value={item}
                            disabled={item.disabled}
                        >
                            {item.description ? (
                                <span className="flex flex-col">
                                    <span>{item.label}</span>
                                    <span className="text-xs text-muted-foreground">
                                        {item.description}
                                    </span>
                                </span>
                            ) : (
                                item.label
                            )}
                        </ComboboxItem>
                    )}
                </ComboboxList>
            </ComboboxContent>
        </Combobox>
    );
}
