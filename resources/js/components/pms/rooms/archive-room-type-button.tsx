import { router } from '@inertiajs/react';
import { useState } from 'react';
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

/**
 * Asks before archiving a room type. Used on the room types list and in the room type page header.
 */
export function ArchiveRoomTypeButton({
    roomType,
}: {
    roomType: Pick<App.Data.Rooms.RoomTypeData, 'id' | 'name'>;
}) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    return (
        <AlertDialog open={open} onOpenChange={setOpen}>
            <AlertDialogTrigger render={<Button variant="ghost" size="sm" />}>
                Archive
            </AlertDialogTrigger>
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
                                    onStart: () => setProcessing(true),
                                    onFinish: () => setProcessing(false),
                                    onSuccess: () => setOpen(false),
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
