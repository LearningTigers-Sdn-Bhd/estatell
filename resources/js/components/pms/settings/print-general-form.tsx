import type { EnumOption } from '@/components/pms/enum-select';
import { EnumSelect } from '@/components/pms/enum-select';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';

type Settings = App.Data.Property.PrintSettingsData;

/**
 * Font, font size and footer text. Each change saves by itself; the footer saves on blur.
 */
export function PrintGeneralForm({
    value,
    fonts,
    fontSizes,
    onChange,
    onDraft,
}: {
    value: Settings;
    fonts: EnumOption[];
    fontSizes: EnumOption[];
    onChange: (next: Settings, options?: { delay?: number }) => void;
    onDraft: (next: Settings) => void;
}) {
    return (
        <div className="flex flex-col gap-6">
            <Field>
                <FieldLabel htmlFor="font">Font</FieldLabel>
                <EnumSelect
                    id="font"
                    options={fonts}
                    value={value.font}
                    onValueChange={(font) =>
                        onChange({ ...value, font: font as Settings['font'] })
                    }
                    className="md:w-72"
                />
                <FieldDescription>
                    Chinese text always prints in Noto Sans SC, whatever this
                    setting.
                </FieldDescription>
            </Field>
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
                    className="flex flex-wrap gap-6"
                >
                    {fontSizes.map((option) => (
                        <Field
                            key={option.value}
                            orientation="horizontal"
                            className="w-auto"
                        >
                            <RadioGroupItem
                                id={`font-size-${option.value}`}
                                value={option.value}
                            />
                            <FieldContent>
                                <FieldLabel
                                    htmlFor={`font-size-${option.value}`}
                                >
                                    {option.label}
                                </FieldLabel>
                            </FieldContent>
                        </Field>
                    ))}
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
