import { Form, router, useForm } from '@inertiajs/react';
import {
    Check,
    Copy,
    KeyRound,
    MessagesSquare,
    MoreHorizontal,
    Plus,
    TriangleAlert,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { FormOverlay } from '@/components/pms/form-overlay';
import { PanelHeading } from '@/components/pms/panel-heading';
import { ChatApiWorkflow } from '@/components/pms/properties/chat-api-workflow';
import { StatusBadge } from '@/components/pms/status-badge';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import {
    Alert,
    AlertAction,
    AlertDescription,
    AlertTitle,
} from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useClipboard } from '@/hooks/use-clipboard';
import { destroy, store } from '@/routes/admin/properties/chat-api/connections';

type ChatApi = App.Data.AiAssistant.ChatApiTabData;
type Connection = App.Data.AiAssistant.AiConnectionRowData;
type Issued = { name: string; token: string };

const fields: [string, string, string][] = [
    [
        'channel',
        'Required',
        'whatsapp, instagram, messenger, telegram or other',
    ],
    [
        'thread',
        'Required',
        "The channel's chat id. The same id within 24 hours continues the same chat.",
    ],
    ['message', 'Required', "The guest's text, up to 2,000 characters."],
    ['guest_name', 'Optional', 'Shown to staff in AI chats.'],
];

const errors: [string, string, string][] = [
    ['401', 'unauthorized', 'The token is missing, wrong or revoked.'],
    [
        '404',
        'ai_unavailable',
        'The AI assistant or the Chat API is off, or the hotel has no OpenAI key.',
    ],
    ['422', 'invalid_fields', 'A field is missing or too long.'],
    ['429', 'rate_limited', 'More than 60 messages a minute. Wait and retry.'],
    ['503', 'ai_failed', 'OpenAI did not answer. Try again in a moment.'],
];

/** A block of text to copy into n8n, with a Copy button. */
function CodeBlock({ label, code }: { label: string; code: string }) {
    const [copied, copy] = useClipboard();

    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-center justify-between gap-2">
                <h4 className="text-sm font-medium">{label}</h4>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => copy(code)}
                >
                    {copied === code ? (
                        <Check data-icon="inline-start" />
                    ) : (
                        <Copy data-icon="inline-start" />
                    )}
                    {copied === code ? 'Copied' : 'Copy'}
                </Button>
            </div>
            <pre className="overflow-x-auto rounded-lg border bg-muted p-3 font-mono text-xs">
                {code}
            </pre>
        </div>
    );
}

/** The token in a copyable box. It is shown once. */
function TokenBox({ token }: { token: string }) {
    const [copied, copy] = useClipboard();

    return (
        <div className="flex flex-col gap-3 rounded-lg bg-muted p-4">
            <p className="font-mono text-sm break-all">{token}</p>
            <Button
                type="button"
                variant="outline"
                size="sm"
                className="self-start"
                onClick={() => copy(token)}
            >
                {copied === token ? (
                    <Check data-icon="inline-start" />
                ) : (
                    <Copy data-icon="inline-start" />
                )}
                {copied === token ? 'Copied' : 'Copy token'}
            </Button>
        </div>
    );
}

