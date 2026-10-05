import { useForm } from '@inertiajs/react';

type Card = App.Data.Registration.RegistrationCardPageData;

/** The guest screen's form state, started from the card's current answers. */
export function useCardForm(card: Card) {
    return useForm({
        full_name: card.values.full_name as string,
        nationality_code: (card.values.nationality_code as string) ?? '',
        date_of_birth: (card.values.date_of_birth as string) ?? '',
        gender: (card.values.gender as string) ?? '',
        phone: (card.values.phone as string) ?? '',
        email: (card.values.email as string) ?? '',
        address_line1: (card.values.address_line1 as string) ?? '',
        address_line2: (card.values.address_line2 as string) ?? '',
        city: (card.values.city as string) ?? '',
        state: (card.values.state as string) ?? '',
        postal_code: (card.values.postal_code as string) ?? '',
        country_code: (card.values.country_code as string) ?? '',
        vehicle_plate: (card.values.vehicle_plate as string) ?? '',
        marketing_consent: card.values.marketing_consent as boolean,
        identity_document_id:
            card.cardDocumentId !== null
                ? String(card.cardDocumentId)
                : card.documents[0]
                  ? String(card.documents[0].id)
                  : '',
        identity: {
            type: 'mykad',
            number: '',
            issuing_country_code: '',
            expires_on: '',
        },
        locale: card.locale,
        ticks: [] as number[],
        signer_name: card.guestName,
        signature: '',
    });
}

export type CardForm = ReturnType<typeof useCardForm>;

/** What every section of the guest screen needs: the form, the words and the field layout. */
export type CardSectionProps = {
    card: Card;
    form: CardForm;
    word: (text: string) => string;
    /** The label with a star when the Layout tab makes the field required. */
    mark: (field: string | null, label: string) => string;
    has: (field: string) => boolean;
};
