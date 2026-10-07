import type { LucideIcon } from 'lucide-react';
import { AArrowDown, AArrowUp, Type } from 'lucide-react';
import type { EnumOption } from '@/components/pms/enum-select';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldLabel,
    FieldLegend,
    FieldSet,
    FieldTitle,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';

type Settings = App.Data.Property.PrintSettingsData;

/** A font size from `PdfFontSize::options()`. */
export type FontSizeOption = EnumOption & { description: string };

const fontSizeIcons: Record<string, LucideIcon> = {
    small: AArrowDown,
    medium: Type,
    large: AArrowUp,
};

/** A font style from `PdfFont::options()`, with the CSS families its sample uses. */
export type FontStyleOption = EnumOption & {
    headingFamily: string;
    bodyFamily: string;
};

/**
 * Font style, font size and footer text. Each change saves by itself; the footer saves on blur.
 */
export function PrintGeneralForm({
    value,
    propertyName,
    fonts,
    fontSizes,
    onChange,
    onDraft,
}: {
    value: Settings;
    propertyName: string;
    fonts: FontStyleOption[];
    fontSizes: FontSizeOption[];
    onChange: (next: Settings, options?: { delay?: number }) => void;
    onDraft: (next: Settings) => void;
}) {
    return (
        <div className="flex flex-col gap-6">
            <FieldSet>
                <FieldLegend variant="label">Font style</FieldLegend>
                <FieldDescription>
                    Chinese text prints in Noto Sans in every style.
                </FieldDescription>
                <RadioGroup
                    value={value.font}
                    onValueChange={(font) =>
                        onChange({ ...value, font: font as Settings['font'] })
                    }
                    className="grid gap-3 sm:grid-cols-2"
                >
                    {fonts.map((font) => (
                        <FieldLabel
                            key={font.value}
                            htmlFor={`font-${font.value}`}
                        >
                            <Field orientation="horizontal">
                                <FieldContent className="min-w-0">
                                    <FieldTitle>{font.label}</FieldTitle>
                                    <p
                                        className="truncate text-lg font-bold"
                                        style={{
                                            fontFamily: font.headingFamily,
                                        }}
                                    >
                                        {propertyName}
                                    </p>
                                    <p
                                        className="truncate text-sm text-muted-foreground"
                                        style={{ fontFamily: font.bodyFamily }}
                                    >
                                        Tan Wei Ming · 陈美玲 · RM 1,250.00
                                    </p>
                                </FieldContent>
                                <RadioGroupItem
                                    id={`font-${font.value}`}
                                    value={font.value}
                                />
                            </Field>
                        </FieldLabel>
                    ))}
                </RadioGroup>
            </FieldSet>
            <FieldSet>
                <FieldLegend variant="label">Font size</FieldLegend>
                <RadioGroup
                    value={value.fontSize}
                    onValueChange={(fontSize) =>
                        onChange({
                            ...value,
                            fontSize: fontSize as Settings['fontSize'],
                        })
                    }
                    className="grid gap-3 sm:grid-cols-3"
                >
                    {fontSizes.map((option) => {
                        const Icon = fontSizeIcons[option.value];

                        return (
                            <FieldLabel
                                key={option.value}
                                htmlFor={`font-size-${option.value}`}
                            >
                                <Field orientation="horizontal">
                                    <Icon className="mt-0.5 size-4 text-muted-foreground" />
                                    <FieldContent>
                                        <FieldTitle>{option.label}</FieldTitle>
                                        <FieldDescription>
                                            {option.description}
                                        </FieldDescription>
                                    </FieldContent>
                                    <RadioGroupItem
                                        id={`font-size-${option.value}`}
                                        value={option.value}
                                    />
                                </Field>
                            </FieldLabel>
                        );
                    })}
                </RadioGroup>
            </FieldSet>
            <Field>
                <FieldLabel htmlFor="footer_text">Footer text</FieldLabel>
                <Input
                    id="footer_text"
                    maxLength={500}
                    value={value.footerText ?? ''}
                    placeholder="Thank you for staying with us."
                    onChange={(event) =>
                        onDraft({ ...value, footerText: event.target.value })
                    }
                    onBlur={() => onChange(value, { delay: 0 })}
                />
                <FieldDescription>
                    Printed at the foot of every page. Saves when you leave the
                    field.
                </FieldDescription>
            </Field>
        </div>
    );
}
