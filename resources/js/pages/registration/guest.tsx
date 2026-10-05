import { Head, Link } from '@inertiajs/react';
import type { EnumOption } from '@/components/pms/enum-select';
import { RegistrationCardForm } from '@/components/pms/registration-card/registration-card-form';
import { show } from '@/routes/registration';

type Card = App.Data.Registration.RegistrationCardPageData;

/** The card a guest checks, ticks and signs, on the desk device. Staff open it from the staff card page. */
export default function RegistrationGuestPage({
    card,
    genders,
    identityTypes,
    countries,
}: {
    card: Card;
    genders: EnumOption[];
    identityTypes: EnumOption[];
    countries: EnumOption[];
}) {
    const word = (text: string) => card.labels[text] ?? text;

    return (
        <>
            <Head title={word('Registration card')} />
            <RegistrationCardForm
                key={card.cardId}
                card={card}
                genders={genders}
                identityTypes={identityTypes}
                countries={countries}
            >
                <Link
                    href={show.url({ unitGuest: card.unitGuestId })}
                    className="self-center text-xs text-muted-foreground underline-offset-4 hover:underline"
                >
                    {word('Staff: back to desk')}
                </Link>
            </RegistrationCardForm>
        </>
    );
}
