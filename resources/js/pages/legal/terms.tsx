import { Head } from '@inertiajs/react';
import { LegalPage } from '@/components/landing/legal-page';

const description =
    'Terms for using Estatell’s website and hotel operating software, including subscriptions, hotel responsibilities, integrations, and AI-assisted features.';

const sections = [
    {
        id: 'scope',
        title: 'Who we are & what these terms cover',
        paragraphs: [
            'Estatell is a hotel operating platform provided by Jesselton Pixel Sdn. Bhd. (‘Estatell’, ‘we’, ‘us’, or ‘our’). These Terms and Conditions govern your use of our website and, where incorporated into your service agreement, our software and related services. ‘You’ means the visitor, customer, or organisation using them.',
            'By using this website, you agree to these terms. If you use Estatell for a hotel or another organisation, you must have authority to act for it. Do not use the website or services if you do not agree. A signed service agreement, accepted order, or data-processing agreement takes precedence over these terms for the subject matter it specifically covers.',
        ],
    },
    {
        id: 'services',
        title: 'Our platform & service availability',
        paragraphs: [
            'Estatell brings together a Property Management System (PMS), Channel Manager, and Booking Engine for independent and boutique hotels and hotel groups. Depending on your agreed package, services may include reservations, housekeeping, guest messaging, self check-in, e-invoicing, dynamic pricing, and AI Concierge. WAStays is a related booking platform that works with Estatell.',
            'Optional modules, payment methods, channel connections, and smart lock integrations depend on configuration, provider availability, and your agreement. Features marked ‘coming soon’, including any previewed AI voice functionality, are not a commitment to current availability or a delivery date. Confirm required capabilities before purchasing.',
            'We may maintain, update, or change the services. Maintenance, internet failures, and third-party outages can interrupt access or delay synchronisation. Any guaranteed uptime, support response, onboarding timeline, or service credit must be set out in your service agreement.',
        ],
    },
    {
        id: 'accounts',
        title: 'Accounts & acceptable use',
        paragraphs: [
            'Provide accurate business and account information, keep credentials confidential, and grant access only to authorised staff. You are responsible for activity under accounts you control and for promptly removing access when a staff member no longer needs it. Tell us promptly if you suspect unauthorised access.',
            'Do not use the website or services for fraud, unlawful bookings, spam, harassment, or unlawful collection of personal data. Do not upload malicious code, interfere with availability, bypass access controls, access another customer’s records, or copy or exploit our software except as permitted by law or your agreement.',
        ],
    },
    {
        id: 'hotel-responsibilities',
        title: 'Hotel operations & guest bookings',
        paragraphs: [
            'Estatell supplies technology; it does not operate your hotel or provide the accommodation. Unless expressly agreed otherwise, a guest’s accommodation contract is with the hotel. The hotel is responsible for its listings, room availability, rates, taxes, policies, licences, safety, accessibility, and the delivery of accommodation and in-stay services.',
            'Hotels must maintain accurate inventory and rates, review channel synchronisation and booking confirmations, and resolve overbookings, cancellations, no-shows, refunds, deposits, chargebacks, and guest complaints under their published policies and applicable law. Guests should contact their hotel about these matters.',
            'Hotels remain responsible for checking identity-verification requirements, smart lock access rules, invoice and tax information, and operational decisions. Automation does not replace appropriate staff oversight or legal and accounting advice.',
        ],
    },
    {
        id: 'fees',
        title: 'Pricing, payments & subscriptions',
        paragraphs: [
            'PMS and Channel Manager pricing is based on room-count tiers. Direct bookings through your own website are described as commission-free, but the Booking Engine fee still applies. Payment-provider fees, taxes, optional modules, and charges from OTAs or other third parties may apply separately. Your accepted quotation or order sets out the charges that apply to you.',
            'Billing frequency, payment deadlines, subscription duration, renewal, cancellation, refunds, and any price-change notice are governed by your agreed commercial terms and applicable law. Contact us for these details before subscribing; this website does not itself create a free trial, refund entitlement, or automatic-renewal arrangement.',
            'Guest payments and deposits are subject to the hotel’s booking conditions and the relevant payment provider’s terms. Do not treat a demonstration or an unconfirmed payment attempt as a completed reservation or settled payment.',
        ],
    },
    {
        id: 'integrations-ai',
        title: 'Third-party services & AI features',
        paragraphs: [
            'Connected services may include OTAs, FPX or card payment providers, WhatsApp, smart locks, and e-invoicing systems. These providers operate under their own terms and privacy notices. Hotels must hold the required accounts and permissions and comply with the rules of the channels they connect.',
            'Third-party changes, restrictions, or outages can affect integrations. We do not control those providers or guarantee that every external update will arrive without delay. Monitor important reservations, payments, and access events, and keep an appropriate manual fallback for hotel operations.',
            'AI Concierge and pricing or upsell automation assist your team; outputs may be incomplete or incorrect. Hotels must review important responses, configure permitted actions, and verify rates, policies, and booking details. AI responses are not legal advice, an emergency service, or a guarantee of a room or price.',
        ],
    },
    {
        id: 'data-rights',
        title: 'Your data & our intellectual property',
        paragraphs: [
            'As between you and Estatell, you retain your rights in the hotel content and business data you provide. Personal data remains subject to the rights of the individuals concerned. You authorise us to process your content only as needed to deliver and support the agreed services, follow your lawful instructions, and meet legal obligations.',
            'You must have a lawful basis to collect and share guest and staff information, provide required privacy notices, and obtain consent where needed, including for marketing. Our Privacy Policy explains the distinction between data we handle for our own purposes and guest data processed on a hotel’s behalf. Additional processing obligations may be set out in a data-processing agreement.',
            'The Estatell software, website design, branding, and original materials belong to us or our licensors. Your agreement gives you a right to use the services, not ownership of our software or permission to resell it or use our trademarks without authorisation.',
        ],
    },
    {
        id: 'illustrations',
        title: 'Website examples & performance claims',
        paragraphs: [
            'Booking journeys, guest conversations, dashboard previews, sample rates, and savings calculators on this website are illustrative. They do not process live reservations, quote a binding rate, or guarantee savings, revenue growth, occupancy, reduced OTA dependency, or any other business result.',
            'Actual outcomes depend on your property, demand, pricing, channel agreements, configuration, and use of the services. Confirm current product specifications and commercial terms with us rather than relying solely on a marketing example.',
        ],
    },
    {
        id: 'liability',
        title: 'Disclaimers & limits of responsibility',
        paragraphs: [
            'To the extent permitted by applicable law, the website and its illustrative materials are provided ‘as is’ and ‘as available’, without a promise that they will always be accurate, uninterrupted, or error-free. Warranties and remedies for paid services are governed by your service agreement and any rights that cannot lawfully be excluded.',
            'To the extent permitted by law and subject to your service agreement, we are not responsible for indirect or consequential losses, lost profits, or lost business opportunities arising from use of the website or services. Responsibility for third-party failures and other service-related claims is subject to the applicable agreement and law.',
            'Nothing in these terms excludes liability for fraud, fraudulent misrepresentation, or any liability or consumer right that cannot lawfully be excluded or limited. These terms do not remove a hotel’s obligations to its guests.',
        ],
    },
    {
        id: 'ending-services',
        title: 'Suspension & ending services',
        paragraphs: [
            'Subject to your service agreement and applicable law, we may restrict access where reasonably necessary to address misuse, a security threat, unlawful activity, or a material breach, including non-payment. Where practicable and lawful, we will explain the issue and provide an opportunity to resolve it before suspension.',
            'Cancellation, termination notice, final charges, and access to export or return your data follow your service and data-processing agreements. Arrange any required export before access ends. Legal retention duties and backup lifecycles may affect when records can be deleted; ending a subscription does not automatically cancel guest bookings or third-party contracts.',
        ],
    },
    {
        id: 'law-updates',
        title: 'Applicable law, updates & contact',
        paragraphs: [
            'Unless your service agreement states otherwise, these terms are governed by the laws of Malaysia. Disputes are subject to the competent courts of Malaysia, without limiting mandatory protections or rights to bring claims available under applicable law. Please contact us first so we can try to resolve a concern.',
            'We may revise these terms to reflect changes in the website, services, or law and will update the date above. Material changes to an existing paid service are subject to the notice and acceptance requirements in your agreement and applicable law; publishing a website update does not override those requirements.',
            'For questions about these terms, contact Jesselton Pixel Sdn. Bhd. at hello@estatell.com or +6011-6202 3998. Read our Privacy Policy for information about personal data.',
        ],
    },
];

export default function TermsAndConditions() {
    return (
        <>
            <Head title="Terms and Conditions">
                <meta name="description" content={description} />
            </Head>
            <LegalPage
                current="terms"
                title="Terms and Conditions"
                introduction="Clear expectations for using Estatell — from exploring our website to running your hotel with one connected operating core."
                sections={sections}
            />
        </>
    );
}
