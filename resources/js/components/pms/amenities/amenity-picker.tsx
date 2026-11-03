import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { SettingsSection } from '@/components/pms/settings-section';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldContent, FieldLabel } from '@/components/ui/field';
import type { RouteFormDefinition } from '@/wayfinder';

type Picker = App.Data.BookingEngine.AmenityPickerData;
type Amenity = App.Data.BookingEngine.AmenityData;

/** Groups the amenities by category, keeping the order they come in. */
function groupByCategory(amenities: Amenity[]) {
    const groups = new Map<string, Amenity[]>();

    for (const amenity of amenities) {
        groups.set(amenity.category, [
            ...(groups.get(amenity.category) ?? []),
            amenity,
        ]);
    }

    return [...groups.entries()];
}

/**
 * Tick boxes for the amenities a property or room type can have, grouped by category, saved as one form.
 * A retired amenity shows only while it is ticked, so it can be unticked.
 */
export function AmenityPicker({
    picker,
    form,
    title,
    description,
    readOnly,
}: {
    picker: Picker;
    form: RouteFormDefinition<'post'>;
    title: string;
    description: string;
    readOnly: boolean;
}) {
    const [selected, setSelected] = useState<number[]>(picker.selectedIds);

    function toggle(id: number, checked: boolean) {
        setSelected((current) =>
            checked ? [...current, id] : current.filter((item) => item !== id),
        );
    }

    return (
        <SettingsSection
            form={form}
            title={title}
            description={description}
            submitLabel="Save amenities"
            contentClassName="grid gap-6"
            readOnly={readOnly}
        >
            {() =>
                picker.amenities.length === 0 ? (
                    <EmptyState
                        title="No amenities yet"
                        description="Support adds the amenities you can pick from."
                    />
                ) : (
                    <>
                        {selected.map((id) => (
                            <input
                                key={id}
                                type="hidden"
                                name="amenity_ids[]"
                                value={id}
                            />
                        ))}
                        {groupByCategory(picker.amenities).map(
                            ([category, amenities]) => (
                                <fieldset
                                    key={category}
                                    className="flex flex-col gap-3"
                                >
                                    <legend className="mb-3 text-sm font-medium">
                                        {category}
                                    </legend>
                                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                        {amenities.map((amenity) => (
                                            <Field
                                                key={amenity.id}
                                                orientation="horizontal"
                                            >
                                                <Checkbox
                                                    id={`amenity-${amenity.id}`}
                                                    checked={selected.includes(
                                                        amenity.id,
                                                    )}
                                                    disabled={readOnly}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        toggle(
                                                            amenity.id,
                                                            checked,
                                                        )
                                                    }
                                                />
                                                <FieldContent>
                                                    <FieldLabel
                                                        htmlFor={`amenity-${amenity.id}`}
                                                    >
                                                        {amenity.name}
                                                        {!amenity.active && (
                                                            <Badge variant="secondary">
                                                                Retired
                                                            </Badge>
                                                        )}
                                                    </FieldLabel>
                                                </FieldContent>
                                            </Field>
                                        ))}
                                    </div>
                                </fieldset>
                            ),
                        )}
                    </>
                )
            }
        </SettingsSection>
    );
}
