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

            toast.add({ title: data.message, type: data.type });
        });
    }, []);
}
