import { Form, router } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronUp,
    ExternalLink,
    MapPin,
    Pencil,
    Plus,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import NearbyPlaceController from '@/actions/App/Http/Controllers/BookingEngine/NearbyPlaceController';
import { EmptyState } from '@/components/pms/empty-state';
import { NearbyPlaceLinkDialog } from '@/components/pms/nearby-place-link-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

type Place = App.Data.BookingEngine.PropertyNearbyPlaceData;
type Suggestion = App.Data.BookingEngine.NearbyPlaceSuggestionData;

function isOwnPlace(row: Place | Suggestion): row is Place {
    return 'active' in row;
}

/** Swap an on place with its neighbour and send the whole order, off places last. The server checks that the ids are the full list. */
function movePlace(
    onPlaces: Place[],
    offPlaces: Place[],
    position: number,
    step: -1 | 1,
) {
    const ids = onPlaces.map((place) => place.id);

    [ids[position], ids[position + step]] = [
        ids[position + step],
        ids[position],
    ];

    router.put(
        NearbyPlaceController.reorder.url(),
        { order: [...ids, ...offPlaces.map((place) => place.id)] },
        { preserveScroll: true },
    );
}

function EditPlaceDialog({
    place,
    onClose,
}: {
    place: Place;
    onClose: () => void;
}) {
    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Edit {place.name}</DialogTitle>
                </DialogHeader>
                <Form
                    {...NearbyPlaceController.update.form({
                        nearbyPlace: place.id,
                    })}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <Field data-invalid={!!errors.display_name}>
                                <FieldLabel htmlFor="nearby-place-display-name">
                                    Name on your site (optional)
                                </FieldLabel>
                                <Input
                                    id="nearby-place-display-name"
                                    name="display_name"
                                    defaultValue={place.displayName ?? ''}
                                    placeholder={place.sharedName}
                                    maxLength={255}
                                    autoComplete="off"
                                    autoFocus
                                    aria-invalid={!!errors.display_name}
                                />
                                <FieldDescription>
                                    Leave empty to use "{place.sharedName}".
                                </FieldDescription>
                                <FieldError>{errors.display_name}</FieldError>
                            </Field>
                            <Field data-invalid={!!errors.description}>
                                <FieldLabel htmlFor="nearby-place-description">
                                    Description (optional)
                                </FieldLabel>
                                <Input
                                    id="nearby-place-description"
                                    name="description"
                                    defaultValue={place.description ?? ''}
                                    placeholder="e.g. Ferry to the island"
                                    maxLength={255}
                                    autoComplete="off"
                                    aria-invalid={!!errors.description}
                                />
                                <FieldError>{errors.description}</FieldError>
                            </Field>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="destructive"
                                    className="sm:mr-auto"
                                    onClick={() =>
                                        router.delete(
                                            NearbyPlaceController.destroy.url({
                                                nearbyPlace: place.id,
                                            }),
                                            {
                                                preserveScroll: true,
                                                onSuccess: onClose,
                                            },
                                        )
                                    }
                                >
                                    Remove place
                                </Button>
                                <DialogClose
                                    render={<Button variant="outline" />}
                                >
                                    Cancel
                                </DialogClose>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    focusableWhenDisabled
                                >
                                    {processing && (
                                        <Spinner data-icon="inline-start" />
                                    )}
                                    Save
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * The places guests see near the property, in one table. On places come first, in the owner's order. Off places and the
 * Approved places suggested nearby follow, nearest first. Turning a suggestion on adds it to the property.
 */
export function NearbyPlacesSection({
    places,
    suggestions,
    readOnly = false,
}: {
    places: Place[];
    suggestions: Suggestion[];
    readOnly?: boolean;
}) {
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<Place | null>(null);
    const [savingId, setSavingId] = useState<number | null>(null);

    const onPlaces = useMemo(
        () => places.filter((place) => place.active),
        [places],
    );
    const offPlaces = useMemo(
        () => places.filter((place) => !place.active),
        [places],
    );
    const offRows = useMemo(
        () =>
            [...offPlaces, ...suggestions].sort(
                (first, second) =>
                    (first.distanceKm ?? Infinity) -
                    (second.distanceKm ?? Infinity),
            ),
        [offPlaces, suggestions],
    );

    const saveSwitch = (row: Place | Suggestion, active: boolean) => {
        const options = {
            preserveScroll: true,
            onStart: () => setSavingId(row.id),
            onFinish: () => setSavingId(null),
        };

        if (isOwnPlace(row)) {
            router.put(
                NearbyPlaceController.active.url({ nearbyPlace: row.id }),
                { active },
                options,
            );
        } else {
            router.post(
                NearbyPlaceController.storeSuggested.url({
                    nearbyPlace: row.id,
                }),
                {},
                options,
            );
        }
    };

    const addButton = (
        <Button onClick={() => setAdding(true)}>
            <Plus data-icon="inline-start" />
            Add place
        </Button>
    );

    const renderRow = (row: Place | Suggestion, position?: number) => {
        const own = isOwnPlace(row);

        return (
            <TableRow key={row.id}>
                <TableCell>
                    <Switch
                        size="sm"
                        checked={own && row.active}
                        disabled={readOnly || savingId === row.id}
                        aria-label={`Show ${row.name} to guests`}
                        onCheckedChange={(active) => saveSwitch(row, active)}
                    />
                </TableCell>
                <TableCell>
                    <div className="flex min-w-0 flex-col gap-0.5">
                        <a
                            href={row.googleMapsUrl}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 font-medium hover:underline"
                        >
                            {row.name}
                            <ExternalLink className="size-3" />
                        </a>
                        {own && row.description && (
                            <span className="max-w-md truncate text-xs text-muted-foreground">
                                {row.description}
                            </span>
                        )}
                    </div>
                </TableCell>
                <TableCell className="text-right text-muted-foreground tabular-nums">
                    {row.distance}
                </TableCell>
                <TableCell>
                    <div className="flex items-center justify-end gap-1">
                        {!own && <Badge variant="outline">Suggested</Badge>}
                        {own && !readOnly && position !== undefined && (
                            <>
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label={`Move ${row.name} up`}
                                    disabled={position === 0}
                                    onClick={() =>
                                        movePlace(
                                            onPlaces,
                                            offPlaces,
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
                                    aria-label={`Move ${row.name} down`}
                                    disabled={position === onPlaces.length - 1}
                                    onClick={() =>
                                        movePlace(
                                            onPlaces,
                                            offPlaces,
                                            position,
                                            1,
                                        )
                                    }
                                >
                                    <ChevronDown />
                                </Button>
                            </>
                        )}
                        {own && !readOnly && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setEditing(row)}
                            >
                                <Pencil data-icon="inline-start" />
                                Edit
                            </Button>
                        )}
                    </div>
                </TableCell>
            </TableRow>
        );
    };

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-base font-medium">Nearby places</h2>
                    <p className="text-sm text-muted-foreground">
                        Turn on the places guests should see. Suggestions come
                        from other properties near you.
                    </p>
                </div>
                {!readOnly && addButton}
            </div>

            {places.length === 0 && suggestions.length === 0 ? (
                <EmptyState
                    icon={MapPin}
                    title="No nearby places yet"
                    description="Add a place guests may want to visit, such as a jetty, market or beach."
                    action={readOnly ? undefined : addButton}
                />
            ) : (
                <div className="overflow-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>On site</TableHead>
                                <TableHead>Place</TableHead>
                                <TableHead className="text-right">
                                    Distance
                                </TableHead>
                                <TableHead>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {onPlaces.map((place, position) =>
                                renderRow(place, position),
                            )}
                            {offRows.map((row) => renderRow(row))}
                        </TableBody>
                    </Table>
                </div>
            )}

            <NearbyPlaceLinkDialog
                form={NearbyPlaceController.store.form()}
                description="Paste the link of a place guests may want to visit, such as a jetty, market or beach."
                open={adding}
                onOpenChange={setAdding}
            />
            {editing && (
                <EditPlaceDialog
                    key={editing.id}
                    place={editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </div>
    );
}
