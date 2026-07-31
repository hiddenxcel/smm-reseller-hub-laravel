import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

export default function Edit() {
    return (
        <AuthenticatedLayout>
            <Head title="Profile" />

            <div className="max-w-3xl space-y-6">
                <div>
                    <h1 className="font-heading text-2xl font-extrabold tracking-tight">
                        Profile
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Your account details and password.
                    </p>
                </div>

                <div className="rounded-xl border border-border bg-card p-5 sm:p-6">
                    <UpdateProfileInformationForm className="max-w-xl" />
                </div>

                <div className="rounded-xl border border-border bg-card p-5 sm:p-6">
                    <UpdatePasswordForm className="max-w-xl" />
                </div>

                <div className="rounded-xl border border-border bg-card p-5 sm:p-6">
                    <DeleteUserForm className="max-w-xl" />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
