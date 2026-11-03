import { Head, router } from '@inertiajs/react';
import { ChevronDown, ChevronUp, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { AmenityDialog } from '@/components/pms/amenities/amenity-dialog';
import type { EnumOption } from '@/components/pms/enum-select';
import { EnumSelect } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
}: {
    amenities: Amenity[];
    typeOptions: EnumOption[];
}) {
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<Amenity | null>(null);
    const [type, setType] = useState(typeOptions[0]?.value ?? '');
    const [search, setSearch] = useState('');

    const visible = useMemo(() => {
        const term = search.trim().toLowerCase();

        return amenities.filter(
            (amenity) =>
                amenity.type === type &&
                (term === '' ||
                    amenity.name.toLowerCase().includes(term) ||
                    amenity.category.toLowerCase().includes(term)),
        );
    }, [amenities, type, search]);

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

                <div className="flex flex-wrap gap-2">
                    <EnumSelect
                        aria-label="Type"
                        options={typeOptions}
                        value={type}
                        onValueChange={setType}
                        className="w-40"
                    />
                    <Input
                        aria-label="Search amenities"
                        placeholder="Search by name or category"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        className="w-72"
                    />
                </div>

                <div className="overflow-hidden rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Amenity</TableHead>
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
                                <TableRow>
                                    <TableCell
                                        colSpan={4}
                                        className="text-center text-muted-foreground"
                                    >
                                        No amenities found.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>
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
