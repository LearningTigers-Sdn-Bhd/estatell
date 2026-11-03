import { Head, router } from '@inertiajs/react';
import {
    BedDouble,
    Building2,
    ChevronDown,
    ChevronUp,
    List,
    ListChecks,
    Plus,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import { AmenityDialog } from '@/components/pms/amenities/amenity-dialog';
import { EmptyState } from '@/components/pms/empty-state';
import type { EnumOption } from '@/components/pms/enum-select';
import { FilterBar } from '@/components/pms/filter-bar';
import { PageHeader } from '@/components/pms/page-header';
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
import { dashboard } from '@/routes/admin';
import { index, reorder } from '@/routes/admin/amenities';
import { update as updateActive } from '@/routes/admin/amenities/active';

type Amenity = App.Data.BookingEngine.AmenityData;

const ALL = 'all';

const typeIcons: Record<string, LucideIcon> = {
    [ALL]: List,
    property: Building2,
    room: BedDouble,
};

/**
 * Swap an amenity with its neighbour in the rows on screen and send the whole order. The server checks that the ids are the full list.
 */
function moveAmenity(
    amenities: Amenity[],
    visible: Amenity[],
    position: number,
    step: -1 | 1,
) {
    const ids = amenities.map((amenity) => amenity.id);
    const from = ids.indexOf(visible[position].id);
    const to = ids.indexOf(visible[position + step].id);

    [ids[from], ids[to]] = [ids[to], ids[from]];

    router.put(reorder.url(), { order: ids }, { preserveScroll: true });
}

function toggleAmenity(amenity: Amenity) {
    router.put(
        updateActive.url(amenity.id),
        { active: !amenity.active },
        { preserveScroll: true },
    );
}

export default function AmenitiesIndex({
    amenities,
    typeOptions,
    type,
}: {
    amenities: Amenity[];
    typeOptions: EnumOption[];
    type: string;
}) {
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<Amenity | null>(null);
    const [search, setSearch] = useState('');

    const visible = useMemo(() => {
        const term = search.trim().toLowerCase();

        return amenities.filter(
            (amenity) =>
                (type === ALL || amenity.type === type) &&
                (term === '' ||
                    amenity.name.toLowerCase().includes(term) ||
                    amenity.category.toLowerCase().includes(term)),
        );
    }, [amenities, type, search]);

    const typeLabels = useMemo(
        () =>
            new Map(typeOptions.map((option) => [option.value, option.label])),
        [typeOptions],
    );

    const categories = useMemo(
        () => [...new Set(amenities.map((amenity) => amenity.category))].sort(),
        [amenities],
    );

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
                            Add amenity
                        </Button>
                    }
                />

                <TabLinks
                    activeTab={type}
                    panelWidth="full"
                    className="gap-4"
                    triggerClassName="min-w-0 px-0 mr-6"
                    panelClassName="flex flex-col gap-4"
                    tabs={[{ value: ALL, label: 'All' }, ...typeOptions].map(
                        (option) => ({
                            value: option.value,
                            label: option.label,
                            icon: typeIcons[option.value] ?? ListChecks,
                            href: index({ query: { type: option.value } }),
                        }),
                    )}
                >
                    <FilterBar
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Search by name or category"
                    />

                    <div className="overflow-hidden rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Amenity</TableHead>
                                    {type === ALL && (
                                        <TableHead>Type</TableHead>
                                    )}
                                    <TableHead>Category</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {visible.map((amenity, position) => (
                                    <TableRow key={amenity.id}>
                                        <TableCell className="font-medium">
                                            {amenity.name}
                                        </TableCell>
                                        {type === ALL && (
                                            <TableCell className="text-muted-foreground">
                                                {typeLabels.get(amenity.type)}
                                            </TableCell>
                                        )}
                                        <TableCell className="text-muted-foreground">
                                            {amenity.category}
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge
                                                status={
                                                    amenity.active
                                                        ? {
                                                              value: 'on',
                                                              label: 'On',
                                                              tone: 'success',
                                                          }
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
                                                <Button
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    aria-label={`Move ${amenity.name} up`}
                                                    disabled={position === 0}
                                                    onClick={() =>
                                                        moveAmenity(
                                                            amenities,
                                                            visible,
                                                            position,
                                                            -1,
                                                        )
                                                    }
                                                >
                                                    <ChevronUp />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    aria-label={`Move ${amenity.name} down`}
                                                    disabled={
                                                        position ===
                                                        visible.length - 1
                                                    }
                                                    onClick={() =>
                                                        moveAmenity(
                                                            amenities,
                                                            visible,
                                                            position,
                                                            1,
                                                        )
                                                    }
                                                >
                                                    <ChevronDown />
                                                </Button>
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        setEditing(amenity)
                                                    }
                                                >
                                                    Edit
                                                </Button>
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        toggleAmenity(amenity)
                                                    }
                                                >
                                                    {amenity.active
                                                        ? 'Retire'
                                                        : 'Turn on'}
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {visible.length === 0 && (
                                    <TableRow className="hover:bg-transparent">
                                        <TableCell
                                            colSpan={type === ALL ? 5 : 4}
                                        >
                                            {search.trim() === '' ? (
                                                <EmptyState
                                                    icon={ListChecks}
                                                    title="No amenities yet"
                                                    description="Add the first one so owners can pick it."
                                                    action={
                                                        <Button
                                                            onClick={() =>
                                                                setAdding(true)
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
                                                                setSearch('')
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
                </TabLinks>
            </div>

            <AmenityDialog
                typeOptions={typeOptions}
                categories={categories}
                open={adding}
                onOpenChange={setAdding}
            />
            {editing && (
                <AmenityDialog
                    amenity={editing}
                    typeOptions={typeOptions}
                    categories={categories}
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
