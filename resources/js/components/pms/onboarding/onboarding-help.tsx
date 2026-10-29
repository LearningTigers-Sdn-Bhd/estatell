import { usePage } from '@inertiajs/react';
import { CircleHelp } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverDescription,
    PopoverHeader,
    PopoverTitle,
    PopoverTrigger,
} from '@/components/ui/popover';

/**
 * Where to get help during setup. The support address is a placeholder until the team confirms the real one.
 */
const SUPPORT_EMAIL = 'support@estatell.com';

export function OnboardingHelp() {
    const { impersonation, docsUrl } = usePage().props;

    return (
        <Popover>
            <PopoverTrigger
                render={
                    <Button variant="ghost" size="icon" aria-label="Help" />
                }
            >
                <CircleHelp />
            </PopoverTrigger>
            <PopoverContent align="end" className="w-80">
                <PopoverHeader>
                    <PopoverTitle>Setting up your property</PopoverTitle>
                    <PopoverDescription>
                        Finish each required section to go live. Save and
                        continue keeps your place. A locked section opens as a
                        preview until the section before it is done.
                    </PopoverDescription>
                </PopoverHeader>
                <p className="text-sm">
                    <a
                        href={`${docsUrl}/onboarding`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="font-medium underline underline-offset-4"
                    >
                        Read the setup guide
                    </a>{' '}
                    for each step.
                </p>
                <p className="text-sm">
                    Need a hand? Contact support at{' '}
                    <a
                        href={`mailto:${SUPPORT_EMAIL}`}
                        className="font-medium underline underline-offset-4"
                    >
                        {SUPPORT_EMAIL}
                    </a>
                    .
                </p>
                {impersonation !== null && (
                    <p className="text-sm text-muted-foreground">
                        A support user is viewing this property.
                    </p>
                )}
            </PopoverContent>
        </Popover>
    );
}
