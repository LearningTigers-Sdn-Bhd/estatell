import { useState } from 'react';
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

type TagOption = { value: string; label: string };

type Props = {
    id: string;
    name?: string;
    value: string[];
    onChange: (value: string[]) => void;
    /** Names of the property's existing tags. */
    suggestions: string[];
    invalid?: boolean;
};

const MAX_LENGTH = 50;

/**
 * A multi-select combobox for a guest's tags. Pick an existing tag, or type a new name and choose
 * "Create". A new name creates the tag when the guest is saved.
 */
export function TagInput({
    id,
    name,
    value,
    onChange,
    suggestions,
    invalid,
}: Props) {
    const anchor = useComboboxAnchor();
    const [query, setQuery] = useState('');

    const known = new Set(
        [...suggestions, ...value].map((tag) => tag.toLowerCase()),
    );
    const typed = query.trim().slice(0, MAX_LENGTH);
    const items: TagOption[] = [
        ...[...new Set([...suggestions, ...value])].map((tag) => ({
            value: tag,
            label: tag,
        })),
        ...(typed !== '' && !known.has(typed.toLowerCase())
            ? [{ value: typed, label: `Create "${typed}"` }]
            : []),
    ];
    const selected = value.map((tag) => ({ value: tag, label: tag }));

    return (
        <Combobox
            items={items}
            multiple
            autoHighlight
            name={name}
            value={selected}
            inputValue={query}
            onInputValueChange={setQuery}
            onValueChange={(next) => {
                onChange((next as TagOption[]).map((item) => item.value));
                setQuery('');
            }}
            isItemEqualToValue={(a, b) => a.value === b.value}
            itemToStringLabel={(item) => item.label}
            itemToStringValue={(item) => item.value}
        >
            <ComboboxValue>
                {(selectedItems: TagOption[]) => (
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
                            maxLength={MAX_LENGTH}
                            placeholder={
                                selectedItems.length === 0
                                    ? 'Search or create tags'
                                    : ''
                            }
                        />
                    </ComboboxChips>
                )}
            </ComboboxValue>
            <ComboboxContent anchor={anchor}>
                <ComboboxEmpty>Type a name to create a tag.</ComboboxEmpty>
                <ComboboxList>
                    {(item: TagOption) => (
                        <ComboboxItem key={item.value} value={item}>
                            {item.label}
                        </ComboboxItem>
                    )}
                </ComboboxList>
            </ComboboxContent>
        </Combobox>
    );
}
