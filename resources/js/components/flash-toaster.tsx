import { Toaster } from '@/components/ui/toast';
import { useFlashToast } from '@/hooks/use-flash-toast';

/**
 * The app-wide toast viewport. Shows toasts flashed by the server (`Inertia::flash('toast', ...)`).
 */
export function FlashToaster({ children }: { children: React.ReactNode }) {
    useFlashToast();

    return <Toaster>{children}</Toaster>;
}
