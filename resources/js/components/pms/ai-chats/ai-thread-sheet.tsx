import { Link, router } from '@inertiajs/react';
import type { UIMessage } from 'ai';
import { MessageCircle } from 'lucide-react';
import { useState } from 'react';
import {
    guestLabel,
    timeAgo,
} from '@/components/pms/ai-chats/ai-threads-table';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import {
    Conversation,
    ConversationContent,
    ConversationEmptyState,
} from '@/components/ui/conversation';
import {
    Message,
    MessageContent,
    MessageResponse,
} from '@/components/ui/message';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { resolve } from '@/routes/ai-chats';
import { show as reservationShow } from '@/routes/reservations';

type Thread = App.Data.AiAssistant.AiThreadData;

function textOf(message: UIMessage): string {
    return message.parts
        .map((part) => (part.type === 'text' ? part.text : ''))
        .join('');
}

/**
 * One AI chat, read only: the handover summary, the stay's reservation, and every message. Staff contact the guest
 * their own way and press Resolve when the handover is handled. There is no reply box.
 */
export function AiThreadSheet({
    thread,
    onClose,
}: {
    thread: Thread | null;
    onClose: () => void;
}) {
    const [resolving, setResolving] = useState(false);
    const messages = (thread?.messages ?? []) as unknown as UIMessage[];

    const resolveNow = () => {
        if (!thread) {
            return;
        }

        router.post(
            resolve.url({ thread: thread.row.id }),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setResolving(true),
                onFinish: () => setResolving(false),
            },
        );
    };

    return (
        <Sheet
            open={thread !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <SheetContent className="gap-0 data-[side=right]:md:max-w-xl">
                {thread && (
                    <>
                        <SheetHeader className="border-b">
                            <div className="flex items-center gap-2 pr-8">
                                <SheetTitle className="truncate">
                                    {guestLabel(thread.row)}
                                </SheetTitle>
                                <StatusBadge status={thread.row.status} />
                            </div>
                            <SheetDescription>
                                {thread.row.placeLabel}
                                {thread.row.escalatedAt &&
                                    ` · handed over ${timeAgo(thread.row.escalatedAt)} ago`}
                                {thread.resolvedAt &&
                                    ` · resolved by ${thread.resolvedByName ?? 'staff'} ${timeAgo(thread.resolvedAt)} ago`}
                            </SheetDescription>
                        </SheetHeader>

                        {(thread.row.hasSummary ||
                            thread.row.reservationId !== null) && (
                            <div className="flex flex-col gap-2 border-b p-4 text-sm">
                                {thread.row.hasSummary && (
                                    <>
                                        <span className="text-muted-foreground">
                                            Summary
                                        </span>
                                        <p>{thread.row.preview}</p>
                                    </>
                                )}
                                {thread.row.reservationId !== null && (
                                    <Link
                                        href={reservationShow({
                                            reservation:
                                                thread.row.reservationId,
                                        })}
                                        className="w-fit underline underline-offset-4"
                                    >
                                        Open reservation
                                    </Link>
                                )}
                            </div>
                        )}

                        <Conversation className="min-h-0 flex-1">
                            <ConversationContent>
                                {messages.length === 0 ? (
                                    <ConversationEmptyState
                                        icon={<MessageCircle />}
                                        title="No messages"
                                        description="The messages were deleted after 90 days."
                                    />
                                ) : (
                                    messages.map((message) => (
                                        <Message
                                            key={message.id}
                                            from={message.role}
                                        >
                                            <MessageContent>
                                                {message.role ===
                                                'assistant' ? (
                                                    <MessageResponse>
                                                        {textOf(message)}
                                                    </MessageResponse>
                                                ) : (
                                                    <p className="whitespace-pre-wrap">
                                                        {textOf(message)}
                                                    </p>
                                                )}
                                            </MessageContent>
                                        </Message>
                                    ))
                                )}
                            </ConversationContent>
                        </Conversation>

                        {thread.canResolve && (
                            <SheetFooter className="border-t">
                                <Button
                                    onClick={resolveNow}
                                    disabled={resolving}
                                    focusableWhenDisabled
                                >
                                    {resolving && (
                                        <Spinner data-icon="inline-start" />
                                    )}
                                    Resolve
                                </Button>
                            </SheetFooter>
                        )}
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}
