import type { ReactNode } from 'react';

/**
 * The guest's QR pages have no sidebar and no login: the code on the table or the room is the only way in.
 */
export default function QrLayout({ children }: { children: ReactNode }) {
    return (
        <div className="min-h-svh bg-background text-foreground">
            {children}
        </div>
    );
}
