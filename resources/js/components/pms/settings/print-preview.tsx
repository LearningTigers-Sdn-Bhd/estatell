import { ExternalLink } from 'lucide-react';
import type { FontStyleOption } from '@/components/pms/settings/print-general-form';
import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { sample } from '@/routes/settings/printing';

type Settings = App.Data.Property.PrintSettingsData;
type Profile = App.Data.Property.PropertyProfileData;
type Tax = App.Data.Property.PrintTaxData;

/**
 * Tailwind sizes for each PDF size, one step apart like the PDF: header lines and the footer are 1 pt below the body,
 * the property name 2 pt above it.
 */
const textSizes: Record<
    App.Enums.PdfFontSize,
    { body: string; small: string; heading: string }
> = {
    small: { body: 'text-sm', small: 'text-xs', heading: 'text-base' },
    medium: { body: 'text-base', small: 'text-sm', heading: 'text-lg' },
    large: { body: 'text-lg', small: 'text-base', heading: 'text-xl' },
};

/** The address lines as the PDF prints them: line 1, line 2, postcode and city, country. */
export function addressLines(profile: Profile): string[] {
    return [
        profile.addressLine1,
        profile.addressLine2,
        [profile.postalCode, profile.city].filter(Boolean).join(' '),
        profile.countryName,
    ].filter((line): line is string => !!line);
}

/** Phone and email on one line, as the PDF prints them. */
export function contactLine(profile: Profile): string | null {
    return (
        [profile.contactPhone, profile.contactEmail]
            .filter(Boolean)
            .join(' · ') || null
    );
}

/**
 * A browser drawing of the PDF letterhead and footer from the form state. Browser fonts differ from dompdf fonts, so
 * it is close but not exact; Open sample PDF shows the real output of the saved settings.
 */
export function PrintPreview({
    settings,
    profile,
    taxes,
    fonts,
}: {
    settings: Settings;
    fonts: FontStyleOption[];
    profile: Profile;
    taxes: Tax[];
}) {
    const contact = contactLine(profile);
    const font = fonts.find((option) => option.value === settings.font);
    const size = textSizes[settings.fontSize];
    const registration = [
        settings.showRegistrationNumber && profile.registrationNumber
            ? `Registration no. ${profile.registrationNumber}`
            : null,
        settings.showTin && profile.tin ? `TIN ${profile.tin}` : null,
        ...taxes
            .filter(
                (tax) =>
                    settings.taxIds.includes(tax.id) && tax.registrationNumber,
            )
            .map((tax) => `${tax.code} ${tax.registrationNumber}`),
    ].filter((line): line is string => !!line);

    return (
        <aside className="flex flex-col gap-3 lg:sticky lg:top-4">
            <div
                className={cn(
                    'flex flex-col gap-4 rounded-lg border bg-card p-4 text-card-foreground',
                    size.body,
                )}
                style={{ fontFamily: font?.bodyFamily }}
            >
                <div className="flex items-start gap-3">
                    {settings.showLogo && profile.logoUrl && (
                        <img
                            src={profile.logoUrl}
                            alt=""
                            className="h-12 w-auto max-w-20 object-contain"
                        />
                    )}
                    <div className="flex min-w-0 flex-col gap-0.5">
                        <p
                            className={cn('font-bold', size.heading)}
                            style={{ fontFamily: font?.headingFamily }}
                        >
                            {profile.name}
                        </p>
                        {settings.showAddress &&
                            addressLines(profile).map((line) => (
                                <p key={line} className={size.small}>
                                    {line}
                                </p>
                            ))}
                        {settings.showContact && contact && (
                            <p className={size.small}>{contact}</p>
                        )}
                        {registration.map((line) => (
                            <p
                                key={line}
                                className={cn(
                                    size.small,
                                    'text-muted-foreground',
                                )}
                            >
                                {line}
                            </p>
                        ))}
                    </div>
                </div>
                <div className="flex flex-col gap-1 border-t pt-3">
                    <p
                        className={cn('font-bold', size.heading)}
                        style={{ fontFamily: font?.headingFamily }}
                    >
                        Sample report
                    </p>
                    <div className="flex justify-between gap-2 tabular-nums">
                        <span className="truncate">Aisyah binti Rahman</span>
                        <span>480.00</span>
                    </div>
                    <div className="flex justify-between gap-2 tabular-nums">
                        <span className="truncate">Daniel Lim</span>
                        <span>210.00</span>
                    </div>
                </div>
                {settings.footerText && (
                    <p
                        className={cn(
                            'border-t pt-3 text-center text-muted-foreground',
                            size.small,
                        )}
                    >
                        {settings.footerText}
                    </p>
                )}
            </div>
            <a
                href={sample.url()}
                target="_blank"
                rel="noreferrer"
                className={buttonVariants({
                    variant: 'outline',
                    className: 'self-start',
                })}
            >
                <ExternalLink data-icon="inline-start" />
                Open sample PDF
            </a>
        </aside>
    );
}
