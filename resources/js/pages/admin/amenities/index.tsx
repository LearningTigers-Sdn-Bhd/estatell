import { Head, router, usePage } from '@inertiajs/react';
import {
    BedDouble,
    Building2,
    ChevronDown,
    ChevronRight,
    ChevronUp,
    List,
    ListChecks,
    ListTree,
    Plus,
    Tags,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';
import { AmenityCategoryDialog } from '@/components/pms/amenities/amenity-category-dialog';
import { AmenityCategoryTable } from '@/components/pms/amenities/amenity-category-table';
import { AmenityDialog } from '@/components/pms/amenities/amenity-dialog';
import { EmptyState } from '@/components/pms/empty-state';
import type { EnumOption } from '@/components/pms/enum-select';
import { FilterBar } from '@/components/pms/filter-bar';
import { PageHeader } from '@/components/pms/page-header';
import { PaginationFooter } from '@/components/pms/pagination-footer';
import type { PaginationData } from '@/components/pms/pagination-footer';
import { paginationUrl } from '@/components/pms/pagination-navigation';
import { StatusBadge } from '@/components/pms/status-badge';
import { TabLinks } from '@/components/pms/tab-links';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes/admin';
import { index, reorder } from '@/routes/admin/amenities';
import { update as updateActive } from '@/routes/admin/amenities/active';

type Amenity = App.Data.BookingEngine.AmenityData;
type Category = App.Data.BookingEngine.AmenityCategoryData;
type View = 'grouped' | 'list';

const CATEGORIES = 'categories';
const LIST_PAGE_SIZE = 50;

const tabIcons: Record<string, LucideIcon> = {
    property: Building2,
    room: BedDouble,
    [CATEGORIES]: Tags,
};

/**
 * Swap an amenity with its neighbour in its category and send the whole order. The server checks that the ids are the full list.
 */
function moveAmenity(
    amenities: Amenity[],
    group: Amenity[],
    position: number,
    step: -1 | 1,
) {
    const ids = amenities.map((amenity) => amenity.id);
    const from = ids.indexOf(group[position].id);
    const to = ids.indexOf(group[position + step].id);

    [ids[from], ids[to]] = [ids[to], ids[from]];

    router.put(
        reorder.url(),
        { order: ids },
        { preserveScroll: true, preserveState: true },
    );
}

/** The address of a page in the List view, so a refresh stays on that view and page. */
function listPageUrl(listUrl: string, page: number): string {
    const url = new URL(paginationUrl(listUrl, page));

    url.searchParams.set('view', 'list');

    return url.href;
}

function toggleAmenity(amenity: Amenity) {
    router.put(
        updateActive.url(amenity.id),
        { active: !amenity.active },
        { preserveScroll: true, preserveState: true },
    );
}

function AmenityRow({
    amenity,
    showCategory,
    onEdit,
    onMoveUp,
    onMoveDown,
}: {
    amenity: Amenity;
    showCategory: boolean;
    onEdit: () => void;
    /** Leave out to hide the move buttons, or pass null to show that one disabled. */
    onMoveUp?: (() => void) | null;
    onMoveDown?: (() => void) | null;
}) {
    return (
        <TableRow>
            <TableCell className="font-medium">{amenity.name}</TableCell>
            {showCategory && (
                <TableCell className="text-muted-foreground">
                    {amenity.category}
                </TableCell>
            )}
            <TableCell>
                <StatusBadge
                    status={
                        amenity.active
                            ? { value: 'on', label: 'On', tone: 'success' }
                            : {
                                  value: 'retired',
                                  label: 'Retired',
                                  tone: 'neutral',
                              }
                    }
                />
            </TableCell>
            <TableCell>
                <div className="flex items-center justify-end gap-1">
                    {onMoveUp !== undefined && (
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            aria-label={`Move ${amenity.name} up`}
                            disabled={onMoveUp === null}
                            onClick={onMoveUp ?? undefined}
                        >
                            <ChevronUp />
                        </Button>
                    )}
                    {onMoveDown !== undefined && (
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            aria-label={`Move ${amenity.name} down`}
                            disabled={onMoveDown === null}
                            onClick={onMoveDown ?? undefined}
                        >
                            <ChevronDown />
                        </Button>
                    )}
                    <Button variant="outline" size="sm" onClick={onEdit}>
                        Edit
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => toggleAmenity(amenity)}
                    >
                        {amenity.active ? 'Retire' : 'Turn on'}
                    </Button>
                </div>
            </TableCell>
        </TableRow>
    );
}

