import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/react';
import { Loader2, TriangleAlert } from 'lucide-react';
import { FormEventHandler, useRef, useState } from 'react';

/**
 * Deleting the account is the one action here that cannot be undone, so it is
 * kept apart — its own block, in the destructive colour, at the bottom — and
 * asks for the password before anything happens.
 */
export default function DeleteUserForm() {
    const [confirming, setConfirming] = useState(false);
    const passwordInput = useRef<HTMLInputElement>(null);

    const {
        data,
        setData,
        delete: destroy,
        processing,
        reset,
        errors,
        clearErrors,
    } = useForm({
        password: '',
    });

    const close = () => {
        setConfirming(false);
        clearErrors();
        reset();
    };

    const deleteUser: FormEventHandler = (event) => {
        event.preventDefault();

        destroy(route('profile.destroy'), {
            preserveScroll: true,
            onSuccess: () => close(),
            onError: () => passwordInput.current?.focus(),
            onFinish: () => reset(),
        });
    };

    return (
        <section className="rounded-2xl border border-destructive/30 bg-destructive/5 p-4 sm:p-5">
            <div className="flex items-start gap-3">
                <TriangleAlert className="mt-0.5 size-5 shrink-0 text-destructive" aria-hidden />

                <div className="min-w-0 flex-1">
                    <h2 className="font-heading text-base font-bold text-destructive">
                        Delete account
                    </h2>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        This permanently removes your shop, bots, customers and orders. It cannot be
                        undone.
                    </p>

                    <Button
                        type="button"
                        variant="outline"
                        className="mt-3 w-full border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive sm:w-auto"
                        onClick={() => setConfirming(true)}
                    >
                        Delete my account
                    </Button>
                </div>
            </div>

            <Dialog open={confirming} onOpenChange={(open) => !open && close()}>
                <DialogContent>
                    <form onSubmit={deleteUser} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Delete your account?</DialogTitle>
                            <DialogDescription>
                                Everything in it is deleted for good. Type your password to confirm
                                it is you.
                            </DialogDescription>
                        </DialogHeader>

                        <div>
                            <Input
                                id="delete_password"
                                type="password"
                                ref={passwordInput}
                                className="h-10"
                                value={data.password}
                                onChange={(event) => setData('password', event.target.value)}
                                placeholder="Your password"
                                autoComplete="current-password"
                                aria-label="Your password"
                                autoFocus
                            />
                            {errors.password && (
                                <p className="mt-1.5 text-sm text-destructive">{errors.password}</p>
                            )}
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={close}>
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                className="bg-destructive text-white hover:bg-destructive/90"
                                disabled={processing || data.password === ''}
                            >
                                {processing && <Loader2 className="size-4 animate-spin" />}
                                Delete account
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </section>
    );
}
