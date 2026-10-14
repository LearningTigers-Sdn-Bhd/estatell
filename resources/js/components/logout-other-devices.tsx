import { Form } from '@inertiajs/react';
import { useRef, useState } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Account/SecurityController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useIsImpersonating } from '@/hooks/use-is-impersonating';

export default function LogoutOtherDevices() {
    const [open, setOpen] = useState(false);
    const passwordInput = useRef<HTMLInputElement>(null);
    const impersonating = useIsImpersonating();

    if (impersonating) {
        return null;
    }

    return (
        <div className="flex flex-col gap-6">
            <Heading
                variant="small"
                title="Other devices"
                description="Sign out everywhere except this browser, for example after using a shared computer"
            />

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogTrigger
                    render={
                        <Button
                            variant="outline"
                            className="self-start"
                            data-test="logout-other-devices-button"
                        />
                    }
                >
                    Log out other devices
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Log out other devices?</DialogTitle>
                        <DialogDescription>
                            Enter your password. Every other browser and device
                            signed in to your account will be signed out.
                        </DialogDescription>
                    </DialogHeader>

                    <Form
                        {...SecurityController.logoutOtherDevices.form()}
                        options={{ preserveScroll: true }}
                        onError={() => passwordInput.current?.focus()}
                        onSuccess={() => setOpen(false)}
                        resetOnSuccess
                        resetOnError
                        className="flex flex-col gap-6"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="logout-password">
                                        Password
                                    </Label>

                                    <PasswordInput
                                        id="logout-password"
                                        name="password"
                                        ref={passwordInput}
                                        autoComplete="current-password"
                                        placeholder="Password"
                                    />

                                    <InputError message={errors.password} />
                                </div>

                                <DialogFooter>
                                    <DialogClose
                                        render={<Button variant="outline" />}
                                    >
                                        Cancel
                                    </DialogClose>

                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        focusableWhenDisabled
                                        data-test="confirm-logout-other-devices-button"
                                    >
                                        {processing && <Spinner />}
                                        Log out other devices
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
