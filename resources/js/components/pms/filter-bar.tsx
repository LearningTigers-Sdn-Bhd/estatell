import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';

type Props = {
    search: string;
    onSearchChange: (search: string) => void;
    searchPlaceholder?: string;
    /** Filters such as `EnumSelect`s, shown after the search box. */
    children?: ReactNode;
};

/**
 * Search box plus filters for Index pages. The search is debounced so typing makes one request.
 */
export function FilterBar({
    search,
    onSearchChange,
    searchPlaceholder = 'Search',
    children,
}: Props) {
    const [draft, setDraft] = useState(search);

    useEffect(() => setDraft(search), [search]);

    useEffect(() => {
        if (draft === search) {
            return;
        }

        const timeout = setTimeout(() => onSearchChange(draft), 300);

        return () => clearTimeout(timeout);
    }, [draft, search, onSearchChange]);

    return (
        <div className="flex flex-wrap items-center gap-2">
            <InputGroup className="w-full sm:w-auto sm:flex-1">
                <InputGroupAddon>
                    <Search />
                </InputGroupAddon>
                <InputGroupInput
                    type="search"
                    value={draft}
                    onChange={(event) => setDraft(event.target.value)}
                    placeholder={searchPlaceholder}
                    aria-label={searchPlaceholder}
                />
            </InputGroup>
            {children}
        </div>
    );
}
