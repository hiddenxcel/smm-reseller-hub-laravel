<?php

namespace App\Http\Controllers;

use App\Models\Broadcast;
use App\Services\Broadcasts\Audience;
use App\Services\Broadcasts\Broadcasts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One message to many customers: a new service, a price drop, an update.
 *
 * Only people WhatsApp allows free text to are sent to (those who wrote in the
 * last 24 hours), plus everyone on Telegram. The screen says how many that is
 * before anything goes out.
 */
class BroadcastController extends Controller
{
    public function index(Request $request): Response
    {
        $tenant = $request->user();
        $audience = Audience::for($tenant->id);

        return Inertia::render('OrderBot/Broadcast', [
            'segments' => collect(Audience::SEGMENTS)
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values()
                ->all(),
            'channels' => ['whatsapp' => $audience->hasWhatsApp(), 'telegram' => $audience->hasTelegram()],
            'blocker' => Broadcasts::for($tenant)->blocker(),
            'maxLength' => (int) config('broadcasts.max_length'),
            'recent' => Broadcast::query()
                ->latest('id')
                ->limit(15)
                ->get()
                ->map(fn (Broadcast $b) => [
                    'id' => $b->id,
                    'message' => $b->message,
                    'audience' => Audience::SEGMENTS[$b->audience] ?? $b->audience,
                    'status' => $b->status,
                    'note' => $b->note,
                    'total' => $b->total,
                    'sent' => $b->sent,
                    'failed' => $b->failed,
                    'skipped' => $b->skipped,
                    'at' => $b->created_at?->toIso8601String(),
                ])
                ->all(),
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate(['audience' => ['required', Rule::in(array_keys(Audience::SEGMENTS))]]);

        return response()->json(Audience::for($request->user()->id)->summary($validated['audience']));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:'.(int) config('broadcasts.max_length')],
            'audience' => ['required', Rule::in(array_keys(Audience::SEGMENTS))],
        ]);

        $tenant = $request->user();
        $broadcasts = Broadcasts::for($tenant);

        if (($blocked = $broadcasts->blocker()) !== null) {
            return Redirect::back()->with('error', $blocked);
        }

        $broadcast = $broadcasts->queue($validated['message'], $validated['audience']);

        if ($broadcast === null) {
            return Redirect::back()->with('error', 'Nobody in that group can be reached right now.');
        }

        $reachable = $broadcast->total - $broadcast->skipped;

        return Redirect::back()->with('success', "Sending to {$reachable} ".str('customer')->plural($reachable).'. It goes out a few at a time.');
    }

    public function cancel(Request $request, Broadcast $broadcast): RedirectResponse
    {
        Broadcasts::for($request->user())->cancel($broadcast);

        return Redirect::back()->with('success', 'Broadcast cancelled.');
    }

    public function resume(Request $request, Broadcast $broadcast): RedirectResponse
    {
        Broadcasts::for($request->user())->resume($broadcast);

        return Redirect::back()->with('success', 'Broadcast resumed.');
    }
}
