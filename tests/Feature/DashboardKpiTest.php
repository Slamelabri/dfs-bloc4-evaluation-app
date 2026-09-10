<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesDemoData;
use Tests\TestCase;

/**
 * Tableau de bord Laravel (/) et fraicheur des indicateurs.
 */
class DashboardKpiTest extends TestCase
{
    use CreatesDemoData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareDemoData();

        $this->createTicket([
            'reference' => 'INC-DASH-1',
            'title' => 'Ticket ouvert existant',
            'priority' => 'critical',
            'status' => 'in_progress',
        ]);
    }

    private function lireKpis(): array
    {
        $reponse = $this->get('/');
        $reponse->assertStatus(200);

        return $reponse->viewData('kpis');
    }

    public function test_le_tableau_de_bord_repond(): void
    {
        $this->get('/')->assertStatus(200)->assertSee('INC-DASH-1');
    }

    public function test_les_kpis_de_depart_sont_coherents(): void
    {
        $kpis = $this->lireKpis();

        $this->assertSame(1, $kpis['openTickets']);
        $this->assertSame(1, $kpis['criticalTickets']);
    }

    public function test_le_compteur_de_tickets_ouverts_suit_la_base(): void
    {
        $avant = $this->lireKpis()['openTickets'];

        $this->createTicket([
            'reference' => 'INC-DASH-2',
            'title' => 'Nouveau ticket ouvert',
            'status' => 'new',
        ]);

        $apres = $this->lireKpis()['openTickets'];

        if ($apres === $avant) {
            $this->fail(
                "Le KPI est reste a {$avant} apres la creation d'un ticket : ".
                "le cache 'dashboard.kpis' n'est pas invalide."
            );
        }

        $this->assertSame($avant + 1, $apres);
    }

    public function test_un_ticket_resolu_sort_du_compteur_des_ouverts(): void
    {
        $ticket = $this->createTicket([
            'reference' => 'INC-DASH-3',
            'title' => 'Ticket a resoudre',
            'status' => 'new',
        ]);

        $avant = $this->lireKpis()['openTickets'];

        $ticket->update(['status' => 'resolved']);

        $apres = $this->lireKpis()['openTickets'];

        $this->assertSame($avant - 1, $apres);
    }

    public function test_vider_le_cache_remet_les_kpis_a_jour(): void
    {
        $avant = $this->lireKpis()['openTickets'];

        $this->createTicket([
            'reference' => 'INC-DASH-4',
            'title' => 'Ticket ouvert supplementaire',
            'status' => 'new',
        ]);

        Cache::forget('dashboard.kpis');

        $this->assertSame($avant + 1, $this->lireKpis()['openTickets']);
    }
}
