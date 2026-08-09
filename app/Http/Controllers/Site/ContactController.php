<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Notifications\ContactMessageReceived;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

class ContactController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'min:10', 'max:4000'],
            // A field no person sees and no person fills. Bots fill every
            // input they find, so anything here means the submission is not
            // worth delivering.
            'website' => ['nullable', 'size:0'],
        ], [
            'website.size' => 'That submission looked automated.',
        ]);

        // A mailbox somebody opens, not the noreply@ everything is sent from.
        // These were the same address, so every message the form collected was
        // delivered to an account with no reader and no forwarding.
        $to = config('mail.contact_to');

        if (blank($to)) {
            throw ValidationException::withMessages([
                'message' => 'Contact is not set up yet — please reach us on WhatsApp.',
            ]);
        }

        try {
            Notification::route('mail', $to)->notify(new ContactMessageReceived(
                name: $validated['name'],
                email: $validated['email'],
                subject: $validated['subject'],
                body: $validated['message'],
            ));
        } catch (Throwable $e) {
            // Losing the message silently is worse than saying so: the sender
            // would walk away believing they had been heard.
            Log::error('Contact message could not be sent', ['error' => $e->getMessage()]);

            throw ValidationException::withMessages([
                'message' => 'We could not send that just now. Please try again, or reach us on WhatsApp.',
            ]);
        }

        return back()->with('status', 'Thanks — we have your message and will reply by email.');
    }
}
