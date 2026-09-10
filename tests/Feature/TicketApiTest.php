<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesDemoData;
use Tests\TestCase;

/**
 * API /api/v1/tickets : securite du token, contrat JSON, recherche et filtres.
 */
class TicketApiTest extends TestCase
{
    use CreatesDemoData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareDemoData();

        $this->createTicket([
            'reference' => 'INC-000001',
            'title' => 'Panne terminal de paiement',
            'priority' => 'critical',
            'status' => 'in_progress',
        ]);

        $this->createTicket([
            'reference' => 'INC-000002',
            'title' => 'Maintenance climatisation',
            'priority' => 'medium',
            'status' => 'scheduled',
        ]);
    }

    private function appelApi(string $url, ?string $token = null)
    {
        if ($token === null) {
            $token = $this->apiToken;
        }

        return $this->withHeader('Authorization', 'Bearer '.$token)->getJson($url);
    }

    // --- Authentification -------------------------------------------------

    public function test_l_api_refuse_une_requete_sans_token(): void
    {
        $this->getJson('/api/v1/tickets')->assertStatus(401);
    }

    public function test_l_api_refuse_un_token_inconnu(): void
    {
        $this->appelApi('/api/v1/tickets', 'token-bidon')->assertStatus(401);
    }

    public function test_l_api_accepte_un_token_valide(): void
    {
        $this->appelApi('/api/v1/tickets')->assertStatus(200);
    }

    // --- Habilitations du token -------------------------------------------

    public function test_un_token_en_lecture_peut_lire(): void
    {
        $this->appelApi('/api/v1/tickets', $this->apiTokenLectureSeule)->assertStatus(200);
    }

    public function test_un_token_en_lecture_ne_peut_pas_creer(): void
    {
        $reponse = $this->withHeader('Authorization', 'Bearer '.$this->apiTokenLectureSeule)
            ->postJson('/api/v1/tickets', [
                'site_id' => $this->site->id,
                'opened_by_user_id' => $this->supervisor->id,
                'title' => 'Tentative avec un token en lecture seule',
                'description' => 'Ne doit pas aboutir.',
                'priority' => 'low',
            ]);

        $reponse->assertStatus(403);
    }

    // --- Contrat JSON attendu par le microservice Next.js ------------------

    public function test_la_reponse_expose_la_cle_data_et_pas_items(): void
    {
        $corps = $this->appelApi('/api/v1/tickets')->json();

        $this->assertArrayHasKey('data', $corps);
        $this->assertArrayNotHasKey('items', $corps);
    }

    public function test_un_ticket_renvoie_les_champs_attendus(): void
    {
        $this->appelApi('/api/v1/tickets')->assertJsonStructure([
            'data' => [
                ['id', 'reference', 'title', 'priority', 'status', 'site', 'interventions'],
            ],
        ]);
    }

    // --- Recherche et filtres ---------------------------------------------

    public function test_la_recherche_seule_trouve_le_bon_ticket(): void
    {
        $this->appelApi('/api/v1/tickets?search=Maintenance')
            ->assertStatus(200)
            ->assertJsonPath('data.0.reference', 'INC-000002');
    }

    public function test_la_recherche_trouve_aussi_par_reference(): void
    {
        $this->appelApi('/api/v1/tickets?search=INC-000001')
            ->assertStatus(200)
            ->assertJsonPath('data.0.reference', 'INC-000001');
    }

    public function test_la_recherche_respecte_le_filtre_de_priorite(): void
    {
        // "Maintenance" n'existe que sur un ticket de priorite "medium".
        $reponse = $this->appelApi('/api/v1/tickets?search=Maintenance&priority=critical');
        $reponse->assertStatus(200);

        $nombre = count($reponse->json('data'));

        if ($nombre > 0) {
            $this->fail(
                "La recherche renvoie {$nombre} ticket(s) alors que le filtre priority=critical ".
                'devrait tout exclure (OR non groupe).'
            );
        }

        $this->assertSame(0, $nombre);
    }

    // --- Robustesse et injection SQL --------------------------------------

    public function test_une_apostrophe_dans_la_recherche_ne_casse_pas_l_api(): void
    {
        $reponse = $this->appelApi('/api/v1/tickets?search='.urlencode("O'Brien"));
        $code = $reponse->getStatusCode();

        if ($code >= 500) {
            $this->fail("L'API renvoie {$code} sur une apostrophe : le terme est concatene dans le SQL.");
        }

        $this->assertSame(200, $code);
    }

    public function test_une_injection_sql_ne_renvoie_pas_tous_les_tickets(): void
    {
        $reponse = $this->appelApi('/api/v1/tickets?search='.urlencode("%' OR '1'='1"));
        $code = $reponse->getStatusCode();

        if ($code >= 500) {
            $this->fail("L'API renvoie {$code} : la chaine d'injection atteint le moteur SQL.");
        }

        $nombre = count($reponse->json('data'));

        if ($nombre > 0) {
            $this->fail("L'injection SQL a fait remonter {$nombre} ticket(s).");
        }

        $this->assertSame(0, $nombre);
    }

    // --- Creation ----------------------------------------------------------

    public function test_la_creation_d_un_ticket_renvoie_201(): void
    {
        $reponse = $this->withHeader('Authorization', 'Bearer '.$this->apiToken)
            ->postJson('/api/v1/tickets', [
                'site_id' => $this->site->id,
                'opened_by_user_id' => $this->supervisor->id,
                'title' => 'Nouveau ticket via API',
                'description' => 'Cree par le test automatise.',
                'priority' => 'high',
            ]);

        $reponse->assertStatus(201);

        $this->assertDatabaseHas('tickets', [
            'title' => 'Nouveau ticket via API',
            'priority' => 'high',
            'status' => 'new',
        ]);
    }

    public function test_la_creation_refuse_une_priorite_invalide(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->apiToken)
            ->postJson('/api/v1/tickets', [
                'site_id' => $this->site->id,
                'opened_by_user_id' => $this->supervisor->id,
                'title' => 'Ticket invalide',
                'description' => 'Priorite inexistante.',
                'priority' => 'urgentissime',
            ])
            ->assertStatus(422);
    }

    public function test_deux_creations_ne_produisent_pas_la_meme_reference(): void
    {
        $donnees = [
            'site_id' => $this->site->id,
            'opened_by_user_id' => $this->supervisor->id,
            'title' => 'Ticket de collision',
            'description' => 'Verifie l unicite des references generees.',
            'priority' => 'low',
        ];

        $premiere = $this->withHeader('Authorization', 'Bearer '.$this->apiToken)
            ->postJson('/api/v1/tickets', $donnees)->json('data.reference');

        $seconde = $this->withHeader('Authorization', 'Bearer '.$this->apiToken)
            ->postJson('/api/v1/tickets', $donnees)->json('data.reference');

        $this->assertNotSame($premiere, $seconde);
    }
}
