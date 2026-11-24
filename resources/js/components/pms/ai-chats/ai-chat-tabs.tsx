import { Inbox, MessagesSquare } from 'lucide-react';
import type { TabLink } from '@/components/pms/tab-links';
import { Badge } from '@/components/ui/badge';
import { all, index } from '@/routes/ai-chats';

/** The Handed over and All tabs of the AI chats inbox. Handed over shows how many chats wait for the front desk. */
export function aiChatTabs(handedOverCount: number): TabLink[] {
    return [
        {
            value: 'handed-over',
            label: (
                <span className="flex items-center gap-2">
                    Handed over
                    {handedOverCount > 0 && (
                        <Badge variant="secondary" className="tabular-nums">
                            {handedOverCount}
                        </Badge>
                    )}
                </span>
            ),
            title: 'Handed over',
            icon: Inbox,
            href: index(),
        },
        {
            value: 'all',
            label: 'All',
            icon: MessagesSquare,
            href: all(),
        },
    ];
}