export default function AmenitiesIndex({
    amenities,
    categories,
    typeOptions,
    tab,
    page,
    listUrl,
}: {
    amenities: Amenity[];
    categories: Category[];
    typeOptions: EnumOption[];
    tab: string;
    page: number;
    listUrl: string;
}) {
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<Amenity | null>(null);
    const [search, setSearch] = useState('');
    const { url } = usePage();
    const [view, setView] = useState<View>(() =>
        new URL(url, 'http://localhost').searchParams.get('view') === 'list'
            ? 'list'
            : 'grouped',
    );
    const [openGroups, setOpenGroups] = useState<number[]>([]);

    /** Keeps the view in the URL (`?view=list`) with no request, and goes back to the first page of the list. */
    function changeView(next: View) {
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

    const showingCategories = tab === CATEGORIES;
    const term = search.trim().toLowerCase();

    const visible = useMemo(
        () =>
            amenities.filter(
                (amenity) =>
                    amenity.type === tab &&
                    (term === '' ||
                        amenity.name.toLowerCase().includes(term) ||
                        amenity.category.toLowerCase().includes(term)),
            ),
        [amenities, tab, term],
    );

    const groups = useMemo(
        () =>
            categories
                .map((category) => ({
                    category,
                    rows: visible.filter(
                        (amenity) => amenity.categoryId === category.id,
                    ),
                }))
                .filter((group) => group.rows.length > 0),
        [categories, visible],
    );

    const categoryOptions = (type: string): EnumOption[] =>
        categories
            .filter((category) => category.type === type)
            .map((category) => ({
                value: String(category.id),
                label: category.name,
            }));

    const columnCount = view === 'list' ? 4 : 3;

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

    return (
        <>
            <Head title="Amenities" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Amenities"
                    description="The shared list owners pick from. A retired amenity leaves the pickers and stays where it is already ticked."
                    actions={
                        <Button onClick={() => setAdding(true)}>
                            <Plus data-icon="inline-start" />
                            {showingCategories ? 'Add category' : 'Add amenity'}
                        </Button>
                    }
                />

                <TabLinks
                    activeTab={tab}
                    panelWidth="full"
                    className="gap-4"
                    triggerClassName="min-w-0 px-0 mr-6"
                    panelClassName="flex flex-col gap-4"
                    tabs={[
                        ...typeOptions,
                        { value: CATEGORIES, label: 'Categories' },
                    ].map((option) => ({
                        value: option.value,
                        label: option.label,
                        icon: tabIcons[option.value] ?? ListChecks,
                        href: index({
                            query: {
                                tab: option.value,
                                ...(view === 'list' ? { view } : {}),
                            },
                        }),
                    }))}
                >
                    {showingCategories ? (
                        <AmenityCategoryTable
                            categories={categories}
                            typeOptions={typeOptions}
                        />
                    ) : (
                        <>
                            <FilterBar
                                search={search}
                                onSearchChange={setSearch}
                                searchPlaceholder="Search by name or category"
                            >
                                <ToggleGroup
                                    variant="outline"
                                    spacing={0}
                                    value={[view]}
                                    onValueChange={(value) =>
                                        value[0] && changeView(value[0] as View)
                                    }
                                    aria-label="View"
                                >
                                    <ToggleGroupItem value="grouped">
                                        <ListTree data-icon="inline-start" />
                                        Grouped
                                    </ToggleGroupItem>
                                    <ToggleGroupItem value="list">
                                        <List data-icon="inline-start" />
                                        List
                                    </ToggleGroupItem>
                                </ToggleGroup>
                            </FilterBar>

                            <div className="overflow-hidden rounded-lg border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Amenity</TableHead>
                                            {view === 'list' && (
                                                <TableHead>Category</TableHead>
                                            )}
                                            <TableHead>Status</TableHead>
                                            <TableHead>
                                                <span className="sr-only">
                                                    Actions
                                                </span>
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {view === 'list'
                                            ? listRows.map((amenity) => (
                                                  <AmenityRow
                                                      key={amenity.id}
                                                      amenity={amenity}
                                                      showCategory
                                                      onEdit={() =>
                                                          setEditing(amenity)
                                                      }
                                                  />
                                              ))
                                            : groups.map(
                                                  ({ category, rows }) => (
                                                      <Fragment
                                                          key={category.id}
                                                      >
                                                          <TableRow className="bg-muted/50 hover:bg-muted/50">
                                                              <TableCell
                                                                  colSpan={
                                                                      columnCount
                                                                  }
                                                                  className="p-0"
                                                              >
                                                                  <button
                                                                      type="button"
                                                                      aria-expanded={isGroupOpen(
                                                                          category.id,
                                                                      )}
                                                                      disabled={
                                                                          term !==
                                                                          ''
                                                                      }
                                                                      onClick={() =>
                                                                          toggleGroup(
                                                                              category.id,
                                                                          )
                                                                      }
                                                                      className="flex w-full items-center gap-2 p-2 text-left font-medium"
                                                                  >
                                                                      <ChevronRight
                                                                          aria-hidden
                                                                          className={cn(
                                                                              'size-4 text-muted-foreground transition-transform',
                                                                              isGroupOpen(
                                                                                  category.id,
                                                                              ) &&
                                                                                  'rotate-90',
                                                                          )}
                                                                      />
                                                                      {
                                                                          category.name
                                                                      }
                                                                      <span className="font-normal text-muted-foreground tabular-nums">
                                                                          (
                                                                          {
                                                                              rows.length
                                                                          }
                                                                          )
                                                                      </span>
                                                                  </button>
                                                              </TableCell>
                                                          </TableRow>
                                                          {isGroupOpen(
                                                              category.id,
                                                          ) &&
                                                              rows.map(
                                                                  (
                                                                      amenity,
                                                                      position,
                                                                  ) => (
                                                                      <AmenityRow
                                                                          key={
                                                                              amenity.id
                                                                          }
                                                                          amenity={
                                                                              amenity
                                                                          }
                                                                          showCategory={
                                                                              false
                                                                          }
                                                                          onEdit={() =>
                                                                              setEditing(
                                                                                  amenity,
                                                                              )
                                                                          }
                                                                          onMoveUp={
                                                                              term !==
                                                                              ''
                                                                                  ? undefined
                                                                                  : position ===
                                                                                      0
                                                                                    ? null
                                                                                    : () =>
                                                                                          moveAmenity(
                                                                                              amenities,
                                                                                              rows,
                                                                                              position,
                                                                                              -1,
                                                                                          )
                                                                          }
                                                                          onMoveDown={
                                                                              term !==
                                                                              ''
                                                                                  ? undefined
                                                                                  : position ===
                                                                                      rows.length -
                                                                                          1
                                                                                    ? null
                                                                                    : () =>
                                                                                          moveAmenity(
                                                                                              amenities,
                                                                                              rows,
                                                                                              position,
                                                                                              1,
                                                                                          )
                                                                          }
                                                                      />
                                                                  ),
                                                              )}
                                                      </Fragment>
                                                  ),
                                              )}
                                        {visible.length === 0 && (
                                            <TableRow className="hover:bg-transparent">
                                                <TableCell
                                                    colSpan={columnCount}
                                                >
                                                    {term === '' ? (
                                                        <EmptyState
                                                            icon={ListChecks}
                                                            title="No amenities yet"
                                                            description="Add the first one so owners can pick it."
                                                            action={
                                                                <Button
                                                                    onClick={() =>
                                                                        setAdding(
                                                                            true,
                                                                        )
                                                                    }
                                                                >
                                                                    <Plus data-icon="inline-start" />
                                                                    Add amenity
                                                                </Button>
                                                            }
                                                        />
                                                    ) : (
                                                        <EmptyState
                                                            icon={ListChecks}
                                                            title="No amenities match your search"
                                                            action={
                                                                <Button
                                                                    variant="outline"
                                                                    onClick={() =>
                                                                        setSearch(
                                                                            '',
                                                                        )
                                                                    }
                                                                >
                                                                    Clear search
                                                                </Button>
                                                            }
                                                        />
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        )}
                                    </TableBody>
                                </Table>
                            </div>

                            {view === 'list' && term === '' && (
                                <PaginationFooter pagination={pagination} />
                            )}
                        </>
                    )}
                </TabLinks>
            </div>

            {showingCategories ? (
                <AmenityCategoryDialog
                    typeOptions={typeOptions}
                    open={adding}
                    onOpenChange={setAdding}
                />
            ) : (
                <AmenityDialog
                    key={tab}
                    categories={categoryOptions(tab)}
                    open={adding}
                    onOpenChange={setAdding}
                />
            )}
            {editing && (
                <AmenityDialog
                    amenity={editing}
                    categories={categoryOptions(editing.type)}
                    open
                    onOpenChange={(open) => !open && setEditing(null)}
                />
            )}
        </>
    );
}

AmenitiesIndex.layout = {
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Amenities', href: index() },
    ],
};
