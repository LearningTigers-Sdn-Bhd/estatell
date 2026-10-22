import { Head } from '@inertiajs/react';
import { LegalPage } from '@/components/landing/legal-page';

const description =
    'How Estatell handles website enquiries and personal data associated with hotel bookings, guest services, integrations, and your privacy choices.';

const sections = [
    {
        id: 'scope',
        title: 'Who we are & when this policy applies',
        paragraphs: [
            'Estatell is provided by Jesselton Pixel Sdn. Bhd. (‘we’, ‘us’, or ‘our’). This Privacy Policy explains personal data handling in connection with our marketing website, enquiries, customer relationships, and the hotel software features described on this website. It should be read alongside any service-specific privacy notice and the privacy notice of the hotel you book with.',
            'We determine how personal data is used when responding to our own business enquiries and managing customer relationships. When a hotel uses Estatell to manage its guests and staff, the hotel generally determines why that data is collected and how it is used, and Estatell processes it on the hotel’s behalf under the applicable agreement. The hotel’s privacy notice explains its own purposes, lawful basis, and retention requirements.',
            'WAStays is a related booking platform. This policy does not replace the notices presented by WAStays, a hotel, a payment provider, an OTA, or a messaging service when you use their services. Applicable privacy law, including Malaysia’s Personal Data Protection Act 2010 as amended where it applies, takes precedence over any inconsistent wording in this policy.',
        ],
    },
    {
        id: 'website-data',
        title: 'Information from visitors & customers',
        paragraphs: [
            'If you contact us by email or WhatsApp, request a walkthrough, or discuss a subscription, we receive the information you choose to provide. This may include your name, business email, telephone number, role, hotel name, room count, requested modules, and the contents of your enquiry. Customer administration may also involve account, billing, and support correspondence.',
            'Visiting a website involves transmitting technical information such as your IP address, browser and device details, requested URLs, and request times to the server. Hosting and security services may process this information to deliver the site, diagnose faults, and protect against misuse.',
            'The booking journeys, guest conversations, and dashboards shown on this marketing website are demonstrations, not live guest records. Its savings and pricing sliders calculate examples in your browser; they are not a reservation form or a submission of your hotel’s financial information to us.',
        ],
    },
    {
        id: 'hotel-data',
        title: 'Information handled for hotels',
        paragraphs: [
            'The data handled through a hotel’s Estatell account depends on the modules it enables and the information it or its guests provide. Reservation and PMS records may include guest names, contact details, stay dates, room assignments, booking sources, preferences, requests, and booking or payment status. Staff records may include names, work contact details, roles, and assigned housekeeping or service tasks.',
            'If enabled by the hotel, self check-in may involve identity documents, digital signatures, and deposit records. Smart lock integrations may involve room assignments and access credentials. F&B ordering, in-stay requests, feedback, and e-invoicing may involve order details, messages, invoice details, and tax information required for the transaction.',
            'Messaging and AI Concierge features may process the content of guest conversations and the booking information needed to answer a request. Payment services process the information submitted through their checkout; Estatell and the hotel may receive transaction references, amounts, and payment status. The relevant payment provider’s notice explains its handling of card or bank details.',
            'Hotels should collect only what is necessary and permitted by law. Please do not send payment credentials, identity documents, or other sensitive information to our general sales email or WhatsApp contact. Use the secure process specified by your hotel for any required guest documentation.',
        ],
    },
    {
        id: 'purposes',
        title: 'Why personal data is used',
        paragraphs: [
            'For our own customer relationships, we use information to respond to enquiries, arrange demonstrations, prepare quotations, administer agreed services and billing, provide support, communicate service changes, protect the services, and meet legal obligations. We use personal data only where a lawful basis under applicable law permits it, including consent where required or processing necessary for a contract or legal obligation.',
            'On a hotel’s instructions, information may be used to manage reservations and availability, coordinate front desk and housekeeping work, support check-in and room access, process service requests, issue invoices, and send booking confirmations or stay reminders. Connected channels receive information needed to carry out the integration selected by the hotel.',
            'A hotel’s guest database is not permission for unrestricted marketing. Hotels must provide appropriate notices and obtain consent where required before sending promotions, remarketing, or sharing guest details. You may ask the sender to stop promotional messages; essential booking and service messages may still be needed to fulfil your request or meet legal duties.',
            'Providing information for a sales enquiry is voluntary, but we may be unable to respond or prepare a suitable quotation without contact and property details. A hotel should explain which guest details are required by law or for a booking, which are optional, and what happens if required information is not provided.',
        ],
    },
    {
        id: 'sharing',
        title: 'Who may receive information',
        paragraphs: [
            'Information may be accessed by authorised personnel and service providers where needed to operate, support, host, or protect the agreed services. Depending on the features used, recipients may include the relevant hotel and its authorised staff, connected OTAs, payment providers, email and WhatsApp services, AI service providers, smart lock providers, and e-invoicing services.',
            'These recipients do not all have the same role. Some process data on behalf of Estatell or the hotel under contractual instructions; others, such as a payment provider or booking channel, may determine their own uses and must provide their own privacy notices. Enabling an integration does not make every connected provider subject solely to this policy.',
            'We may also disclose information where legally required, to respond to a valid request from a competent authority, to protect legal rights or address fraud and security incidents, or as part of a business transfer subject to applicable safeguards and notice requirements. This policy does not authorise disclosure for unrelated third-party marketing.',
        ],
    },
    {
        id: 'ai',
        title: 'AI-assisted guest services',
        paragraphs: [
            'Where a hotel enables AI Concierge or other AI-assisted features, relevant messages and context may be processed to generate a response or assist with a guest request. What is processed depends on the enabled service and the hotel’s configuration. Website examples do not mean that an AI conversation is taking place with you on this marketing site.',
            'Avoid including unnecessary sensitive information in a chat. AI output can be inaccurate; contact hotel staff to verify important booking, payment, access, or policy information. Ask the hotel about an available human-assisted alternative and the providers and data practices applicable to its deployment. This policy does not itself grant permission to use guest conversations to train general-purpose AI models.',
        ],
    },
    {
        id: 'cookies',
        title: 'Cookies, browser storage & external links',
        paragraphs: [
            'This marketing website does not currently include advertising pixels or analytics trackers in its application code. Its interactive demonstrations run in the browser without requiring a visitor account. Necessary infrastructure or security technologies may still be used to deliver the website; separate registration, booking, and third-party services may use their own cookies or storage.',
            'You can manage cookies and site storage through your browser settings. Disabling necessary technologies can affect features on services that rely on them. If non-essential tracking is introduced, the relevant notice and any consent controls required by law must be provided before it is used.',
            'Clicking a WhatsApp, registration, payment, or other external link takes you to a separate service. That provider may receive technical data and any information you submit under its own privacy notice. Our links do not control how those providers handle your information.',
        ],
    },
    {
        id: 'retention-security',
        title: 'Retention, security & international processing',
        paragraphs: [
            'Personal data should be retained only as long as needed for the purpose for which it was collected, the relevant agreement, and applicable legal, accounting, or dispute-resolution requirements. Retention depends on the type of record and the service involved; there is no single retention period for every record. Hotel-controlled records are subject to the hotel’s instructions and legal duties.',
            'When data is no longer needed, deletion, return, or anonymisation is subject to applicable agreements and legal requirements. Backup copies may remain until the relevant backup lifecycle completes. Contact us, or the hotel responsible for your record, for retention information relevant to your request.',
            'Appropriate technical and organisational safeguards are required to protect personal data, with access limited to authorised purposes. No internet transmission or storage system can be guaranteed completely secure. Protect your account credentials and report suspected unauthorised access promptly. Security incidents must be assessed and notified to affected parties or authorities where applicable law requires it.',
            'Hosting, support, and connected providers may involve processing outside your country, including outside Malaysia. Any such transfer must meet applicable legal requirements and use appropriate protections, including contractual safeguards where required. Contact us for details relevant to your service rather than assuming that all information remains in one country.',
        ],
    },
    {
        id: 'rights',
        title: 'Your privacy rights & choices',
        paragraphs: [
            'Depending on applicable law and our role in the processing, you may request access to or correction of your personal data, withdraw consent, object to direct marketing, or request deletion, restriction, or portability where those rights apply. These rights are not absolute: legal retention duties and other lawful exceptions may limit a request. Withdrawing consent does not invalidate earlier lawful processing and may affect services that require that information.',
            'For our sales, customer, or website-related records, email hello@estatell.com with your request and enough information to identify the relevant interaction. We may need proportionate verification of your identity or authority before releasing or changing records. Do not attach identity documents unless we request them through an appropriate channel.',
            'For a reservation, check-in document, guest message, or other hotel-controlled record, contact the hotel first. Where we process that record on its behalf, we will assist or route your request as required by our obligations rather than independently changing the hotel’s records. We will handle requests within the periods required by applicable law and explain any applicable limitation.',
            'You may also raise a concern with the relevant data-protection authority, including Malaysia’s Personal Data Protection Commissioner where applicable. Contacting us first can help resolve an issue but does not remove your right to make a complaint.',
        ],
    },
    {
        id: 'children-updates',
        title: 'Children, policy changes & contact',
        paragraphs: [
            'Our marketing website and hotel-management accounts are intended for business users, not for children to create accounts independently. Hotels may need information about children travelling with an adult; the hotel is responsible for ensuring a lawful basis and any required parent or guardian authorisation. Contact the hotel or us if you believe a child’s information has been provided inappropriately.',
            'We may update this policy when services, data practices, or legal requirements change. The date above identifies the latest revision. Where law requires additional notice or consent for a material change, a website update alone will not replace that requirement.',
            'For privacy questions or requests concerning Estatell, contact Jesselton Pixel Sdn. Bhd. at hello@estatell.com or +6011-6202 3998. Please state whether your question concerns this website, a customer account, or a particular hotel so it can reach the appropriate team.',
        ],
    },
];

export default function PrivacyPolicy() {
    return (
        <>
            <Head title="Privacy Policy">
                <meta name="description" content={description} />
            </Head>
            <LegalPage
                current="privacy"
                title="Privacy Policy"
                introduction="Your information has a purpose. Here’s how personal data fits into our website, hotel operations, and the guest experiences Estatell helps connect."
                sections={sections}
            />
        </>
    );
}
