import { Head } from '@inertiajs/react';
import { FileText, IdCard, StickyNote } from 'lucide-react';
import type { ReactNode } from 'react';
import { DateText } from '@/components/pms/date-text';
import { DetailLayout } from '@/components/pms/detail-layout';
import { AnonymizeGuestDialog } from '@/components/pms/guests/anonymize-guest-dialog';
import type { DocumentFormOptions } from '@/components/pms/guests/document-sheet';
import { DocumentsTab } from '@/components/pms/guests/documents-tab';
import { GuestForm, guestFormKey } from '@/components/pms/guests/guest-form';
import { NotesTab } from '@/components/pms/guests/notes-tab';
import { PossibleDuplicates } from '@/components/pms/guests/possible-duplicates';
import type { EnumOption } from '@/components/pms/enum-select';
import { GuestFlags } from '@/components/pms/guests/guest-flags';
import {
    BlacklistButton,
    VipButton,
} from '@/components/pms/guests/guest-actions';
import { PageHeader } from '@/components/pms/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { index, show } from '@/routes/guests';
import { index as documentsTab } from '@/routes/guests/documents';
import { index as notesTab } from '@/routes/guests/notes';

type Guest = App.Data.Guests.GuestData;
type Note = App.Data.Guests.GuestNoteData;
type Document = App.Data.Guests.GuestIdentityDocumentData;
type Tab = 'details' | 'notes' | 'documents';

type Props = {
    guest: Guest;
    tab: Tab;
    notes?: Note[];
    documents?: Document[];
    duplicates?: App.Data.Guests.GuestMatchData[];
    retentionDays?: number | null;
    /** Set when the user can add and edit ID documents. */
    documentFormOptions?: DocumentFormOptions | null;
    /** Set when the guest can be edited here, otherwise the Details tab is read-only. */
    formOptions?: {
        countries: EnumOption[];
        genders: EnumOption[];
        locales: EnumOption[];
        tags: App.Data.Guests.TagData[];
    } | null;
    canManage: boolean;
    canBlacklist: boolean;
    canViewDocuments: boolean;
    canMerge: boolean;
    canAnonymize: boolean;
};

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="flex flex-col gap-1">
            <h2 className="text-sm font-medium text-muted-foreground">
                {title}
            </h2>
            <div className="text-sm">{children}</div>
        </section>
    );
}

const NOT_SET = <p className="text-muted-foreground">Not set</p>;

function Details({ guest }: { guest: Guest }) {
    const cityLine = [guest.postalCode, guest.city].filter(Boolean).join(' ');
    const address = [
        guest.addressLine1,
        guest.addressLine2,
        [cityLine, guest.state, guest.countryName].filter(Boolean).join(', '),
    ].filter(Boolean);
    const personal = [
        guest.nationalityName && `Nationality: ${guest.nationalityName}`,
        guest.genderLabel && `Gender: ${guest.genderLabel}`,
        guest.preferredLocaleLabel && `Language: ${guest.preferredLocaleLabel}`,
    ].filter(Boolean);

    return (
        <div className="flex flex-col gap-5">
            <Section title="Email">{guest.email ?? NOT_SET}</Section>
            <Section title="Phone">{guest.phone ?? NOT_SET}</Section>
            <Section title="Tags">
                {guest.tags.length ? (
                    <span className="flex flex-wrap gap-1">
                        {guest.tags.map((tag) => (
                            <Badge key={tag.id} variant="outline">
                                {tag.name}
                            </Badge>
                        ))}
                    </span>
                ) : (
                    NOT_SET
                )}
            </Section>
            <Section title="Address">
                {address.length
                    ? address.map((line) => <p key={line}>{line}</p>)
                    : NOT_SET}
            </Section>
            <Section title="Personal">
                {personal.length
                    ? personal.map((line) => <p key={line}>{line}</p>)
                    : NOT_SET}
                {guest.dateOfBirth && (
                    <p>
                        Date of birth: <DateText date={guest.dateOfBirth} />
                    </p>
                )}
            </Section>
        </div>
    );
}

export default function GuestShow({
    guest,
    tab,
    notes = [],
    documents = [],
    duplicates = [],
    retentionDays = null,
    documentFormOptions = null,
    formOptions = null,
    canManage,
    canBlacklist,
    canViewDocuments,
    canMerge,
    canAnonymize,
}: Props) {
    const isAnonymized = guest.anonymizedAt !== null;
    const tabs = [
        {
            value: 'details',
            label: 'Details',
            icon: FileText,
            href: show.url({ guest: guest.id }),
        },
        {
            value: 'notes',
            label: 'Notes',
            icon: StickyNote,
            href: notesTab.url({ guest: guest.id }),
        },
        ...(canViewDocuments && !isAnonymized
            ? [
                  {
                      value: 'documents',
                      label: 'Documents',
                      icon: IdCard,
                      href: documentsTab.url({ guest: guest.id }),
                  },
              ]
            : []),
    ];

    return (
        <>
            <Head title={guest.fullName} />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title={guest.fullName}
                    description={
                        <>
                            Added <DateText date={guest.createdAt} />
                        </>
                    }
                    badge={
                        <GuestFlags
                            isVip={guest.isVip}
                            isAnonymized={isAnonymized}
                            isBlacklisted={guest.blacklistedAt !== null}
                        />
                    }
                    back={{ href: index.url(), label: 'Guests' }}
                    actions={
                        <>
                            {canManage && !isAnonymized && (
                                <VipButton guest={guest} />
                            )}
                            {canBlacklist && !isAnonymized && (
                                <BlacklistButton guest={guest} />
                            )}
                            {canAnonymize && !isAnonymized && (
                                <AnonymizeGuestDialog guest={guest} />
                            )}
                        </>
                    }
                />

                {isAnonymized && (
                    <Alert>
                        <AlertTitle>Anonymized</AlertTitle>
                        <AlertDescription>
                            This guest was anonymized on{' '}
                            <DateText date={guest.anonymizedAt} />. Personal
                            data was removed. Financial history is kept.
                        </AlertDescription>
                    </Alert>
                )}

                {guest.blacklistedAt && (
                    <Alert variant="destructive">
                        <AlertTitle>Blacklisted</AlertTitle>
                        <AlertDescription>
                            {guest.blacklistReason}
                        </AlertDescription>
                    </Alert>
                )}

                <DetailLayout
                    tabs={tabs}
                    activeTab={tab}
                    panelWidth={tab === 'notes' ? 'full' : 'default'}
                >
                    {tab === 'notes' ? (
                        <NotesTab
                            guest={guest}
                            notes={notes}
                            canAdd={canManage && !isAnonymized}
                        />
                    ) : tab === 'documents' ? (
                        <DocumentsTab
                            guest={guest}
                            documents={documents}
                            formOptions={documentFormOptions}
                            retentionDays={retentionDays}
                        />
                    ) : (
                        <div className="flex flex-col gap-6">
                            {formOptions ? (
                                <GuestForm
                                    key={guestFormKey(guest)}
                                    guest={guest}
                                    {...formOptions}
                                />
                            ) : (
                                <Details guest={guest} />
                            )}
                            <PossibleDuplicates
                                guest={guest}
                                duplicates={duplicates}
                                canMerge={canMerge}
                            />
                        </div>
                    )}
                </DetailLayout>
            </div>
        </>
    );
}

GuestShow.layout = (props: { guest: Guest }) => ({
    breadcrumbs: [
        { title: 'Guests', href: index() },
        { title: props.guest.fullName, href: show({ guest: props.guest.id }) },
    ],
});
