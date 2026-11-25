import { useChat } from '@ai-sdk/react';
import { Link } from '@inertiajs/react';
import { DefaultChatTransport } from 'ai';
import type { UIMessage } from 'ai';
import { MessageCircle, SendHorizontal } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { FormEvent, KeyboardEvent } from 'react';
import {
    Conversation,
    ConversationContent,
    ConversationEmptyState,
    ConversationScrollButton,
} from '@/components/ui/conversation';
import {
    Message,
    MessageContent,
    MessageResponse,
} from '@/components/ui/message';
import { Button } from '@/components/ui/button';
import {
    GuestRequestCard,
    QuoteLinkCard,
    RoomOptionsCard,
} from '@/components/pms/ai-chat/ai-chat-cards';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupTextarea,
} from '@/components/ui/input-group';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';

const MAX_LENGTH = 2000;

type Thread = { thread: string | null; messages: UIMessage[] };

/** Laravel's XSRF cookie as the header it checks, as Inertia sends it. */
function xsrfHeaders(): Record<string, string> {
    const token = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length);

    return token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {};
}

function readStored(key: string | undefined): string | null {
    if (!key) {
        return null;
    }

    try {
        return window.localStorage.getItem(key);
    } catch {
        return null;
    }
}

function writeStored(key: string | undefined, value: string | null): void {
    if (!key) {
        return;
    }

    try {
        if (value === null) {
            window.localStorage.removeItem(key);
        } else {
            window.localStorage.setItem(key, value);
        }
    } catch {
        // Private mode: the chat still works, it just starts again on reload.
    }
}

/** The browser's language as an ISO 639-1 code (`ms` from `ms-MY`), for the Usage tab. Null when it is not two letters. */
function browserLanguage(): string | null {
    const code = navigator.language.split('-')[0]?.toLowerCase() ?? '';

    return /^[a-z]{2}$/.test(code) ? code : null;
}

const CARD_PARTS = [
    'tool-CheckRoomsAndPrices',
    'tool-SendQuoteLink',
    'tool-MakeGuestRequest',
];

/** A finished reply with no text and no card, which the model can rarely send. */
function isEmptyReply(message: UIMessage): boolean {
    return (
        textOf(message) === '' &&
        !message.parts.some((part) => CARD_PARTS.includes(part.type))
    );
}

function textOf(message: UIMessage): string {
    return message.parts
        .map((part) => (part.type === 'text' ? part.text : ''))
        .join('');
}

/**
 * The AI assistant chat: a button that opens a Sheet with the conversation. The server keeps the history; the Sheet
 * loads it from `messagesUrl` when it first opens and sends each message as text to `sendUrl`, which streams the reply.
 * With `storageKey` (the lobby), the browser keeps its thread id in local storage; a stay page has one thread per stay.
 * The header says the guest is chatting with an AI assistant and links to the front desk contact page.
 */
export function AiChatSheet({
    messagesUrl,
    sendUrl,
    storageKey,
    contactUrl,
    requestsUrl,
}: {
    messagesUrl: string;
    sendUrl: string;
    storageKey?: string;
    /** The Concierge contact page of this lobby or stay. */
    contactUrl: string;
    /** The stay's requests page, linked from a sent request. Only the stay chat has one. */
    requestsUrl?: string;
}) {
    const [open, setOpen] = useState(false);
    const [loaded, setLoaded] = useState<{
        id: string;
        messages: UIMessage[];
    } | null>(null);

    useEffect(() => {
        if (!open || loaded) {
            return;
        }

        const stored = readStored(storageKey);
        const url = stored
            ? `${messagesUrl}?thread=${encodeURIComponent(stored)}`
            : messagesUrl;

        void fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })
            .then((response) =>
                response.ok
                    ? (response.json() as Promise<Thread>)
                    : { thread: null, messages: [] },
            )
            .catch((): Thread => ({ thread: null, messages: [] }))
            .then((data) => {
                const id = data.thread ?? crypto.randomUUID();
                writeStored(storageKey, id);
                setLoaded({ id, messages: data.messages });
            });
    }, [open, loaded, messagesUrl, storageKey]);

    return (
        <>
            <Button
                type="button"
                size="lg"
                className="fixed right-4 bottom-4 rounded-full shadow-lg"
                onClick={() => setOpen(true)}
            >
                <MessageCircle data-icon="inline-start" />
                Ask us
            </Button>
            <Sheet open={open} onOpenChange={setOpen}>
                <SheetContent className="w-full gap-0 sm:max-w-md">
                    <SheetHeader className="border-b">
                        <SheetTitle>Ask us</SheetTitle>
                        <SheetDescription>
                            You are chatting with an AI assistant. Do not share
                            card numbers or passwords.{' '}
                            <Link
                                href={contactUrl}
                                className="text-foreground underline underline-offset-4"
                            >
                                Contact the front desk
                            </Link>
                        </SheetDescription>
                    </SheetHeader>
                    {loaded ? (
                        <ChatBody
                            key={loaded.id}
                            id={loaded.id}
                            initialMessages={loaded.messages}
                            sendUrl={sendUrl}
                            requestsUrl={requestsUrl}
                            onThreadEnded={() => {
                                const id = crypto.randomUUID();
                                writeStored(storageKey, id);
                                setLoaded({ id, messages: [] });
                            }}
                        />
                    ) : (
                        <div className="flex flex-1 items-center justify-center">
                            <Spinner />
                        </div>
                    )}
                </SheetContent>
            </Sheet>
        </>
    );
}

