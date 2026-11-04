import { router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import type { PaginationData } from '@/components/pms/pagination-footer';
import { paginationUrl } from '@/components/pms/pagination-navigation';

type Amenity = App.Data.BookingEngine.AmenityData;

export type AmenityView = 'grouped' | 'list';

const LIST_PAGE_SIZE = 50;

/** The address of a page in the List view, so a refresh stays on that view and page. */
function listPageUrl(listUrl: string, page: number): string {
    const url = new URL(paginationUrl(listUrl, page));

    url.searchParams.set('view', 'list');

    return url.href;
}

/**
 * What the admin and owner amenity tables share: search, the Grouped or List view, open groups and List pages.
 * The rows come in list order (by category, then position), and the browser derives everything from them.
 */
export function useAmenityTable(
    amenities: Amenity[],
    page: number,
    listUrl: string,
) {
    const { url } = usePage();
    const [search, setSearch] = useState('');
    const [view, setView] = useState<AmenityView>(() =>
        new URL(url, 'http://localhost').searchParams.get('view') === 'list'
            ? 'list'
            : 'grouped',
    );
    const [openGroups, setOpenGroups] = useState<number[]>([]);

    const term = search.trim().toLowerCase();

    /** Keeps the view in the URL (`?view=list`) with no request, and goes back to the first page of the list. */
    function changeView(next: AmenityView) {
        setView(next);

        const target = new URL(url, 'http://localhost');

        target.searchParams.delete('page');

        if (next === 'list') {
            target.searchParams.set('view', 'list');
        } else {
            target.searchParams.delete('view');
        }

        router.replace({
            url: `${target.pathname}${target.search}`,
            props: (current) => ({ ...current, page: 1 }),
            preserveScroll: true,
            preserveState: true,
        });
    }

    const visible = useMemo(
        () =>
            amenities.filter(
                (amenity) =>
                    term === '' ||
                    amenity.name.toLowerCase().includes(term) ||
                    amenity.category.toLowerCase().includes(term),
            ),
        [amenities, term],
    );

    const groups = useMemo(() => {
        const byCategory = new Map<
            number,
            { id: number; name: string; rows: Amenity[] }
        >();

        for (const amenity of visible) {
            const group = byCategory.get(amenity.categoryId);

            if (group) {
                group.rows.push(amenity);
            } else {
                byCategory.set(amenity.categoryId, {
                    id: amenity.categoryId,
                    name: amenity.category,
                    rows: [amenity],
                });
            }
        }

        return [...byCategory.values()];
    }, [visible]);

    /** Search opens every group that has a match, so nothing it found stays hidden. */
    const isGroupOpen = (categoryId: number) =>
        term !== '' || openGroups.includes(categoryId);

    function toggleGroup(categoryId: number) {
        setOpenGroups((current) =>
            current.includes(categoryId)
                ? current.filter((id) => id !== categoryId)
                : [...current, categoryId],
        );
    }

    const lastPage = Math.max(1, Math.ceil(visible.length / LIST_PAGE_SIZE));
    const currentPage = Math.min(page, lastPage);
    const firstRow = (currentPage - 1) * LIST_PAGE_SIZE;
    const listRows =
        term === ''
            ? visible.slice(firstRow, firstRow + LIST_PAGE_SIZE)
            : visible;
    const pagination: PaginationData = {
        currentPage,
        lastPage,
        from: firstRow + 1,
        to: firstRow + listRows.length,
        total: visible.length,
        prevUrl: currentPage > 1 ? listPageUrl(listUrl, currentPage - 1) : null,
        nextUrl:
            currentPage < lastPage
                ? listPageUrl(listUrl, currentPage + 1)
                : null,
    };

    return {
        search,
        setSearch,
        term,
        view,
        changeView,
        visible,
        groups,
        isGroupOpen,
        toggleGroup,
        listRows,
        pagination,
    };
}
