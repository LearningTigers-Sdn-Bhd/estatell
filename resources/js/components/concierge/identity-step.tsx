import { Form } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowRight, Camera, IdCard, UserRound } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldError,
    FieldDescription,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { identity } from '@/routes/concierge';

/** A Malaysian guest has a MyKad; anyone else shows a passport. The guest can still change it. */
const kindFor = (nationalityCode?: string | null) =>
    nationalityCode === 'MY' ? 'my_kad' : 'passport';

/** The saved document types this form offers, as the form names them. */
const savedKinds = { mykad: 'my_kad', passport: 'passport' } as const;

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
    const saved = stay.identity;
    const [kind, setKind] = useState<string>(
        saved?.documentKind === 'mykad' || saved?.documentKind === 'passport'
            ? savedKinds[saved.documentKind]
            : kindFor(saved?.nationalityCode),
    );

    // The saved number never reaches the page; a blank field with this flag keeps it on the server.
    const keepNumber =
        !!saved?.documentNumberHint &&
        saved.documentKind !== null &&
        savedKinds[saved.documentKind as keyof typeof savedKinds] === kind;

    return (
        <Form
            action={identity.url({ token: stay.token })}
            method="post"
            className="concierge-identity-form"
        >
            {({ processing, errors }) => (
                <div className="flex flex-col gap-6">
                    <div className="grid items-start gap-6 lg:grid-cols-3">
                        <div className="flex min-w-0 flex-col gap-6 lg:col-span-2">
                            <Card>
                                <CardHeader>
                                    <CardTitle>
                                        <h2 className="flex items-center gap-2">
                                            <IdCard
                                                className="size-5"
                                                aria-hidden
                                            />
                                            Identity document
                                        </h2>
                                    </CardTitle>
                                    <CardDescription>
                                        Enter the details exactly as on your
                                        MyKad or passport.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <FieldGroup className="grid gap-5 sm:grid-cols-2">
                                        <Field data-invalid={!!errors.kind}>
                                            <FieldLabel htmlFor="kind">
                                                Document type
                                            </FieldLabel>
                                            <EnumSelect
                                                id="kind"
                                                name="kind"
                                                options={kinds}
                                                value={kind}
                                                onValueChange={setKind}
                                                className="min-h-12 w-full text-base"
                                                aria-invalid={!!errors.kind}
                                            />
                                            <FieldError>
                                                {errors.kind}
                                            </FieldError>
                                        </Field>
                                        <Field data-invalid={!!errors.number}>
                                            {keepNumber && (
                                                <input
                                                    type="hidden"
                                                    name="keep_saved_document"
                                                    value="1"
                                                />
                                            )}
                                            <FieldLabel htmlFor="number">
                                                Document number
                                            </FieldLabel>
                                            <Input
                                                id="number"
                                                name="number"
                                                autoComplete="off"
                                                placeholder={
                                                    keepNumber
                                                        ? `Saved ${saved?.documentNumberHint}. Leave blank to keep`
                                                        : 'Enter document number'
                                                }
                                                className="h-12 px-3 text-base md:text-base"
                                                required={!keepNumber}
                                                aria-invalid={!!errors.number}
                                            />
                                            <FieldError>
                                                {errors.number}
                                            </FieldError>
                                        </Field>
                                    </FieldGroup>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle>
                                        <h2 className="flex items-center gap-2">
                                            <UserRound
                                                className="size-5"
                                                aria-hidden
                                            />
                                            Personal details
                                        </h2>
                                    </CardTitle>
                                    <CardDescription>
                                        Use your name as it appears on your
                                        identity document.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <FieldGroup className="grid gap-5 sm:grid-cols-2">
                                        <Field
                                            className="sm:col-span-2"
                                            data-invalid={!!errors.full_name}
                                        >
                                            <FieldLabel htmlFor="full_name">
                                                Full name
                                            </FieldLabel>
                                            <Input
                                                id="full_name"
                                                name="full_name"
                                                autoComplete="name"
                                                defaultValue={
                                                    saved?.fullName ?? ''
                                                }
                                                placeholder="Enter full name"
                                                className="h-12 px-3 text-base md:text-base"
                                                required
                                                aria-invalid={
                                                    !!errors.full_name
                                                }
                                            />
                                            <FieldError>
                                                {errors.full_name}
                                            </FieldError>
                                        </Field>
                                        <Field
                                            className="sm:col-span-2"
                                            data-invalid={!!errors.nationality}
                                        >
                                            <FieldLabel htmlFor="nationality">
                                                Nationality
                                                {stay.nationalityRequired
                                                    ? ''
                                                    : ' (optional)'}
                                            </FieldLabel>
                                            <EnumSelect
                                                id="nationality"
                                                name="nationality"
                                                options={countries}
                                                onValueChange={(code) =>
                                                    code &&
                                                    setKind(kindFor(code))
                                                }
                                                defaultValue={
                                                    saved?.nationalityCode ??
                                                    undefined
                                                }
                                                placeholder="Choose a country"
                                                className="min-h-12 w-full text-base"
                                                aria-invalid={
                                                    !!errors.nationality
                                                }
                                            />
                                            <FieldError>
                                                {errors.nationality}
                                            </FieldError>
                                        </Field>
                                        <Field
                                            data-invalid={
                                                !!errors.date_of_birth
                                            }
                                        >
                                            <FieldLabel htmlFor="date_of_birth">
                                                {saved?.hasDateOfBirth
                                                    ? 'Date of birth (saved, leave blank to keep)'
                                                    : 'Date of birth (optional)'}
                                            </FieldLabel>
                                            <Input
                                                id="date_of_birth"
                                                name="date_of_birth"
                                                type="date"
                                                autoComplete="bday"
                                                className="h-12 px-3 text-base md:text-base"
                                                aria-invalid={
                                                    !!errors.date_of_birth
                                                }
                                            />
                                            <FieldError>
                                                {errors.date_of_birth}
                                            </FieldError>
                                        </Field>
                                        <Field data-invalid={!!errors.gender}>
                                            <FieldLabel htmlFor="gender">
                                                Gender (optional)
                                            </FieldLabel>
                                            <EnumSelect
                                                id="gender"
                                                name="gender"
                                                options={genders}
                                                defaultValue={
                                                    saved?.gender ?? undefined
                                                }
                                                placeholder="Choose"
                                                className="min-h-12 w-full text-base"
                                                aria-invalid={!!errors.gender}
                                            />
                                            <FieldError>
                                                {errors.gender}
                                            </FieldError>
                                        </Field>
                                    </FieldGroup>
                                </CardContent>
                            </Card>
                        </div>

                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    <h2 className="flex flex-wrap items-center gap-2">
                                        <Camera
                                            className="size-5"
                                            aria-hidden
                                        />
                                        Document photos
                                        <Badge variant="secondary" size="lg">
                                            Optional
                                        </Badge>
                                    </h2>
                                </CardTitle>
                                <CardDescription>
                                    You can add photos of your document. You can
                                    also continue without them.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <FieldGroup>
                                    <Field data-invalid={!!errors.scan}>
                                        <FieldLabel htmlFor="scan">
                                            Front of document
                                        </FieldLabel>
                                        <Input
                                            id="scan"
                                            name="scan"
                                            type="file"
                                            accept="image/*"
                                            capture="environment"
                                            className="h-auto min-h-12 py-2 file:mr-3 file:h-8 file:rounded-md file:bg-muted file:px-3"
                                            aria-invalid={!!errors.scan}
                                        />
                                        <FieldError>{errors.scan}</FieldError>
                                    </Field>
                                    <Field data-invalid={!!errors.scan_back}>
                                        <FieldLabel htmlFor="scan_back">
                                            Back of document
                                        </FieldLabel>
                                        <Input
                                            id="scan_back"
                                            name="scan_back"
                                            type="file"
                                            accept="image/*"
                                            capture="environment"
                                            className="h-auto min-h-12 py-2 file:mr-3 file:h-8 file:rounded-md file:bg-muted file:px-3"
                                            aria-invalid={!!errors.scan_back}
                                        />
                                        <FieldError>
                                            {errors.scan_back}
                                        </FieldError>
                                    </Field>
                                    <FieldDescription>
                                        Keep the text clear and all four corners
                                        visible.
                                    </FieldDescription>
                                </FieldGroup>
                            </CardContent>
                        </Card>
                    </div>

                    <div className="flex justify-end border-t pt-6">
                        <Button
                            type="submit"
                            size="lg"
                            className="h-12 w-full sm:w-auto sm:min-w-56"
                            disabled={processing}
                            focusableWhenDisabled
                        >
                            {processing && <Spinner data-icon="inline-start" />}
                            Save and continue
                            <ArrowRight data-icon="inline-end" aria-hidden />
                        </Button>
                    </div>
                </div>
            )}
        </Form>
    );
}
