import { Head, router } from '@inertiajs/react';
import {
    BedDouble,
    Building2,
    ChevronDown,
    ChevronUp,
    ListChecks,
    Plus,
    Tags,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { AmenityCategoryDialog } from '@/components/pms/amenities/amenity-category-dialog';
import { AmenityCategoryTable } from '@/components/pms/amenities/amenity-category-table';
import { AmenityDialog } from '@/components/pms/amenities/amenity-dialog';
import {
    AmenityGroupRow,
    AmenityViewToggle,
} from '@/components/pms/amenities/amenity-table-parts';
import { useAmenityTable } from '@/components/pms/amenities/use-amenity-table';
import { EmptyState } from '@/components/pms/empty-state';
import type { EnumOption } from '@/components/pms/enum-select';
import { FilterBar } from '@/components/pms/filter-bar';
import { PageHeader } from '@/components/pms/page-header';
import { PaginationFooter } from '@/components/pms/pagination-footer';
import { TabLinks, withTabCrumb } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { dashboard } from '@/routes/admin';
import { index, reorder } from '@/routes/admin/amenities';
import { update as updateActive } from '@/routes/admin/amenities/active';

type Amenity = App.Data.BookingEngine.AmenityData;
type Category = App.Data.BookingEngine.AmenityCategoryData;

const CATEGORIES = 'categories';

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
    const [saving, setSaving] = useState(false);

    /** Off retires the amenity. The switch flips at once and follows the server's answer. It is async so a quick next switch does not cancel this save. */
    function saveActive(active: boolean) {
        router
            .optimistic<{ amenities: Amenity[] }>((props) => ({
                amenities: props.amenities.map((item) =>
                    item.id === amenity.id ? { ...item, active } : item,
                ),
            }))
            .put(
                updateActive.url(amenity.id),
                { active },
                {
                    async: true,
                    preserveScroll: true,
                    preserveState: true,
                    onStart: () => setSaving(true),
                    onFinish: () => setSaving(false),
                },
            );
    }

    return (
        <TableRow>
            <TableCell>
                <Switch
                    size="sm"
                    checked={amenity.active}
                    disabled={saving}
                    aria-label={`Make ${amenity.name} ${amenity.active ? 'unavailable' : 'available'}`}
                    onCheckedChange={saveActive}
                />
            </TableCell>
            <TableCell className="font-medium">{amenity.name}</TableCell>
            {showCategory && (
                <TableCell className="text-muted-foreground">
                    {amenity.category}
                </TableCell>
            )}
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
                </div>
            </TableCell>
        </TableRow>
    );
}

/**
 * One tab per amenity type, then Categories, for the tab bar and the breadcrumbs. The tab bar adds a count badge.
 */
function amenityTabs(
    typeOptions: EnumOption[],
    view?: string,
    badge?: (value: string) => ReactNode,
): TabLink[] {
    return [...typeOptions, { value: CATEGORIES, label: 'Categories' }].map(
        (option) => ({
            value: option.value,
            title: option.label,
            label: badge ? (
                <>
                    {option.label}
                    {badge(option.value)}
                </>
            ) : (
                option.label
            ),
            icon: tabIcons[option.value] ?? ListChecks,
            href: index({
                query: {
                    tab: option.value,
                    ...(view === 'list' ? { view } : {}),
                },
            }),
        }),
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
    const showingCategories = tab === CATEGORIES;
    const ofTab = useMemo(
        () => amenities.filter((amenity) => amenity.type === tab),
        [amenities, tab],
    );
    const table = useAmenityTable(ofTab, page, listUrl);
    const { view, term } = table;

    const categoryOptions = (type: string): EnumOption[] =>
        categories
            .filter((category) => category.type === type)
            .map((category) => ({
                value: String(category.id),
                label: category.name,
            }));

    const columnCount = view === 'list' ? 4 : 3;

    return (
        <>
            <Head title="Amenities" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Amenities"
                    description="The shared list owners pick from. Turn an amenity off to retire it. It leaves the pickers and stays where it is already ticked."
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
                    tabs={amenityTabs(typeOptions, view, (value) => (
                        <Badge variant="secondary" className="tabular-nums">
                            {value === CATEGORIES
                                ? categories.length
                                : amenities.filter(
                                      (amenity) => amenity.type === value,
                                  ).length}
                        </Badge>
                    ))}
                >
                    {showingCategories ? (
                        <AmenityCategoryTable
                            categories={categories}
                            typeOptions={typeOptions}
                        />
                    ) : (
                        <>
                            <FilterBar
                                search={table.search}
                                onSearchChange={table.setSearch}
                                searchPlaceholder="Search by name or category"
                            >
                                <AmenityViewToggle
                                    view={view}
                                    onChange={table.changeView}
                                />
                            </FilterBar>

                            <div className="overflow-hidden rounded-lg border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="w-px">
                                                Available
                                            </TableHead>
                                            <TableHead>Amenity</TableHead>
                                            {view === 'list' && (
                                                <TableHead>Category</TableHead>
                                            )}
                                            <TableHead>
                                                <span className="sr-only">
                                                    Actions
                                                </span>
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {view === 'list'
                                            ? table.listRows.map((amenity) => (
                                                  <AmenityRow
                                                      key={amenity.id}
                                                      amenity={amenity}
                                                      showCategory
                                                      onEdit={() =>
                                                          setEditing(amenity)
                                                      }
                                                  />
                                              ))
                                            : table.groups.map((group) => (
                                                  <Fragment key={group.id}>
                                                      <AmenityGroupRow
                                                          name={group.name}
                                                          count={
                                                              group.rows.length
                                                          }
                                                          open={table.isGroupOpen(
                                                              group.id,
                                                          )}
                                                          disabled={term !== ''}
                                                          colSpan={columnCount}
                                                          onToggle={() =>
                                                              table.toggleGroup(
                                                                  group.id,
                                                              )
                                                          }
                                                      />
                                                      {table.isGroupOpen(
                                                          group.id,
                                                      ) &&
                                                          group.rows.map(
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
                                                                                          group.rows,
                                                                                          position,
                                                                                          -1,
                                                                                      )
                                                                      }
                                                                      onMoveDown={
                                                                          term !==
                                                                          ''
                                                                              ? undefined
                                                                              : position ===
                                                                                  group
                                                                                      .rows
                                                                                      .length -
                                                                                      1
                                                                                ? null
                                                                                : () =>
                                                                                      moveAmenity(
                                                                                          amenities,
                                                                                          group.rows,
                                                                                          position,
                                                                                          1,
                                                                                      )
                                                                      }
                                                                  />
                                                              ),
                                                          )}
                                                  </Fragment>
                                              ))}
                                        {table.visible.length === 0 && (
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
                                                                        table.setSearch(
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
                                <PaginationFooter
                                    pagination={table.pagination}
                                />
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

AmenitiesIndex.layout = (props: {
    typeOptions: EnumOption[];
    tab: string;
}) => ({
    breadcrumbs: withTabCrumb(
        [
            { title: 'Admin', href: dashboard() },
            { title: 'Amenities', href: index() },
        ],
        amenityTabs(props.typeOptions),
        props.tab,
    ),
});
