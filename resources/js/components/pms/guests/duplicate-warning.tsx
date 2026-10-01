import { TriangleAlert } from 'lucide-react';
import { StatusBadge } from '@/components/pms/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { useGuestMatches } from '@/hooks/use-guest-matches';
import type { GuestMatchCriteria } from '@/hooks/use-guest-matches';
import { show } from '@/routes/guests';

/**
 * A warning under a form field when the details typed match an existing guest. It never blocks saving.
 * Matches open in a new tab, so the form keeps what was typed.
 */
export function DuplicateWarning({
    criteria,
}: {
    criteria: GuestMatchCriteria;
}) {
    const matches = useGuestMatches(criteria);

    if (matches.length === 0) {
        return null;
    }

    const hasStrongMatch = matches.some((match) => match.isStrong);
    const hasBlacklisted = matches.some((match) => match.isBlacklisted);

    return (
        <Alert variant={hasBlacklisted ? 'destructive' : 'default'}>
            <TriangleAlert aria-hidden />
            <AlertTitle>
                {hasStrongMatch
                    ? 'Possible duplicate guest'
                    : 'A guest with the same name exists'}
            </AlertTitle>
            <AlertDescription>
                <ul className="flex flex-col gap-1">
                    {matches.map((match) => (
                        <li
                            key={match.id}
                            className="flex flex-wrap items-center gap-x-2 gap-y-1"
                        >
                            <a
                                href={show.url({ guest: match.id })}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="font-medium underline underline-offset-4"
                            >
                                {match.fullName}
                            </a>
                            <span>({match.reasons.join(', ')})</span>
                            {match.isVip && (
                                <StatusBadge
                                    status={{
                                        value: 'vip',
                                        label: 'VIP',
                                        tone: 'info',
                                    }}
                                />
                            )}
                            {match.isBlacklisted && (
                                <StatusBadge
                                    status={{
                                        value: 'blacklisted',
                                        label: 'Blacklisted',
                                        tone: 'danger',
                                    }}
                                />
                            )}
                        </li>
                    ))}
                </ul>
            </AlertDescription>
        </Alert>
    );
}
