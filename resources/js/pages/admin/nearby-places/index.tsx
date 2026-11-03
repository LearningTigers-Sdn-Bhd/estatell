import { Head, router } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { useMemo, useState } from 'react';
import NearbyPlaceController from '@/actions/App/Http/Controllers/Admin/NearbyPlaceController';
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
import { index } from '@/routes/admin/nearby-places';

type Place = App.Data.BookingEngine.AdminNearbyPlaceData;

const ALL = 'all';

function act(action: typeof NearbyPlaceController.approve, place: Place) {
    router.put(
        action.url({ nearbyPlace: place.id }),
        {},
        { preserveScroll: true },
    );
}

export default function NearbyPlacesIndex({
    places,
    statusOptions,
}: {
    places: Place[];
    statusOptions: EnumOption[];
}) {
    const [status, setStatus] = useState(ALL);
    const [search, setSearch] = useState('');

    const visible = useMemo(() => {
        const term = search.trim().toLowerCase();

        return places.filter(
            (place) =>
                (status === ALL || place.statusValue === status) &&
                (term === '' || place.name.toLowerCase().includes(term)),
        );
    }, [places, status, search]);

    return (
        <>
            <Head title="Nearby places" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Nearby places"
                    description="Places owners added from Google Maps links. Approved places are suggested to nearby properties. Rejecting a place removes it from every property that has it and tells the owner."
                />

                <div className="flex flex-wrap gap-2">
                    <EnumSelect
                        aria-label="Status"
                        options={[
                            { value: ALL, label: 'All statuses' },
                            ...statusOptions,
                        ]}
                        value={status}
                        onValueChange={setStatus}
                        className="w-44"
                    />
                    <Input
                        aria-label="Search places"
                        placeholder="Search by name"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        className="w-72"
                    />
                </div>

                <div className="overflow-hidden rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Place</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">
                                    Properties
                                </TableHead>
                                <TableHead>Added by</TableHead>
                                <TableHead>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {visible.map((place) => (
                                <TableRow key={place.id}>
                                    <TableCell className="font-medium">
                                        <a
                                            href={place.googleMapsUrl}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="inline-flex items-center gap-1 hover:underline"
                                        >
                                            {place.name}
                                            <ExternalLink className="size-3" />
                                        </a>
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge status={place.status} />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {place.propertiesCount}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {place.addedBy ?? '-'}
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex items-center justify-end gap-1">
                                            {place.canApprove && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        act(
                                                            NearbyPlaceController.approve,
                                                            place,
                                                        )
                                                    }
                                                >
                                                    Approve
                                                </Button>
                                            )}
                                            {place.canReject && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        act(
                                                            NearbyPlaceController.reject,
                                                            place,
                                                        )
                                                    }
                                                >
                                                    Reject
                                                </Button>
                                            )}
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                            {visible.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={5}
                                        className="text-center text-muted-foreground"
                                    >
                                        No places found.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </>
    );
}

NearbyPlacesIndex.layout = {
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Nearby places', href: index() },
    ],
};
