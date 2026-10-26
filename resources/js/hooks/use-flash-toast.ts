import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from '@/components/ui/toast';
import type { FlashToast } from '@/types/ui';

export function useFlashToast(): void {
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            const data = flash?.toast as FlashToast | undefined;

            if (!data) {
                return;
            }

            toast.add({
                title: data.message,
                description: data.description,
                type: data.type,
                // A list of reasons takes longer to read than a headline.
                timeout: data.description ? 15000 : undefined,
            });
        });
    }, []);
}
