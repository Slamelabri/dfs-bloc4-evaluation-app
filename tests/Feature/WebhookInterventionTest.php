<?php

namespace Tests\Feature;

use App\Models\Intervention;
use App\Support\WebhookStatusMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesDemoData;
use Tests\TestCase;

/**
 * Webhook entrant : public/hooks.php relaie vers la route
 * POST /webhooks/interventions, ce qui donne acces au kernel HTTP.
 */
class WebhookInterventionTest extends TestCase
{
    use CreatesDemoData;
    use RefreshDatabase;

    private const URL = '/api/webhooks/interventions';

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareDemoData();
    }

    private function appelWebhook(array $payload, bool $avecAuth = true)
    {
        $entetes = ['Accept' => 'application/json'];

        if ($avecAuth === true) {
            $identifiants = config('services.webhook.basic_user').':'.config('services.webhook.basic_password');
            $entetes['Authorization'] = 'Basic '.base64_encode($identifiants);
        }

        return $this->withHeaders($entetes)->postJson(self::URL, $payload);
    }

    // --- Authentification -------------------------------------------------

    public function test_le_webhook_refuse_un_appel_sans_authentification(): void
    {
        $this->createTicket(['reference' => 'INC-WH-AUTH']);

        $this->appelWebhook([
            'ticket_reference' => 'INC-WH-AUTH',
            'status' => 'completed',
        ], false)->assertStatus(401);
    }

    public function test_le_webhook_refuse_de_mauvais_identifiants(): void
    {
        $this->createTicket(['reference' => 'INC-WH-BAD']);

        $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Basic '.base64_encode('pirate:pirate'),
        ])->postJson(self::URL, [
            'ticket_reference' => 'INC-WH-BAD',
            'status' => 'completed',
        ])->assertStatus(401);
    }

    public function test_le_webhook_accepte_un_appel_authentifie(): void
    {
        $this->createTicket(['reference' => 'INC-WH-OK']);

        $this->appelWebhook([
            'ticket_reference' => 'INC-WH-OK',
            'status' => 'scheduled',
            'external_event_id' => 'evt-ok-1',
        ])->assertStatus(200);
    }

    // --- Creation et deduplication ----------------------------------------

    public function test_le_webhook_cree_une_intervention(): void
    {
        $ticket = $this->createTicket(['reference' => 'INC-WH-1']);

        $this->appelWebhook([
            'ticket_reference' => 'INC-WH-1',
            'status' => 'scheduled',
            'summary' => 'Creneau confirme.',
            'external_event_id' => 'evt-1',
        ]);

        $this->assertSame(1, Intervention::query()->where('ticket_id', $ticket->id)->count());
    }

    public function test_le_meme_evenement_externe_ne_cree_qu_une_intervention(): void
    {
        $this->createTicket(['reference' => 'INC-WH-2']);

        $payload = [
            'ticket_reference' => 'INC-WH-2',
            'status' => 'scheduled',
            'summary' => 'Creneau confirme.',
            'external_event_id' => 'evt-doublon',
        ];

        // Le systeme externe rejoue regulierement ses evenements.
        $this->appelWebhook($payload)->assertStatus(200);
        $this->appelWebhook($payload)->assertStatus(200);

        $nombre = Intervention::query()->where('external_event_id', 'evt-doublon')->count();

        if ($nombre > 1) {
            $this->fail("Le webhook a cree {$nombre} interventions pour le meme external_event_id.");
        }

        $this->assertSame(1, $nombre);
    }

    // --- Coherence du statut du ticket ------------------------------------

    private function verifierStatutTicket(string $statutWebhook): void
    {
        $reference = 'INC-WH-'.strtoupper($statutWebhook);

        $ticket = $this->createTicket(['reference' => $reference, 'status' => 'new']);

        $this->appelWebhook([
            'ticket_reference' => $reference,
            'status' => $statutWebhook,
            'external_event_id' => 'evt-'.$statutWebhook,
        ])->assertStatus(200);

        $attendu = WebhookStatusMapper::toTicketStatus($statutWebhook);
        $obtenu = $ticket->fresh()->status;

        if ($obtenu !== $attendu) {
            $this->fail(
                "Statut webhook '{$statutWebhook}' : le ticket devrait passer a '{$attendu}' ".
                "mais vaut '{$obtenu}'."
            );
        }

        $this->assertSame($attendu, $obtenu);
    }

    public function test_un_webhook_scheduled_planifie_le_ticket(): void
    {
        $this->verifierStatutTicket('scheduled');
    }

    public function test_un_webhook_in_progress_passe_le_ticket_en_cours(): void
    {
        $this->verifierStatutTicket('in_progress');
    }

    public function test_un_webhook_completed_resout_le_ticket(): void
    {
        $this->verifierStatutTicket('completed');
    }

    // --- Cas d'erreur ------------------------------------------------------

    public function test_une_reference_inconnue_renvoie_404(): void
    {
        $this->appelWebhook([
            'ticket_reference' => 'INC-INEXISTANT',
            'status' => 'completed',
        ])->assertStatus(404);
    }

    public function test_un_payload_incomplet_renvoie_422(): void
    {
        $this->appelWebhook(['status' => 'completed'])->assertStatus(422);
    }
}
