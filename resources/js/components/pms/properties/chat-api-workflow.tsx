import {
    ArrowDown,
    ArrowLeftRight,
    ArrowRight,
    MessageCircle,
} from 'lucide-react';
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
        <div className="flex flex-col items-stretch gap-2 md:flex-row">
            {nodes.map((node, index) => (
                <Fragment key={node.title}>
                    {index > 0 && (
                        <div
                            aria-hidden
                            className="flex items-center justify-center text-muted-foreground"
                        >
                            <ArrowDown className="size-4 md:hidden" />
                            <ArrowRight className="hidden size-4 md:block" />
                        </div>
                    )}
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
