import { Form, router } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { update as updateMode } from '@/routes/admin/properties/booking-engine-mode';
import { update as updateFeature } from '@/routes/admin/properties/features';

type PropertyFeature = App.Data.Platform.PropertyFeatureData;
type BookingEngineMode = App.Data.Platform.BookingEngineModeData;

/** What happens when an admin switches to each mode, shown in the confirm dialog. */
const modeEffects: Record<string, string> = {
    'api-only':
        'The booking website goes offline. The owner creates an API token and adds return addresses on the API tab.',
    microsite:
        "The API token stops working at once, so the owner's website cannot take bookings. The owner publishes the microsite again from the General tab.",
};

/**
 * Microsite or API only, under the Booking engine switch while it is on. A change asks first, because it takes the
 * current online booking offline.
 */
function BookingEngineModeField({
    code,
    mode,
    readOnly,
}: {
    code: string;
    mode: BookingEngineMode;
    readOnly: boolean;
}) {
    const [next, setNext] = useState<string | null>(null);
    const label = (value: string) =>
        mode.options.find((option) => option.value === value)?.label ?? value;

    return (
        <Field orientation="horizontal" className="border-l-2 pl-4">
            <FieldContent>
                <FieldLabel>Booking engine mode</FieldLabel>
                <FieldDescription>
                    A microsite run by estatell, or the owner's own website
                    through the API.
                </FieldDescription>
            </FieldContent>
            {readOnly ? (
                <Badge variant="secondary">{label(mode.mode)}</Badge>
            ) : (
                <ToggleGroup
                    variant="outline"
                    spacing={0}
                    value={[mode.mode]}
                    onValueChange={(value) =>
                        value[0] && value[0] !== mode.mode && setNext(value[0])
                    }
                    aria-label="Booking engine mode"
                >
                    {mode.options.map((option) => (
                        <ToggleGroupItem
                            key={option.value}
                            value={option.value}
                        >
                            {option.label}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
            )}

            <AlertDialog
                open={next !== null}
                onOpenChange={(open) => !open && setNext(null)}
            >
                <AlertDialogContent>
                    {next && (
                        <Form
                            {...updateMode.form({ property: code })}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setNext(null)}
                        >
                            {({ processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="mode"
                                        value={next}
                                    />
                                    <AlertDialogHeader>
                                        <AlertDialogTitle>
                                            Switch to {label(next)}?
                                        </AlertDialogTitle>
                                        <AlertDialogDescription>
                                            {modeEffects[next]}
                                        </AlertDialogDescription>
                                    </AlertDialogHeader>
                                    <AlertDialogFooter>
                                        <AlertDialogCancel>
                                            Cancel
                                        </AlertDialogCancel>
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            disabled={processing}
                                            focusableWhenDisabled
                                        >
                                            {processing && (
                                                <Spinner data-icon="inline-start" />
                                            )}
                                            Switch to {label(next)}
                                        </Button>
                                    </AlertDialogFooter>
                                </>
                            )}
                        </Form>
                    )}
                </AlertDialogContent>
            </AlertDialog>
        </Field>
    );
}

/**
 * The Features tab body. With `readOnly` (the partner page) each feature shows On or Off and a note, with no switch.
 */
export function PropertyFeatures({
    code,
    features,
    bookingEngineMode,
    readOnly = false,
}: {
    code: string;
    features: PropertyFeature[];
    bookingEngineMode?: BookingEngineMode;
    readOnly?: boolean;
}) {
    const modeField = (item: PropertyFeature) =>
        item.feature === 'booking-engine' &&
        item.active &&
        bookingEngineMode && (
            <BookingEngineModeField
                code={code}
                mode={bookingEngineMode}
                readOnly={readOnly}
            />
        );

    const toggle = (feature: PropertyFeature['feature'], active: boolean) => {
        router
            .optimistic<{ features: PropertyFeature[] }>((props) => ({
                features: props.features.map((item) =>
                    item.feature === feature ? { ...item, active } : item,
                ),
            }))
            .put(
                updateFeature.url({ property: code, feature }),
                { active },
                { preserveScroll: true },
            );
    };

    if (readOnly) {
        return (
            <FieldGroup className="max-w-3/4">
                {features.map((item) => (
                    <Fragment key={item.feature}>
                        <Field orientation="horizontal">
                            <FieldContent>
                                <FieldLabel>{item.label}</FieldLabel>
                                <FieldDescription>
                                    {item.description}
                                </FieldDescription>
                            </FieldContent>
                            <Badge
                                variant={item.active ? 'secondary' : 'outline'}
                            >
                                {item.active ? 'On' : 'Off'}
                            </Badge>
                        </Field>
                        {modeField(item)}
                    </Fragment>
                ))}
                <p className="text-sm text-muted-foreground">
                    Ask estatell support to change features.
                </p>
            </FieldGroup>
        );
    }

    return (
        <FieldGroup className="max-w-3/4">
            {features.map((item) => (
                <Fragment key={item.feature}>
                    <Field orientation="horizontal">
                        <FieldContent>
                            <FieldLabel htmlFor={`feature-${item.feature}`}>
                                {item.label}
                            </FieldLabel>
                            <FieldDescription>
                                {item.description}
                            </FieldDescription>
                        </FieldContent>
                        <Switch
                            id={`feature-${item.feature}`}
                            checked={item.active}
                            onCheckedChange={(active) =>
                                toggle(item.feature, active)
                            }
                        />
                    </Field>
                    {modeField(item)}
                </Fragment>
            ))}
        </FieldGroup>
    );
}
