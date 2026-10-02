import { Link } from '@inertiajs/react';
import { ChevronDown, Plus } from 'lucide-react';
import { buttonVariants } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { newMethod, walkIn } from '@/routes/reservations';

/**
 * The New menu of the Reservations list: a booking taken ahead, or a walk-in at the desk.
 */
export function NewBookingMenu() {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger className={buttonVariants()}>
                <Plus data-icon="inline-start" />
                New
                <ChevronDown data-icon="inline-end" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem render={<Link href={newMethod.url()} />}>
                    New booking
                </DropdownMenuItem>
                <DropdownMenuItem render={<Link href={walkIn.url()} />}>
                    Walk-in
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
