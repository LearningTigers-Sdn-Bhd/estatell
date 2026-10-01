import { Head, Link } from '@inertiajs/react';
import { FileText, IdCard, Pencil, Plus, StickyNote } from 'lucide-react';
import type { ReactNode } from 'react';
import { DateText } from '@/components/pms/date-text';
import { DetailLayout } from '@/components/pms/detail-layout';
import { EmptyState } from '@/components/pms/empty-state';
import { AddNoteDialog } from '@/components/pms/guests/add-note-dialog';
import { AnonymizeGuestDialog } from '@/components/pms/guests/anonymize-guest-dialog';
import { DocumentsTab } from '@/components/pms/guests/documents-tab';
import { PossibleDuplicates } from '@/components/pms/guests/possible-duplicates';
import {
    BlacklistButton,
    VipButton,
} from '@/components/pms/guests/guest-actions';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { buttonVariants } from '@/components/ui/button';
import { edit, index, show } from '@/routes/guests';
import {
    create as createDocument,
    index as documentsTab,
} from '@/routes/guests/documents';
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

function Notes({ notes }: { notes: Note[] }) {
    if (notes.length === 0) {
        return (
            <EmptyState
                icon={StickyNote}
                title="No notes yet"
                description="Notes about preferences or past stays show here."
            />
        );
    }

    return (
        <ul className="flex flex-col gap-4">
            {notes.map((note) => (
                <li key={note.id} className="flex flex-col gap-1">
                    <p className="text-sm whitespace-pre-wrap">{note.body}</p>
                    <p className="text-xs text-muted-foreground">
                        {note.authorName ?? 'Former staff member'} ·{' '}
                        <time dateTime={note.createdAt}>
                            {new Date(note.createdAt).toLocaleString('en-GB', {
                                day: '2-digit',
                                month: 'short',
                                year: 'numeric',
                                hour: '2-digit',
                                minute: '2-digit',
                            })}
                        </time>
                    </p>
                </li>
            ))}
        </ul>
    );
}

function Summary({ guest }: { guest: Guest }) {
    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap gap-1.5">
                {guest.isVip && (
                    <StatusBadge
                        status={{ value: 'vip', label: 'VIP', tone: 'info' }}
                    />
                )}
                {guest.anonymizedAt && (
                    <StatusBadge
                        status={{
                            value: 'anonymized',
                            label: 'Anonymized',
                            tone: 'neutral',
                        }}
                    />
                )}
                {guest.blacklistedAt && (
                    <StatusBadge
                        status={{
                            value: 'blacklisted',
                            label: 'Blacklisted',
                            tone: 'danger',
                        }}
                    />
                )}
            </div>
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
            <Section title="Added">
                <DateText date={guest.createdAt} />
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
                    back={{ href: index.url(), label: 'Guests' }}
                    actions={
                        <>
                            {canManage && !isAnonymized && (
                                <>
                                    <VipButton guest={guest} />
                                    {tab === 'notes' && (
                                        <AddNoteDialog guest={guest} />
                                    )}
                                    {tab === 'documents' &&
                                        canViewDocuments && (
                                            <Link
                                                href={createDocument.url({
                                                    guest: guest.id,
                                                })}
                                                className={buttonVariants()}
                                            >
                                                <Plus data-icon="inline-start" />
                                                Add document
                                            </Link>
                                        )}
                                    <Link
                                        href={edit.url({ guest: guest.id })}
                                        className={buttonVariants({
                                            variant: 'outline',
                                        })}
                                    >
                                        <Pencil data-icon="inline-start" />
                                        Edit
                                    </Link>
                                </>
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
                    summary={<Summary guest={guest} />}
                >
                    {tab === 'notes' ? (
                        <Notes notes={notes} />
                    ) : tab === 'documents' ? (
                        <DocumentsTab
                            guest={guest}
                            documents={documents}
                            canManage={canManage}
                            retentionDays={retentionDays}
                        />
                    ) : (
                        <div className="flex flex-col gap-6">
                            <Details guest={guest} />
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