function ChatBody({
    id,
    initialMessages,
    sendUrl,
    requestsUrl,
    onThreadEnded,
}: {
    id: string;
    initialMessages: UIMessage[];
    sendUrl: string;
    requestsUrl?: string;
    onThreadEnded: () => void;
}) {
    const [text, setText] = useState('');

    const transport = useMemo(
        () =>
            new DefaultChatTransport({
                api: sendUrl,
                credentials: 'same-origin',
                headers: () => ({
                    Accept: 'text/event-stream',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...xsrfHeaders(),
                }),
                prepareSendMessagesRequest: ({ id: threadId, messages }) => {
                    const last = messages[messages.length - 1];

                    return {
                        body: {
                            id: threadId,
                            text: last ? textOf(last) : '',
                            language: browserLanguage(),
                        },
                    };
                },
                fetch: async (input, init) => {
                    const response = await fetch(input, init);

                    if (response.status === 409) {
                        onThreadEnded();
                    }

                    return response;
                },
            }),
        [sendUrl, onThreadEnded],
    );

    const { messages, sendMessage, status, error } = useChat({
        id,
        messages: initialMessages,
        transport,
    });

    const busy = status === 'submitted' || status === 'streaming';

    function submit(event?: FormEvent) {
        event?.preventDefault();
        const message = text.trim();

        if (message === '' || busy) {
            return;
        }

        setText('');
        void sendMessage({ text: message });
    }

    /** A room picked on a room card goes as the guest's next message. */
    function choose(message: string) {
        if (!busy) {
            void sendMessage({ text: message });
        }
    }

    function onKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            submit();
        }
    }

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <Conversation className="min-h-0 flex-1">
                <ConversationContent>
                    {messages.length === 0 ? (
                        <ConversationEmptyState
                            icon={<MessageCircle />}
                            title="How can we help?"
                            description="Ask about the property, facilities, check-in times or the area."
                        />
                    ) : (
                        messages.map((message, index) => (
                            <Message key={message.id} from={message.role}>
                                <MessageContent>
                                    {message.role === 'assistant' ? (
                                        <>
                                            {textOf(message) !== '' && (
                                                <MessageResponse>
                                                    {textOf(message)}
                                                </MessageResponse>
                                            )}
                                            {message.parts.map((part) =>
                                                part.type ===
                                                    'tool-CheckRoomsAndPrices' &&
                                                part.state ===
                                                    'output-available' ? (
                                                    <RoomOptionsCard
                                                        key={part.toolCallId}
                                                        output={part.output}
                                                        disabled={busy}
                                                        onChoose={choose}
                                                    />
                                                ) : part.type ===
                                                      'tool-SendQuoteLink' &&
                                                  part.state ===
                                                      'output-available' ? (
                                                    <QuoteLinkCard
                                                        key={part.toolCallId}
                                                        output={part.output}
                                                    />
                                                ) : part.type ===
                                                      'tool-MakeGuestRequest' &&
                                                  part.state ===
                                                      'output-available' ? (
                                                    <GuestRequestCard
                                                        key={part.toolCallId}
                                                        output={part.output}
                                                        requestsUrl={
                                                            requestsUrl
                                                        }
                                                    />
                                                ) : null,
                                            )}
                                            {isEmptyReply(message) &&
                                                !(
                                                    busy &&
                                                    index ===
                                                        messages.length - 1
                                                ) && (
                                                    <p className="text-muted-foreground">
                                                        Sorry, I didn&apos;t
                                                        catch that. Could you
                                                        ask again?
                                                    </p>
                                                )}
                                        </>
                                    ) : (
                                        <p className="whitespace-pre-wrap">
                                            {textOf(message)}
                                        </p>
                                    )}
                                </MessageContent>
                            </Message>
                        ))
                    )}
                    {status === 'submitted' && (
                        <Spinner className="text-muted-foreground" />
                    )}
                    {error && (
                        <p className="text-sm text-destructive">
                            We could not send that. Try again in a moment.
                        </p>
                    )}
                </ConversationContent>
                <ConversationScrollButton />
            </Conversation>
            <form onSubmit={submit} className="border-t p-4">
                <InputGroup>
                    <InputGroupTextarea
                        aria-label="Your message"
                        placeholder="Type your question"
                        value={text}
                        maxLength={MAX_LENGTH}
                        onChange={(event) => setText(event.target.value)}
                        onKeyDown={onKeyDown}
                    />
                    <InputGroupAddon align="block-end">
                        <InputGroupButton
                            type="submit"
                            variant="default"
                            size="icon-sm"
                            className="ml-auto"
                            aria-label="Send"
                            disabled={busy || text.trim() === ''}
                            focusableWhenDisabled
                        >
                            {busy ? <Spinner /> : <SendHorizontal />}
                        </InputGroupButton>
                    </InputGroupAddon>
                </InputGroup>
            </form>
        </div>
    );
}
