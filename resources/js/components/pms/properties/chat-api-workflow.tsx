import { ArrowLeftRight, MessageCircle } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Fragment } from 'react';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

type Node = {
    owner: 'n8n' | 'Estatell';
    icon: LucideIcon;
    title: string;
    description: string;
    fields: [string, string][];
};

/**
 * A line with a solid head that runs from one card's edge to the next. It points down on small screens and right from md.
 */
function Connector() {
    return (
        <div
            aria-hidden
            className="flex h-6 flex-col items-center text-muted-foreground md:h-auto md:w-8 md:flex-row"
        >
            <span className="w-px flex-1 bg-current md:h-px md:w-auto" />
            <svg
                viewBox="0 0 8 6"
                className="h-1.5 w-2 shrink-0 fill-current md:hidden"
            >
                <path d="M0 0h8L4 6z" />
            </svg>
            <svg
                viewBox="0 0 6 8"
                className="hidden h-2 w-1.5 shrink-0 fill-current md:block"
            >
                <path d="M0 0l6 4-6 4z" />
            </svg>
        </div>
    );
}

/**
 * An example n8n workflow for the chat API, as three cards. Only the Estatell card is ours to fill in, so it alone has
 * the primary badge. The `{{ }}` values are placeholders for the fields of n8n's WhatsApp trigger.
 */
export function ChatApiWorkflow({ endpointPath }: { endpointPath: string }) {
    const nodes: Node[] = [
        {
            owner: 'n8n',
            icon: MessageCircle,
            title: 'WhatsApp trigger',
            description: 'On message received',
            fields: [],
        },
        {
            owner: 'Estatell',
            icon: ArrowLeftRight,
            title: 'HTTP request',
            description: `POST ${endpointPath}`,
            fields: [
                ['Auth', 'Bearer <token>'],
                ['channel', 'whatsapp'],
                ['thread', '{{ from }}'],
                ['message', '{{ text }}'],
                ['guest_name', '{{ name }}'],
            ],
        },
        {
            owner: 'n8n',
            icon: MessageCircle,
            title: 'WhatsApp',
            description: 'Send message',
            fields: [
                ['To', '{{ from }}'],
                ['Text', '{{ reply }}'],
            ],
        },
    ];

    return (
        <div className="flex flex-col items-stretch rounded-xl border bg-muted p-4 md:flex-row">
            {nodes.map((node, index) => (
                <Fragment key={node.title}>
                    {index > 0 && <Connector />}
                    <Card size="sm" className="flex-1">
                        <CardHeader>
                            <Badge
                                variant={
                                    node.owner === 'Estatell'
                                        ? 'default'
                                        : 'secondary'
                                }
                            >
                                {node.owner}
                            </Badge>
                            <CardTitle className="flex items-center gap-2">
                                <node.icon
                                    aria-hidden
                                    className="size-4 text-muted-foreground"
                                />
                                {node.title}
                            </CardTitle>
                            <CardDescription className="font-mono text-xs break-all">
                                {node.description}
                            </CardDescription>
                        </CardHeader>
                        {node.fields.length > 0 && (
                            <CardContent>
                                <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                                    {node.fields.map(([label, value]) => (
                                        <Fragment key={label}>
                                            <dt className="text-muted-foreground">
                                                {label}
                                            </dt>
                                            <dd className="font-mono text-xs leading-5 break-all">
                                                {value}
                                            </dd>
                                        </Fragment>
                                    ))}
                                </dl>
                            </CardContent>
                        )}
                    </Card>
                </Fragment>
            ))}
        </div>
    );
}
