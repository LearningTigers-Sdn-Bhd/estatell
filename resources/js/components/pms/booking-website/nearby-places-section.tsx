import { Form, router } from '@inertiajs/react';
import { ChevronDown, ChevronUp, ExternalLink, Plus, X } from 'lucide-react';
import NearbyPlaceController from '@/actions/App/Http/Controllers/BookingEngine/NearbyPlaceController';
import { EmptyState } from '@/components/pms/empty-state';
import { SettingsSection } from '@/components/pms/settings-section';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';

type Place = App.Data.BookingEngine.PropertyNearbyPlaceData;
type Suggestion = App.Data.BookingEngine.NearbyPlaceSuggestionData;

/** Swap a place with its neighbour and send the whole order. The server checks that the ids are the full list. */
function movePlace(places: Place[], position: number, step: -1 | 1) {
    const ids = places.map((place) => place.id);

    [ids[position], ids[position + step]] = [
        ids[position + step],
        ids[position],
    ];

    router.put(
        NearbyPlaceController.reorder.url(),
        { order: ids },
        { preserveScroll: true },
    );
}

function saveDescription(place: Place, description: string) {
    if (description === (place.description ?? '')) {
        return;
    }

    router.put(
        NearbyPlaceController.update.url({ nearbyPlace: place.id }),
        { description },
        { preserveScroll: true },
    );
}

/**
 * The places guests see near the property. The owner pastes a Google Maps link; Approved places near the property are
 * suggested below. Each action saves on its own.
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
    return (
        <div className="flex flex-col gap-6">
            <SettingsSection
                form={NearbyPlaceController.store.form()}
                title="Nearby places"
                description="Paste the Google Maps link of a place guests may want to visit, such as a jetty, market or beach."
                submitLabel="Add place"
                contentClassName="grid gap-5"
                readOnly={readOnly}
            >
                {(errors) => (
                    <>
                        <Field data-invalid={!!errors.google_maps_url}>
                            <FieldLabel htmlFor="google_maps_url">
                                Google Maps link
                            </FieldLabel>
                            <Input
                                id="google_maps_url"
                                name="google_maps_url"
                                placeholder="https://www.google.com/maps/place/..."
                                autoComplete="off"
                                disabled={readOnly}
                                aria-invalid={!!errors.google_maps_url}
                            />
                            <FieldDescription>
                                Open the place on Google Maps and copy its link.
                            </FieldDescription>
                            <FieldError>{errors.google_maps_url}</FieldError>
                        </Field>

                        {places.length === 0 ? (
                            <EmptyState
                                title="No nearby places yet"
                                description="Add a place with its Google Maps link, or pick one from the suggestions."
                            />
                        ) : (
                            <ul className="flex flex-col divide-y rounded-lg border">
                                {places.map((place, position) => (
                                    <li
                                        key={place.id}
                                        className="flex flex-wrap items-center gap-3 p-3"
                                    >
                                        <div className="flex min-w-48 flex-1 flex-col gap-1">
                                            <a
                                                href={place.googleMapsUrl}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="inline-flex items-center gap-1 text-sm font-medium hover:underline"
                                            >
                                                {place.name}
                                                <ExternalLink className="size-3" />
                                            </a>
                                            <Input
                                                aria-label={`Description of ${place.name}`}
                                                placeholder="Short description (optional)"
                                                defaultValue={
                                                    place.description ?? ''
                                                }
                                                maxLength={255}
                                                disabled={readOnly}
                                                onBlur={(event) =>
                                                    saveDescription(
                                                        place,
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        {!readOnly && (
                                            <div className="flex items-center gap-1">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    aria-label={`Move ${place.name} up`}
                                                    disabled={position === 0}
                                                    onClick={() =>
                                                        movePlace(
                                                            places,
                                                            position,
                                                            -1,
                                                        )
                                                    }
                                                >
                                                    <ChevronUp />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    aria-label={`Move ${place.name} down`}
                                                    disabled={
                                                        position ===
                                                        places.length - 1
                                                    }
                                                    onClick={() =>
                                                        movePlace(
                                                            places,
                                                            position,
                                                            1,
                                                        )
                                                    }
                                                >
                                                    <ChevronDown />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    aria-label={`Remove ${place.name}`}
                                                    onClick={() =>
                                                        router.delete(
                                                            NearbyPlaceController.destroy.url(
                                                                {
                                                                    nearbyPlace:
                                                                        place.id,
                                                                },
                                                            ),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    <X />
                                                </Button>
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </>
                )}
            </SettingsSection>

            {!readOnly && suggestions.length > 0 && (
                <section className="flex flex-col gap-3 rounded-lg border p-4">
                    <div>
                        <h2 className="text-base font-medium">Suggestions</h2>
                        <p className="text-sm text-muted-foreground">
                            Places other properties near you list, nearest
                            first.
                        </p>
                    </div>
                    <ul className="flex flex-col divide-y">
                        {suggestions.map((suggestion) => (
                            <li
                                key={suggestion.id}
                                className="flex items-center justify-between gap-3 py-2"
                            >
                                <span className="text-sm">
                                    {suggestion.name}{' '}
                                    <span className="text-muted-foreground">
                                        {suggestion.distance}
                                    </span>
                                </span>
                                <Form
                                    {...NearbyPlaceController.storeSuggested.form(
                                        { nearbyPlace: suggestion.id },
                                    )}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            size="sm"
                                            disabled={processing}
                                            focusableWhenDisabled
                                        >
                                            {processing ? (
                                                <Spinner data-icon="inline-start" />
                                            ) : (
                                                <Plus data-icon="inline-start" />
                                            )}
                                            Add
                                        </Button>
                                    )}
                                </Form>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </div>
    );
}
