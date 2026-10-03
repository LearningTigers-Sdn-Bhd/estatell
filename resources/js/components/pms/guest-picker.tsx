import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import {
    Combobox,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
} from '@/components/ui/combobox';
import {
    GUEST_SEARCH_MIN_LENGTH,
    useGuestSearch,
} from '@/hooks/use-guest-search';

type Guest = App.Data.Guests.GuestOptionData;

/**
 * Pick one existing guest. The server searches name, email and phone and sends the first few matches, so the list
 * is never filtered in the browser. The request waits for a pause in typing, and a slow answer to an older request
 * never replaces a newer one.
 */
export function GuestPicker({
    id,
    value,
    onChange,
    invalid,
}: {
    id?: string;
    value: Guest | null;
    onChange: (guest: Guest | null) => void;
    invalid?: boolean;
}) {
    const [query, setQuery] = useState('');
    const { results, searching } = useGuestSearch(query);
    const text = query.trim();

    const items =
        value && !results.some((guest) => guest.id === value.id)
            ? [...results, value]
            : results;

    return (
        <Combobox
            items={items}
            value={value}
            onValueChange={(next) => onChange(next as Guest | null)}
            onInputValueChange={(next, details) => {
                if (details.reason !== 'item-press') {
                    setQuery(next);
                }
            }}
            itemToStringLabel={(guest: Guest) => guest.fullName}
            isItemEqualToValue={(item: Guest, selected: Guest) =>
                item.id === selected.id
            }
            filter={null}
        >
            <ComboboxInput
                id={id}
                className="w-full"
                placeholder="Search by name, phone or email"
                autoComplete="off"
                showClear
                aria-invalid={invalid}
            />
            <ComboboxContent>
                <ComboboxEmpty>
                    {searching
                        ? 'Searching…'
                        : text.length < GUEST_SEARCH_MIN_LENGTH
                          ? 'Type at least 2 letters to search.'
                          : 'No guests found.'}
                </ComboboxEmpty>
                <ComboboxList>
                    {(guest: Guest) => (
                        <ComboboxItem key={guest.id} value={guest}>
                            <span className="flex min-w-0 flex-1 flex-col">
                                <span className="flex items-center gap-2 truncate">
                                    {guest.fullName}
                                    {guest.isVip && <Badge>VIP</Badge>}
                                    {guest.isBlacklisted && (
                                        <Badge variant="destructive">
                                            Blacklisted
                                        </Badge>
                                    )}
                                </span>
                                <span className="truncate text-xs text-muted-foreground">
                                    {[guest.phone, guest.email]
                                        .filter(Boolean)
                                        .join(' · ') || 'No contact details'}
                                </span>
                            </span>
                        </ComboboxItem>
                    )}
                </ComboboxList>
            </ComboboxContent>
        </Combobox>
    );
}
