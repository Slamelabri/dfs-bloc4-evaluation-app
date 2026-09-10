<?php

namespace App\Http\Controllers;

use App\Models\Intervention;
use App\Models\Ticket;
use App\Services\EventLogService;
use App\Support\WebhookStatusMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __construct(private readonly EventLogService $eventLogService)
    {
    }

    public function handle(Request $request): JsonResponse
    {
        if (! $this->authentificationValide($request)) {
            $this->eventLogService->record('webhook', 'auth.rejected', [
                'ip' => $request->ip(),
            ], 'warning');

            return response()->json(['message' => 'Unauthorized'], 401, [
                'WWW-Authenticate' => 'Basic realm="OpsTrack Webhook"',
            ]);
        }

        $payload = $request->validate([
            'ticket_reference' => ['required', 'string', 'max:64'],
            'status' => ['required', 'string', 'max:32'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'external_event_id' => ['nullable', 'string', 'max:128'],
        ]);

        $externalEventId = $payload['external_event_id'] ?? null;

        // Deduplication : le systeme externe rejoue regulierement ses evenements.
        if ($externalEventId !== null) {
            $dejaTraitee = Intervention::query()
                ->where('external_event_id', $externalEventId)
                ->first();

            if ($dejaTraitee !== null) {
                $this->eventLogService->record('webhook', 'intervention.duplicate', [
                    'external_event_id' => $externalEventId,
                    'intervention_id' => $dejaTraitee->id,
                ]);

                return response()->json([
                    'message' => 'Webhook already processed.',
                    'intervention_id' => $dejaTraitee->id,
                ]);
            }
        }

        $ticket = Ticket::query()
            ->where('reference', $payload['ticket_reference'])
            ->first();

        if ($ticket === null) {
            return response()->json([
                'message' => 'Unknown ticket reference.',
            ], 404);
        }

        $intervention = Intervention::query()->create([
            'ticket_id' => $ticket->id,
            'scheduled_for' => now()->addHour(),
            'status' => $payload['status'],
            'summary' => $payload['summary'] ?? 'Webhook update received.',
            'external_event_id' => $externalEventId,
        ]);

        // Le statut du ticket suit reellement celui annonce par le systeme externe.
        $ticket->update([
            'status' => WebhookStatusMapper::toTicketStatus($payload['status']),
        ]);

        $this->eventLogService->record('webhook', 'intervention.synced', [
            'ticket_id' => $ticket->id,
            'intervention_id' => $intervention->id,
            'ticket_status' => $ticket->status,
            'payload' => $payload,
        ]);

        return response()->json([
            'message' => 'Webhook processed.',
            'intervention_id' => $intervention->id,
            'ticket_status' => $ticket->status,
        ]);
    }

    /**
     * Comparaison a temps constant : evite de reveler les identifiants
     * par mesure du temps de reponse.
     */
    private function authentificationValide(Request $request): bool
    {
        $utilisateurAttendu = (string) config('services.webhook.basic_user');
        $motDePasseAttendu = (string) config('services.webhook.basic_password');

        $utilisateurRecu = (string) $request->getUser();
        $motDePasseRecu = (string) $request->getPassword();

        if (! hash_equals($utilisateurAttendu, $utilisateurRecu)) {
            return false;
        }

        if (! hash_equals($motDePasseAttendu, $motDePasseRecu)) {
            return false;
        }

        return true;
    }
}
