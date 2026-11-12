import { Pencil } from 'lucide-react';
import { useState } from 'react';
import { AmenityPicker } from '@/components/pms/amenities/amenity-picker';
import {
    AmenityDetailSheet,
    hasAmenityDetails,
} from '@/components/pms/concierge/amenity-details';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { toggle } from '@/routes/settings/concierge/amenities';

type Details = App.Data.Concierge.ConciergeAmenitySettingsData;

/**
 * One list for everything about amenities: switch an amenity on, then open its details (where, hours, cost,
 * reservation, notes) from the same row. The switches are the ones the Booking website page uses and save to the same
 * list, so this works with or without the booking engine. The list searches, groups and pages, so it stays usable
 * with many amenities on.
 */
export function AmenitiesTab({
    picker,
    page,
    listUrl,
    details,
}: {
    picker: App.Data.BookingEngine.AmenityPickerData;
    page: number;
    listUrl: string;
    details: Details[];
}) {
    const [editing, setEditing] = useState<Details | null>(null);
    const detailsById = new Map(details.map((item) => [item.id, item]));

    return (
        <Card className="print:hidden">
            <CardHeader>
                <CardTitle>Amenities</CardTitle>
                <CardDescription>
                    Turn on what your property offers, then add what guests need
                    to know: where it is, when it opens, what it costs, and
                    whether they must reserve. Switches save at once and also
                    update the booking website, if you use it.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <AmenityPicker
                    picker={picker}
                    saveUrl={toggle.url()}
                    description="Room amenities are set on each room type."
                    readOnly={false}
                    page={page}
                    listUrl={listUrl}
                    rowAction={(amenity, on) => {
                        const item = on ? detailsById.get(amenity.id) : null;

                        return (
                            item && (
                                <span className="flex items-center justify-end gap-2">
                                    {hasAmenityDetails(item) && (
                                        <Badge variant="secondary">Added</Badge>
                                    )}
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-lg"
                                        className="size-11 shrink-0"
                                        aria-label={`Edit details of ${amenity.name}`}
                                        onClick={() => setEditing(item)}
                                    >
                                        <Pencil />
                                    </Button>
                                </span>
                            )
                        );
                    }}
                />
            </CardContent>
            {editing && (
                <AmenityDetailSheet
                    key={editing.id}
                    amenity={editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </Card>
    );
}
