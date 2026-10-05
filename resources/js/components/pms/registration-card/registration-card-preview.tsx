import { cn } from '@/lib/utils';

type Field = App.Data.Registration.RegistrationCardFieldData;

/** Made-up values, so the preview shows what each line looks like. */
const samples: Record<string, string> = {
    full_name: 'Aisyah Rahman',
    nationality: 'Malaysia',
    identity_document: 'Passport A1234567',
    date_of_birth: '14 Mar 1990',
    gender: 'Female',
    address: '12 Jalan Ampang, Kuala Lumpur',
    phone: '+60 12 345 6789',
    email: 'aisyah@example.com',
    vehicle_plate: 'WXY 1234',
    remarks: 'Late arrival, extra pillow',
    booking_number: 'BK-0142',
    check_in: '12 Jun 2026, 15:00',
    check_out: '15 Jun 2026, 11:00',
    nights: '3',
    guest_count: '2 adults',
    room: 'Deluxe Queen · 204',
    rate_per_night: 'RM 220.00',
    total_charges: 'RM 660.00',
};

function Line({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex flex-col gap-0.5">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="font-medium">{value}</dd>
        </div>
    );
}

function Section({
    title,
    className,
    children,
}: {
    title: string;
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <section className="flex flex-col gap-2 border-t pt-3">
            <h4 className="text-[0.65rem] font-medium tracking-wide text-muted-foreground uppercase">
                {title}
            </h4>
            <dl className={cn('grid grid-cols-2 gap-x-3 gap-y-2', className)}>
                {children}
            </dl>
        </section>
    );
}

/**
 * A rough picture of the registration card, drawn from the unsaved table.
 * It uses sample data. The real card comes with the signing flow.
 */
export function RegistrationCardPreview({
    fields,
    visibilities,
    propertyName,
}: {
    fields: Field[];
    visibilities: Record<string, string>;
    propertyName: string;
}) {
    const shown = (group: string) =>
        fields.filter(
            (field) =>
                field.group === group && visibilities[field.field] !== 'hidden',
        );
    const label = (field: Field) =>
        visibilities[field.field] === 'required'
            ? `${field.label} *`
            : field.label;
    const guest = shown('guest');
    const marketing = guest.find(
        (field) => field.field === 'marketing_consent',
    );
    const stay = shown('stay');
    const prices = shown('prices');

    return (
        <aside
            aria-label="Registration card preview"
            className="flex flex-col gap-3 rounded-lg border bg-card p-4 text-xs text-card-foreground lg:sticky lg:top-4"
        >
            <header className="flex flex-col gap-0.5">
                <p className="text-sm font-medium">{propertyName}</p>
                <p className="text-muted-foreground">Registration card</p>
            </header>

            <Section title="Guest details">
                {guest
                    .filter((field) => field !== marketing)
                    .map((field) => (
                        <Line
                            key={field.field}
                            label={label(field)}
                            value={samples[field.field]}
                        />
                    ))}
            </Section>

            {stay.length > 0 && (
                <Section title="Stay details">
                    {stay.map((field) => (
                        <Line
                            key={field.field}
                            label={field.label}
                            value={samples[field.field]}
                        />
                    ))}
                </Section>
            )}

            {prices.length > 0 && (
                <Section title="Estimated charges at booking">
                    {prices.map((field) => (
                        <Line
                            key={field.field}
                            label={field.label}
                            value={samples[field.field]}
                        />
                    ))}
                </Section>
            )}

            {marketing && (
                <p className="flex items-center gap-2 border-t pt-3">
                    <span
                        aria-hidden
                        className="size-3.5 shrink-0 rounded-sm border"
                    />
                    <span>I agree to receive offers from {propertyName}.</span>
                </p>
            )}

            <div className="flex flex-col gap-1.5 border-t pt-3" aria-hidden>
                <span className="h-1.5 w-full rounded-full bg-muted" />
                <span className="h-1.5 w-11/12 rounded-full bg-muted" />
                <span className="h-1.5 w-3/5 rounded-full bg-muted" />
            </div>
            <div className="mt-2 flex flex-col gap-1">
                <span className="h-8 border-b" aria-hidden />
                <span className="text-muted-foreground">Guest signature</span>
            </div>
        </aside>
    );
}
