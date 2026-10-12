import { Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
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
 * Search box plus filters for Index pages. The search is debounced so typing makes one request. Text typed while a
 * request is in flight is kept; the box follows the `search` prop only when something else changes it (a cleared filter).
 */
export function FilterBar({
    search,
    onSearchChange,
    searchPlaceholder = 'Search',
    children,
}: Props) {
    const [draft, setDraft] = useState(search);
    const latest = useRef(onSearchChange);
    /** The last value the box sent up, so the page echoing it back is not mistaken for an outside change. */
    const sent = useRef(search);

    useEffect(() => {
        latest.current = onSearchChange;
    }, [onSearchChange]);

    useEffect(() => {
        if (search !== sent.current) {
            sent.current = search;
            setDraft(search);
        }
    }, [search]);

    useEffect(() => {
        if (draft === sent.current) {
            return;
        }

        const timeout = setTimeout(() => {
            sent.current = draft;
            latest.current(draft);
        }, 300);

        return () => clearTimeout(timeout);
    }, [draft]);

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
