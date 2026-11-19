<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWebhookJob;
use App\Models\WebhookEvent;
use App\Services\Pixelforge\WebhookSignature;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives Pixelforge webhooks.
 *
 * Verify the signature on the raw body, record the event under a unique id
 * (so a duplicate delivery is never processed twice), then hand the slow work
 * to a queued job and answer fast. The route is excluded from CSRF.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, WebhookSignature $signature): Response
    {
        $rawBody = $request->getContent();

        if (! $signature->verify($rawBody, $request->header('Pixelforge-Signature'))) {
            abort(400, 'Invalid signature');
        }

        $event = json_decode($rawBody, true);
        $eventId = $event['id'] ?? null;
        $type = $event['type'] ?? null;

        // A ping (dashboard test button) carries no generation: just acknowledge.
        if ($type === 'ping') {
            return response()->noContent();
        }

        if (! is_string($eventId)) {
            abort(400, 'Missing event id');
        }

        // The unique event_id column is the idempotency guard: a second delivery
        // of the same event fails this insert, so we ack it without reprocessing.
        try {
            WebhookEvent::create([
                'event_id' => $eventId,
                'type' => $type,
                'pixelforge_generation_id' => $event['data']['object']['id'] ?? null,
                'delivery_attempt' => $request->header('Pixelforge-Delivery-Attempt'),
                'payload' => $event,
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->noContent();
        }

        ProcessWebhookJob::dispatch($event);

        return response()->noContent();
    }
}
