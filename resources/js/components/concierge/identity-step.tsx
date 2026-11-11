import { Form } from '@inertiajs/react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { identity } from '@/routes/concierge';

/** The ID step: the guest types the ID fields and may add a photo of each side. */
export function IdentityStep({
    stay,
    kinds,
    genders,
    countries,
}: {
    stay: App.Data.Concierge.ConciergeStayData;
    kinds: EnumOption[];
    genders: EnumOption[];
    countries: EnumOption[];
}) {
    return (
        <Form action={identity.url({ token: stay.token })} method="post">
            {({ processing, errors }) => (
                <div className="flex flex-col gap-6">
                    <div className="flex flex-col gap-1">
                        <h2 className="text-lg font-semibold">Your ID</h2>
                        <p className="text-sm text-pretty text-muted-foreground">
                            Enter the details exactly as on your MyKad or
                            passport. Adding a photo is optional.
                        </p>
                    </div>

                    <FieldGroup>
                        <Field data-invalid={!!errors.kind}>
                            <FieldLabel htmlFor="kind">Document</FieldLabel>
                            <EnumSelect
                                id="kind"
                                name="kind"
                                options={kinds}
                                defaultValue="passport"
                                aria-invalid={!!errors.kind}
                            />
                            <FieldError>{errors.kind}</FieldError>
                        </Field>
                        <Field data-invalid={!!errors.number}>
                            <FieldLabel htmlFor="number">
                                Document number
                            </FieldLabel>
                            <Input
                                id="number"
                                name="number"
                                autoComplete="off"
                                required
                                aria-invalid={!!errors.number}
                            />
                            <FieldError>{errors.number}</FieldError>
                        </Field>
                        <Field data-invalid={!!errors.full_name}>
                            <FieldLabel htmlFor="full_name">
                                Full name
                            </FieldLabel>
                            <Input
                                id="full_name"
                                name="full_name"
                                autoComplete="name"
                                required
                                aria-invalid={!!errors.full_name}
                            />
                            <FieldError>{errors.full_name}</FieldError>
                        </Field>
                        <Field data-invalid={!!errors.nationality}>
                            <FieldLabel htmlFor="nationality">
                                Nationality
                                {stay.nationalityRequired ? '' : ' (optional)'}
                            </FieldLabel>
                            <EnumSelect
                                id="nationality"
                                name="nationality"
                                options={countries}
                                placeholder="Choose a country"
                                aria-invalid={!!errors.nationality}
                            />
                            <FieldError>{errors.nationality}</FieldError>
                        </Field>
                        <Field data-invalid={!!errors.date_of_birth}>
                            <FieldLabel htmlFor="date_of_birth">
                                Date of birth (optional)
                            </FieldLabel>
                            <Input
                                id="date_of_birth"
                                name="date_of_birth"
                                type="date"
                                autoComplete="bday"
                            />
                            <FieldError>{errors.date_of_birth}</FieldError>
                        </Field>
                        <Field>
                            <FieldLabel htmlFor="gender">
                                Gender (optional)
                            </FieldLabel>
                            <EnumSelect
                                id="gender"
                                name="gender"
                                options={genders}
                                placeholder="Choose"
                            />
                        </Field>
                        <Field data-invalid={!!errors.scan}>
                            <FieldLabel htmlFor="scan">
                                Photo of the front (optional)
                            </FieldLabel>
                            <Input
                                id="scan"
                                name="scan"
                                type="file"
                                accept="image/*"
                                capture="environment"
                            />
                            <FieldError>{errors.scan}</FieldError>
                        </Field>
                        <Field data-invalid={!!errors.scan_back}>
                            <FieldLabel htmlFor="scan_back">
                                Photo of the back (optional)
                            </FieldLabel>
                            <Input
                                id="scan_back"
                                name="scan_back"
                                type="file"
                                accept="image/*"
                                capture="environment"
                            />
                            <FieldError>{errors.scan_back}</FieldError>
                        </Field>
                    </FieldGroup>

                    <Button
                        type="submit"
                        size="lg"
                        className="h-12 w-full rounded-full"
                        disabled={processing}
                        focusableWhenDisabled
                    >
                        {processing && <Spinner data-icon="inline-start" />}
                        Save and continue
                    </Button>
                </div>
            )}
        </Form>
    );
}
