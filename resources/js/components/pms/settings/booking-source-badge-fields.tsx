import { Radio } from '@base-ui/react/radio';
import { RadioGroup } from '@base-ui/react/radio-group';
import { Check } from 'lucide-react';
import {
    bookingSourceIcons,
    swatchClasses,
} from '@/components/pms/booking-source-badge';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';

type Option = { value: string; label: string };
export type BadgeMode = 'icon' | 'letters';

/**
 * The Badge and Color fields of the booking source Dialog: Icon or Letter, the icon grid or the letters input, and the
 * ten swatches. Both the icon and the letters stay in the form until Save; the Dialog sends only the chosen one.
 */
export function BookingSourceBadgeFields({
    mode,
    onModeChange,
    icon,
    onIconChange,
    letters,
    onLettersChange,
    color,
    onColorChange,
    icons,
    colors,
    errors,
}: {
    mode: BadgeMode;
    onModeChange: (mode: BadgeMode) => void;
    icon: string;
    onIconChange: (icon: string) => void;
    letters: string;
    onLettersChange: (letters: string) => void;
    color: string;
    onColorChange: (color: string) => void;
    icons: Option[];
    colors: Option[];
    errors: Partial<Record<string, string>>;
}) {
    return (
        <>
            <Field data-invalid={!!(errors.badge_icon || errors.badge_letters)}>
                <FieldLabel id="booking-source-badge-label">Badge</FieldLabel>
                <ToggleGroup
                    variant="outline"
                    spacing={0}
                    value={[mode]}
                    onValueChange={(value) =>
                        value[0] && onModeChange(value[0] as BadgeMode)
                    }
                    aria-labelledby="booking-source-badge-label"
                >
                    <ToggleGroupItem value="icon">Icon</ToggleGroupItem>
                    <ToggleGroupItem value="letters">Letter</ToggleGroupItem>
                </ToggleGroup>
                {mode === 'icon' ? (
                    <ToggleGroup
                        variant="outline"
                        size="sm"
                        spacing={1}
                        className="max-w-full flex-wrap"
                        value={[icon]}
                        onValueChange={(value) =>
                            value[0] && onIconChange(value[0])
                        }
                        aria-label="Badge icon"
                    >
                        {icons.map((option) => {
                            const Icon =
                                bookingSourceIcons[
                                    option.value as App.Enums.BookingSourceIcon
                                ];

                            return (
                                <ToggleGroupItem
                                    key={option.value}
                                    value={option.value}
                                    aria-label={option.label}
                                    title={option.label}
                                >
                                    <Icon aria-hidden />
                                </ToggleGroupItem>
                            );
                        })}
                    </ToggleGroup>
                ) : (
                    <Input
                        id="booking-source-letters"
                        name="badge_letters"
                        aria-label="Letters"
                        placeholder="e.g. B"
                        maxLength={2}
                        className="w-24"
                        value={letters}
                        onChange={(event) =>
                            onLettersChange(
                                event.target.value
                                    .replace(/[^A-Za-z0-9]/g, '')
                                    .toUpperCase(),
                            )
                        }
                        aria-invalid={!!errors.badge_letters}
                    />
                )}
                <FieldError>
                    {errors.badge_icon ?? errors.badge_letters}
                </FieldError>
            </Field>
            <Field data-invalid={!!errors.badge_color}>
                <FieldLabel id="booking-source-color-label">Color</FieldLabel>
                <RadioGroup
                    value={color}
                    onValueChange={(value) => onColorChange(value as string)}
                    aria-labelledby="booking-source-color-label"
                    className="flex flex-wrap gap-2"
                >
                    {colors.map((option) => (
                        <Radio.Root
                            key={option.value}
                            value={option.value}
                            aria-label={option.label}
                            title={option.label}
                            className={cn(
                                'flex size-7 items-center justify-center rounded-md outline-none focus-visible:ring-3 focus-visible:ring-ring/50',
                                swatchClasses[
                                    option.value as App.Enums.SwatchColor
                                ],
                            )}
                        >
                            <Radio.Indicator className="flex items-center justify-center">
                                <Check aria-hidden className="size-4" />
                            </Radio.Indicator>
                        </Radio.Root>
                    ))}
                </RadioGroup>
                <FieldError>{errors.badge_color}</FieldError>
            </Field>
        </>
    );
}
