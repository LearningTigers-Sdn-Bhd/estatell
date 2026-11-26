import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactElement } from 'react';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { archive } from '@/routes/settings/room-types';

type RoomTypeName = Pick<App.Data.Rooms.RoomTypeData, 'id' | 'name'>;

/**
 * Asks before archiving a room type. Opened by the Available switch in the room types list and by More actions on
 * the room type page. `trigger` renders a button that opens it. `onProcessingChange` follows the request.
 */
export function ArchiveRoomTypeDialog({
    roomType,
    open,
    onOpenChange,
    trigger,
    onProcessingChange,
}: {
    roomType: RoomTypeName;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    trigger?: ReactElement;
    onProcessingChange?: (processing: boolean) => void;
}) {
    const [processing, setProcessing] = useState(false);

    const changeProcessing = (next: boolean) => {
        setProcessing(next);
        onProcessingChange?.(next);
    };

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            {trigger && (
                <AlertDialogTrigger render={trigger}>
                    Archive
                </AlertDialogTrigger>
            )}
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Archive {roomType.name}?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        It leaves the active list and cannot be used for new
                        work. You can restore it later.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <Button
                        variant="destructive"
                        disabled={processing}
                        focusableWhenDisabled
                        onClick={() =>
                            router.post(
                                archive.url({ roomType: roomType.id }),
                                {},
                                {
                                    preserveScroll: true,
                                    onStart: () => changeProcessing(true),
                                    onFinish: () => changeProcessing(false),
                                    onSuccess: () => onOpenChange(false),
                                },
                            )
                        }
                    >
                        {processing && <Spinner data-icon="inline-start" />}
                        Archive
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
