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
import { CountrySelect } from '@/components/pms/country-select';
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

/** A Malaysian guest has a MyKad; anyone else shows a passport. The guest can still change it. */
const kindFor = (nationalityCode?: string | null) =>
    nationalityCode === 'MY' ? 'my_kad' : 'passport';

/** The saved document types this form offers, as the form names them. */
const savedKinds = { mykad: 'my_kad', passport: 'passport' } as const;

/**
 * The ID form: the guest types the ID fields and may add a photo of each side. It is used on the stay page, for a booking that
 * needs the guest's ID, and on the walk-in details page, where the room the guest picked goes along in `fields` and the booking
 * is made when the form is sent.
 */
export function IdentityStep({
    action,
    fields = {},
    saved = null,
    nationalityRequired,
    contact = '',
    kinds,
    genders,
    countries,
}: {
    /** Where the details are sent. */
    action: string;
    /** Extra values sent with the details, such as the room a walk-in picked. */
    fields?: Record<string, string | number | null>;
    /** The details already saved for the guest, when the page has any. */
    saved?: App.Data.Concierge.ConciergeIdentityPrefillData | null;
    nationalityRequired: boolean;
    /** An email or phone the guest already typed, put in the matching box. */
    contact?: string;
    kinds: EnumOption[];
    genders: EnumOption[];
    countries: EnumOption[];
}) {
    // Malaysia is the usual guest, so it is picked until the guest changes it.
    const [nationality, setNationality] = useState(
        saved?.nationalityCode || 'MY',
    );
    const [kind, setKind] = useState<string>(
        saved?.documentKind === 'mykad' || saved?.documentKind === 'passport'
            ? savedKinds[saved.documentKind]
            : kindFor(nationality),
    );

    const passport = kind === 'passport';

    // The saved number never reaches the page; a blank field with this flag keeps it on the server.
    const keepNumber =
        !!saved?.documentNumberHint &&
        saved.documentKind !== null &&
        savedKinds[saved.documentKind as keyof typeof savedKinds] === kind;

    return (
        <Form action={action} method="post" className="concierge-identity-form">
            {({ processing, errors }) => (
                <div className="flex flex-col gap-6">
                    <HiddenFields fields={fields} />
                    <div className="grid items-start gap-6 lg:grid-cols-3">
                        <div className="flex min-w-0 flex-col gap-6 lg:col-span-2">
                            <Card>
                                <CardHeader>
                                    <CardTitle>
                                        <h2 className="flex items-center gap-2">
                                            <IdCard
                                                className="size-6"
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
                                                className="size-6"
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
                                                {nationalityRequired
                                                    ? ''
                                                    : ' (optional)'}
                                            </FieldLabel>
                                            <CountrySelect
                                                id="nationality"
                                                name="nationality"
                                                options={countries}
                                                value={nationality}
                                                onValueChange={(code) => {
                                                    setNationality(code);
                                                    code &&
                                                        setKind(kindFor(code));
                                                }}
                                                invalid={!!errors.nationality}
                                                className="h-12 text-base md:text-base [&_input]:h-full [&_input]:text-base md:[&_input]:text-base"
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
                                            <FieldLabel id="gender-label">
                                                Gender (optional)
                                            </FieldLabel>
                                            <div
                                                role="radiogroup"
                                                aria-labelledby="gender-label"
                                                className="grid grid-cols-2 gap-3"
                                            >
                                                {genders.map((option) => (
                                                    <label
                                                        key={option.value}
                                                        className="flex h-12 cursor-pointer items-center justify-center rounded-lg border border-input bg-background px-3 text-base transition-colors has-checked:border-primary has-checked:bg-primary has-checked:text-primary-foreground has-focus-visible:ring-3 has-focus-visible:ring-ring/50"
                                                    >
                                                        <input
                                                            type="radio"
                                                            name="gender"
                                                            value={option.value}
                                                            defaultChecked={
                                                                saved?.gender ===
                                                                option.value
                                                            }
                                                            className="sr-only"
                                                        />
                                                        {option.label}
                                                    </label>
                                                ))}
                                            </div>
                                            <FieldError>
                                                {errors.gender}
                                            </FieldError>
                                        </Field>
                                        <Field data-invalid={!!errors.phone}>
                                            <FieldLabel htmlFor="phone">
                                                Phone
                                            </FieldLabel>
                                            <Input
                                                id="phone"
                                                name="phone"
                                                type="tel"
                                                autoComplete="tel"
                                                defaultValue={
                                                    contact.includes('@')
                                                        ? undefined
                                                        : contact
                                                }
                                                className="min-h-12 text-base"
                                                required
                                                aria-invalid={!!errors.phone}
                                            />
                                            <FieldError>
                                                {errors.phone}
                                            </FieldError>
                                        </Field>
                                        <Field data-invalid={!!errors.email}>
                                            <FieldLabel htmlFor="email">
                                                Email
                                            </FieldLabel>
                                            <Input
                                                id="email"
                                                name="email"
                                                type="email"
                                                autoComplete="email"
                                                defaultValue={
                                                    contact.includes('@')
                                                        ? contact
                                                        : undefined
                                                }
                                                className="min-h-12 text-base"
                                                required
                                                aria-invalid={!!errors.email}
                                            />
                                            <FieldError>
                                                {errors.email}
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
                                            className="size-6"
                                            aria-hidden
                                        />
                                        Identification Document Photo
                                        <Badge variant="secondary" size="lg">
                                            Optional
                                        </Badge>
                                    </h2>
                                </CardTitle>
                                <CardDescription>
                                    {passport
                                        ? 'You can add a photo of your passport. You can also continue without it.'
                                        : 'You can add photos of your MyKad. You can also continue without them.'}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <FieldGroup>
                                    <Field data-invalid={!!errors.scan}>
                                        <FieldLabel htmlFor="scan">
                                            {passport
                                                ? 'Document Photo'
                                                : 'Front Document Photo'}
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
                                    {!passport && (
                                        <Field
                                            data-invalid={!!errors.scan_back}
                                        >
                                            <FieldLabel htmlFor="scan_back">
                                                Back Document Photo
                                            </FieldLabel>
                                            <Input
                                                id="scan_back"
                                                name="scan_back"
                                                type="file"
                                                accept="image/*"
                                                capture="environment"
                                                className="h-auto min-h-12 py-2 file:mr-3 file:h-8 file:rounded-md file:bg-muted file:px-3"
                                                aria-invalid={
                                                    !!errors.scan_back
                                                }
                                            />
                                            <FieldError>
                                                {errors.scan_back}
                                            </FieldError>
                                        </Field>
                                    )}
                                    <FieldDescription>
                                        {passport
                                            ? 'Open the passport to the page with your photo. Keep the text clear and all four corners visible.'
                                            : 'Keep the text clear and all four corners visible.'}
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

/** The values a form sends along with what the guest types, such as the room a walk-in picked. */
function HiddenFields({
    fields,
}: {
    fields: Record<string, string | number | null>;
}) {
    return Object.entries(fields).map(([name, value]) =>
        value === null ? null : (
            <input key={name} type="hidden" name={name} value={value} />
        ),
    );
}

/**
 * The first ID screen of a walk-in: the email or phone number the guest used before recognises a returning guest, who
 * then skips the form. A match moves the page on. No match shows the full form, with what was typed kept. The page never
 * says whether a profile exists.
 */
export function ReturningCheck({
    action,
    fields,
    contact,
    onContact,
    onNew,
}: {
    /** Where the email or phone is sent. */
    action: string;
    /** The room the walk-in picked, sent with it so a match can book that room. */
    fields: Record<string, string | number | null>;
    contact: string;
    onContact: (value: string) => void;
    onNew: () => void;
}) {
    return (
        <Form
            action={action}
            method="post"
            onSuccess={onNew}
            className="concierge-identity-form"
        >
            {({ processing, errors }) => (
                <Card>
                    <HiddenFields fields={fields} />
                    <CardHeader>
                        <CardTitle>
                            <h2 className="flex items-center gap-2">
                                <IdCard className="size-6" aria-hidden />
                                Stayed with us before?
                            </h2>
                        </CardTitle>
                        <CardDescription>
                            Enter the email or phone number you used before. If
                            we know you, you can skip the rest of this step.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-6 sm:flex-row sm:items-end">
                        <FieldGroup className="sm:flex-1">
                            <Field data-invalid={!!errors.contact}>
                                <FieldLabel htmlFor="returning-contact">
                                    Email or phone number
                                </FieldLabel>
                                <Input
                                    id="returning-contact"
                                    name="contact"
                                    autoComplete="email"
                                    placeholder="name@example.com or 012-345 6789"
                                    value={contact}
                                    onChange={(event) =>
                                        onContact(event.target.value)
                                    }
                                    className="h-12 px-3 text-base md:text-base"
                                    required
                                    aria-invalid={!!errors.contact}
                                />
                                <FieldError>{errors.contact}</FieldError>
                            </Field>
                        </FieldGroup>
                        <div className="flex flex-col gap-3 sm:shrink-0 sm:flex-row">
                            <Button
                                type="button"
                                variant="outline"
                                size="lg"
                                className="min-h-12"
                                onClick={onNew}
                            >
                                This is my first stay
                            </Button>
                            <Button
                                type="submit"
                                size="lg"
                                className="min-h-12"
                                disabled={processing}
                                focusableWhenDisabled
                            >
                                {processing && (
                                    <Spinner data-icon="inline-start" />
                                )}
                                Continue
                                <ArrowRight
                                    data-icon="inline-end"
                                    aria-hidden
                                />
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            )}
        </Form>
    );
}
