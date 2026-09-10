<?php

namespace Tests\Concerns;

use App\Models\ApiToken;
use App\Models\Customer;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;

trait CreatesDemoData
{
    protected string $apiToken = 'token-de-test-0000';

    protected string $apiTokenLectureSeule = 'token-lecture-seule-0000';

    protected ?Site $site = null;

    protected ?User $supervisor = null;

    protected int $compteurReference = 0;

    protected function prepareDemoData(): void
    {
        ApiToken::query()->create([
            'name' => 'jeu-de-test',
            'token' => $this->apiToken,
            'abilities' => ['tickets:read', 'tickets:write'],
            'is_active' => true,
        ]);

        ApiToken::query()->create([
            'name' => 'jeu-de-test-lecture',
            'token' => $this->apiTokenLectureSeule,
            'abilities' => ['tickets:read'],
            'is_active' => true,
        ]);

        $this->supervisor = User::query()->create([
            'name' => 'Nora Test',
            'email' => 'nora.test@opstrack.test',
            'role' => 'supervisor',
            'phone' => '06 00 00 00 00',
            'password' => 'password',
        ]);

        $customer = Customer::query()->create([
            'name' => 'Client de test',
            'account_code' => 'TEST-001',
            'industry' => 'Retail',
            'contact_name' => 'Camille Test',
            'contact_email' => 'camille.test@opstrack.test',
            'contact_phone' => '04 00 00 00 00',
            'active' => true,
        ]);

        $this->site = Site::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Site de test',
            'address' => '1 rue du Test',
            'postal_code' => '69002',
            'city' => 'Lyon',
            'latitude' => 45.7433170,
            'longitude' => 4.8157470,
            'timezone' => 'Europe/Paris',
        ]);
    }

    protected function createTicket(array $donnees = []): Ticket
    {
        $this->compteurReference++;

        $valeursParDefaut = [
            'site_id' => $this->site->id,
            'opened_by_user_id' => $this->supervisor->id,
            'assigned_to_user_id' => null,
            'reference' => 'INC-TEST-'.$this->compteurReference,
            'title' => 'Ticket de test',
            'description' => 'Description de test.',
            'priority' => 'medium',
            'status' => 'new',
        ];

        return Ticket::query()->create(array_merge($valeursParDefaut, $donnees));
    }
}