/** A small reference table: three text columns. */
function ReferenceTable({
    head,
    rows,
}: {
    head: [string, string, string];
    rows: [string, string, string][];
}) {
    return (
        <div className="overflow-x-auto rounded-xl border">
            <Table>
                <TableHeader>
                    <TableRow>
                        {head.map((label) => (
                            <TableHead key={label}>{label}</TableHead>
                        ))}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.map(([first, second, third]) => (
                        <TableRow key={first}>
                            <TableCell className="font-mono text-xs">
                                {first}
                            </TableCell>
                            <TableCell className="font-mono text-xs">
                                {second}
                            </TableCell>
                            <TableCell className="whitespace-normal text-muted-foreground">
                                {third}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

/**
 * The Chat API tab on the admin property page: the connections n8n uses to send guest messages to this hotel's
 * assistant, and how to call the API. See docs/35-ai-assistant/03-places-and-routes.md#admin.
 */
export function PropertyChatApi({
    code,
    chatApi,
}: {
    code: string;
    chatApi: ChatApi;
}) {
    const [adding, setAdding] = useState(false);
    const [revoking, setRevoking] = useState<Connection | null>(null);
    const [issued, setIssued] = useState<Issued | null>(null);
    const form = useForm({ name: '' });
    const endpointPath = new URL(chatApi.endpoint).pathname;

    // The token arrives once, as flash data on the redirect after "Add connection".
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;

            if (flash?.aiConnectionToken) {
                setIssued(flash.aiConnectionToken as Issued);
            }
        });
    }, []);

    const addButton = (
        <Button type="button" onClick={() => setAdding(true)}>
            <Plus data-icon="inline-start" />
            Add connection
        </Button>
    );

    const request = [
        `POST ${chatApi.endpoint}`,
        'Authorization: Bearer <token>',
        'Content-Type: application/json',
        '',
        JSON.stringify(
            {
                channel: 'whatsapp',
                thread: '60123456789@c.us',
                message: 'Room for 2 on 12 Dec?',
                guest_name: 'Aisha',
            },
            null,
            2,
        ),
    ].join('\n');

    const reply = JSON.stringify(
        {
            thread_id: '01JD8Z…',
            reply: 'Yes, we have a Deluxe Sea View for 12 Dec …',
            escalated: false,
        },
        null,
        2,
    );

    return (
        <div className="flex flex-col gap-6">
            <div className="flex flex-col gap-4">
                <PanelHeading
                    icon={MessagesSquare}
                    title="Chat API"
                    description="n8n or another tool sends guest messages to this hotel's assistant. Estatell sets it up for the hotel."
                    actions={addButton}
                />

                {chatApi.keyBlocksReplies ? (
                    <Alert>
                        <TriangleAlert />
                        <AlertTitle>
                            The hotel's OpenAI key is not working
                        </AlertTitle>
                        <AlertDescription>
                            n8n messages get the handover message until the
                            hotel adds or fixes its key in Settings › AI
                            assistant.
                        </AlertDescription>
                    </Alert>
                ) : (
                    <Alert>
                        <KeyRound />
                        <AlertTitle>The hotel's OpenAI key</AlertTitle>
                        <AlertDescription>
                            The chat API answers with the hotel's own key.
                        </AlertDescription>
                        <AlertAction>
                            <StatusBadge status={chatApi.keyStatus} />
                        </AlertAction>
                    </Alert>
                )}

                <div className="overflow-x-auto rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Last used</TableHead>
                                <TableHead className="w-10">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {chatApi.connections.length === 0 && (
                                <TableRow className="hover:bg-transparent">
                                    <TableCell colSpan={4}>
                                        <EmptyState
                                            icon={MessagesSquare}
                                            title="No connections yet"
                                            description="Add one to get a token for n8n."
                                        />
                                    </TableCell>
                                </TableRow>
                            )}
                            {chatApi.connections.map((connection) => (
                                <TableRow key={connection.id}>
                                    <TableCell className="font-medium">
                                        {connection.name}
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant={
                                                connection.canRevoke
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                        >
                                            {connection.canRevoke
                                                ? 'Active'
                                                : 'Revoked'}
                                        </Badge>
                                    </TableCell>
                                    <TableCell>
                                        <DateText
                                            date={connection.lastUsedAt}
                                        />
                                    </TableCell>
                                    <TableCell>
                                        {connection.canRevoke && (
                                            <DropdownMenu>
                                                <DropdownMenuTrigger
                                                    render={
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon-sm"
                                                            aria-label={`Actions for ${connection.name}`}
                                                        />
                                                    }
                                                >
                                                    <MoreHorizontal />
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuItem
                                                        variant="destructive"
                                                        onClick={() =>
                                                            setRevoking(
                                                                connection,
                                                            )
                                                        }
                                                    >
                                                        Revoke connection
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>

            <section className="flex flex-col gap-4">
                <h3 className="text-base font-medium">How to call it</h3>
                <div className="flex flex-col gap-2">
                    <h4 className="text-sm font-medium">
                        Example n8n workflow
                    </h4>
                    <ChatApiWorkflow endpointPath={endpointPath} />
                </div>
                <CodeBlock label="Request" code={request} />
                <div className="flex flex-col gap-2">
                    <h4 className="text-sm font-medium">Fields</h4>
                    <ReferenceTable
                        head={['Field', 'Needed', 'What']}
                        rows={fields}
                    />
                </div>
                <div className="flex flex-col gap-2">
                    <CodeBlock label="Reply (200)" code={reply} />
                    <p className="text-sm text-muted-foreground">
                        Send <code className="font-mono text-xs">reply</code>{' '}
                        back to the guest. When{' '}
                        <code className="font-mono text-xs">escalated</code> is
                        true, staff have been told.
                    </p>
                </div>
                <div className="flex flex-col gap-2">
                    <h4 className="text-sm font-medium">Errors</h4>
                    <ReferenceTable
                        head={['Status', 'error.code', 'When']}
                        rows={errors}
                    />
                </div>
            </section>

            <FormOverlay
                variant="dialog"
                open={adding}
                onOpenChange={(open) => {
                    setAdding(open);

                    if (!open) {
                        form.reset();
                        form.clearErrors();
                    }
                }}
                title="Add connection"
                description="You get a token to put in n8n. It is shown once."
                errors={form.errors}
                processing={form.processing}
                submitLabel="Add connection"
                onSubmit={() =>
                    form.post(store.url(code), {
                        preserveScroll: true,
                        onSuccess: () => {
                            setAdding(false);
                            form.reset();
                        },
                    })
                }
            >
                <Field data-invalid={!!form.errors.name}>
                    <FieldLabel htmlFor="connection-name">Name</FieldLabel>
                    <Input
                        id="connection-name"
                        placeholder="e.g. n8n – WhatsApp"
                        maxLength={100}
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        aria-invalid={!!form.errors.name}
                        autoFocus
                    />
                    <FieldError>{form.errors.name}</FieldError>
                </Field>
            </FormOverlay>

            <Dialog
                open={issued !== null}
                onOpenChange={(open) => !open && setIssued(null)}
            >
                <DialogContent>
                    {issued && (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    Token for {issued.name}
                                </DialogTitle>
                                <DialogDescription>
                                    Copy it into n8n now. You will not see it
                                    again.
                                </DialogDescription>
                            </DialogHeader>
                            <TokenBox token={issued.token} />
                            <DialogFooter>
                                <Button onClick={() => setIssued(null)}>
                                    Done
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </DialogContent>
            </Dialog>

            <AlertDialog
                open={revoking !== null}
                onOpenChange={(open) => !open && setRevoking(null)}
            >
                <AlertDialogContent>
                    {revoking && (
                        <Form
                            {...destroy.form({
                                property: code,
                                aiConnection: revoking.id,
                            })}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setRevoking(null)}
                        >
                            {({ processing }) => (
                                <>
                                    <AlertDialogHeader>
                                        <AlertDialogTitle>
                                            Revoke {revoking.name}?
                                        </AlertDialogTitle>
                                        <AlertDialogDescription>
                                            n8n can no longer send messages with
                                            this token. Past chats stay in AI
                                            chats.
                                        </AlertDialogDescription>
                                    </AlertDialogHeader>
                                    <AlertDialogFooter>
                                        <AlertDialogCancel>
                                            Cancel
                                        </AlertDialogCancel>
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            disabled={processing}
                                            focusableWhenDisabled
                                        >
                                            {processing && (
                                                <Spinner data-icon="inline-start" />
                                            )}
                                            Revoke connection
                                        </Button>
                                    </AlertDialogFooter>
                                </>
                            )}
                        </Form>
                    )}
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
