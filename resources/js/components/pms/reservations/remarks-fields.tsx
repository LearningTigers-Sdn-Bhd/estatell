import type { EnumOption } from '@/components/pms/enum-select';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';

/** The longest remark, as `ValidatesRemarks` allows. */
export const REMARK_LIMIT = 500;

export type RemarksValue = Partial<Record<App.Enums.RemarkKind, string>>;

/**
 * One optional text box for each remark kind (housekeeping, dietary, package, payment), for the booking form Notes tab
 * and the Edit remarks Dialog. The labels come from `RemarkKind::options()`.
 */
export function RemarksFields({
    idPrefix,
    kinds,
    value,
    onChange,
    errors,
}: {
    idPrefix: string;
    kinds: EnumOption[];
    value: RemarksValue;
    onChange: (value: RemarksValue) => void;
    errors: Partial<Record<string, string>>;
}) {
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            {kinds.map((kind) => {
                const key = kind.value as App.Enums.RemarkKind;
                const error = errors[`remarks.${key}`];
                const id = `${idPrefix}-${key}`;

                return (
                    <Field key={key} data-invalid={!!error}>
                        <FieldLabel htmlFor={id}>{kind.label}</FieldLabel>
                        <Textarea
                            id={id}
                            name={`remarks[${key}]`}
                            rows={2}
                            maxLength={REMARK_LIMIT}
                            value={value[key] ?? ''}
                            onChange={(event) =>
                                onChange({
                                    ...value,
                                    [key]: event.target.value,
                                })
                            }
                            aria-invalid={!!error}
                        />
                        <FieldError>{error}</FieldError>
                    </Field>
                );
            })}
        </div>
    );
}
