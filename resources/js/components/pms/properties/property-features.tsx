import { router } from '@inertiajs/react';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Badge } from '@/components/ui/badge';
import { Switch } from '@/components/ui/switch';
import { update as updateFeature } from '@/routes/admin/properties/features';

type PropertyFeature = App.Data.Platform.PropertyFeatureData;

/**
 * The Features tab body. With `readOnly` (the partner page) each feature shows On or Off and a note, with no switch.
 */
export function PropertyFeatures({
    code,
    features,
    readOnly = false,
}: {
    code: string;
    features: PropertyFeature[];
    readOnly?: boolean;
}) {
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
                    <Field key={item.feature} orientation="horizontal">
                        <FieldContent>
                            <FieldLabel>{item.label}</FieldLabel>
                            <FieldDescription>
                                {item.description}
                            </FieldDescription>
                        </FieldContent>
                        <Badge variant={item.active ? 'secondary' : 'outline'}>
                            {item.active ? 'On' : 'Off'}
                        </Badge>
                    </Field>
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
                <Field key={item.feature} orientation="horizontal">
                    <FieldContent>
                        <FieldLabel htmlFor={`feature-${item.feature}`}>
                            {item.label}
                        </FieldLabel>
                        <FieldDescription>{item.description}</FieldDescription>
                    </FieldContent>
                    <Switch
                        id={`feature-${item.feature}`}
                        checked={item.active}
                        onCheckedChange={(active) =>
                            toggle(item.feature, active)
                        }
                    />
                </Field>
            ))}
        </FieldGroup>
    );
}
