<?php

namespace Tests\Unit;

use App\Support\WebhookStatusMapper;
use PHPUnit\Framework\TestCase;

/**
 * Test unitaire pur : ni base de donnees, ni HTTP.
 * Il decrit la regle metier "statut externe -> statut de ticket".
 */
class WebhookStatusMapperTest extends TestCase
{
    public function test_un_statut_planifie_donne_scheduled(): void
    {
        $this->assertSame('scheduled', WebhookStatusMapper::toTicketStatus('scheduled'));
        $this->assertSame('scheduled', WebhookStatusMapper::toTicketStatus('planned'));
    }

    public function test_un_statut_demarre_donne_in_progress(): void
    {
        $this->assertSame('in_progress', WebhookStatusMapper::toTicketStatus('in_progress'));
        $this->assertSame('in_progress', WebhookStatusMapper::toTicketStatus('started'));
    }

    public function test_un_statut_termine_donne_resolved(): void
    {
        $this->assertSame('resolved', WebhookStatusMapper::toTicketStatus('completed'));
        $this->assertSame('resolved', WebhookStatusMapper::toTicketStatus('done'));
    }

    public function test_un_statut_ferme_donne_closed(): void
    {
        $this->assertSame('closed', WebhookStatusMapper::toTicketStatus('closed'));
    }

    public function test_un_statut_annule_remet_le_ticket_a_new(): void
    {
        $this->assertSame('new', WebhookStatusMapper::toTicketStatus('cancelled'));
    }

    public function test_un_statut_inconnu_retombe_sur_new(): void
    {
        $this->assertSame('new', WebhookStatusMapper::toTicketStatus('n_importe_quoi'));
    }

    public function test_la_casse_et_les_espaces_sont_ignores(): void
    {
        $this->assertSame('resolved', WebhookStatusMapper::toTicketStatus('  COMPLETED '));
    }

    public function test_on_sait_reconnaitre_un_statut_connu(): void
    {
        $this->assertTrue(WebhookStatusMapper::isKnown('completed'));
        $this->assertFalse(WebhookStatusMapper::isKnown('n_importe_quoi'));
    }

    public function test_le_resultat_est_toujours_un_statut_valide(): void
    {
        $statutsValides = ['new', 'scheduled', 'in_progress', 'resolved', 'closed'];

        foreach (WebhookStatusMapper::KNOWN_STATUSES as $statutExterne) {
            $resultat = WebhookStatusMapper::toTicketStatus($statutExterne);

            $this->assertContains($resultat, $statutsValides, "Statut externe : {$statutExterne}");
        }
    }
}
