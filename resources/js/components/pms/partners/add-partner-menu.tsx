import { ChevronDown, KeyRound, Mail } from 'lucide-react';
import { useState } from 'react';
import { AddPartnerDialog } from '@/components/pms/partners/add-partner-dialog';
import { InvitePartnerDialog } from '@/components/pms/partners/invite-partner-dialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/**
 * The Add partner menu on the Partners page. `onAdded` gets the new login's id after Add with temp password, so the
 * page can open the copy message.
 */
export function AddPartnerMenu({
    onAdded,
}: {
    onAdded: (userId: number) => void;
}) {
    const [inviting, setInviting] = useState(false);
    const [adding, setAdding] = useState(false);

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger render={<Button />}>
                    Add partner
                    <ChevronDown data-icon="inline-end" />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-56">
                    <DropdownMenuItem onClick={() => setInviting(true)}>
                        <Mail />
                        Invite by email
                    </DropdownMenuItem>
                    <DropdownMenuItem onClick={() => setAdding(true)}>
                        <KeyRound />
                        Add with temp password
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <InvitePartnerDialog open={inviting} onOpenChange={setInviting} />
            <AddPartnerDialog
                open={adding}
                onOpenChange={setAdding}
                onAdded={onAdded}
            />
        </>
    );
}
