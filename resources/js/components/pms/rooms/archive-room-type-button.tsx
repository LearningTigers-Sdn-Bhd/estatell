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
 * Asks before archiving a room type. Outline in the room type page header.
 */
export function ArchiveRoomTypeButton({
    roomType,
    variant = 'ghost',
}: {
    roomType: RoomTypeName;
    variant?: 'ghost' | 'outline';
}) {
    const [open, setOpen] = useState(false);

    return (
        <ArchiveRoomTypeDialog
            roomType={roomType}
            open={open}
            onOpenChange={setOpen}
            trigger={<Button variant={variant} size="sm" />}
        />
    );
}

/**
 * The archive confirm on its own, for a control that opens it itself (the Available switch in the room types list).
 * `trigger` renders a button that opens it. `onProcessingChange` follows the request.
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
