import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import type { User } from '@/types';

export function UserInfo({
    user,
    showEmail = false,
    showName = true,
    collapseDetails = false,
}: {
    user: User;
    showEmail?: boolean;
    showName?: boolean;
    collapseDetails?: boolean;
}) {
    const getInitials = useInitials();

    return (
        <>
            <Avatar className="size-8 shrink-0 overflow-hidden rounded-full">
                <AvatarImage src={user.avatar} alt={user.name} />
                <AvatarFallback>{getInitials(user.name)}</AvatarFallback>
            </Avatar>
            {showName && (
                <div
                    className={cn(
                        'grid min-w-0 flex-1 text-left text-sm leading-tight',
                        collapseDetails &&
                            'group-data-[collapsible=icon]:hidden',
                    )}
                >
                    <span className="truncate font-medium">{user.name}</span>
                    {showEmail && (
                        <span className="truncate text-xs text-muted-foreground">
                            {user.email}
                        </span>
                    )}
                </div>
            )}
        </>
    );
}
