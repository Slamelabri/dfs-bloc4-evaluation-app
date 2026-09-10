<?php

namespace Database\Seeders;

use App\Models\ApiToken;
use App\Models\Customer;
use App\Models\Intervention;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $supervisor = User::query()->create([
            'name' => 'Nora Besson',
            'email' => 'nora.besson@opstrack.test',
            'role' => 'supervisor',
            'phone' => '06 10 22 34 45',
            'password' => Hash::make('password'),
        ]);

        $tech1 = User::query()->create([
            'name' => 'Lina Perez',
            'email' => 'lina.perez@opstrack.test',
            'role' => 'technician',
            'phone' => '06 22 33 44 55',
            'password' => Hash::make('password'),
        ]);

        $tech2 = User::query()->create([
            'name' => 'Mathis Leroy',
            'email' => 'mathis.leroy@opstrack.test',
            'role' => 'technician',
            'phone' => '06 99 88 77 66',
            'password' => Hash::make('password'),
        ]);

        $customer = Customer::query()->create([
            'name' => 'Alto Facilities',
            'account_code' => 'ALT-001',
            'industry' => 'Retail',
            'contact_name' => 'Camille Roy',
            'contact_email' => 'camille.roy@alto-facilities.test',
            'contact_phone' => '04 72 11 22 33',
            'active' => true,
        ]);

        $site = Site::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Lyon Confluence',
            'address' => '12 quai Perrache',
            'postal_code' => '69002',
            'city' => 'Lyon',
            'latitude' => 45.7433170,
            'longitude' => 4.8157470,
            'timezone' => 'Europe/Paris',
        ]);

        $ticket1 = Ticket::query()->create([
            'site_id' => $site->id,
            'opened_by_user_id' => $supervisor->id,
            'assigned_to_user_id' => $tech1->id,
            'reference' => 'INC-240301',
            'title' => 'Intermittent payment terminal outage',
            'description' => 'Three terminals restart during peak hours and the store asks for a fast onsite intervention.',
            'priority' => 'critical',
            'status' => 'in_progress',
            'sla_due_at' => now()->addHours(4),
        ]);

        $ticket2 = Ticket::query()->create([
            'site_id' => $site->id,
            'opened_by_user_id' => $supervisor->id,
            'assigned_to_user_id' => $tech2->id,
            'reference' => 'INC-240302',
            'title' => 'Cooling unit maintenance overdue',
            'description' => 'Preventive maintenance has not been recorded on the dispatch board.',
            'priority' => 'medium',
            'status' => 'scheduled',
            'sla_due_at' => now()->addDay(),
        ]);

        Intervention::query()->create([
            'ticket_id' => $ticket1->id,
            'scheduled_for' => now()->subHour(),
            'started_at' => now()->subMinutes(40),
            'status' => 'in_progress',
            'summary' => 'Technician is replacing the failing network switch.',
            'external_event_id' => 'evt-ops-001',
        ]);

        Intervention::query()->create([
            'ticket_id' => $ticket2->id,
            'scheduled_for' => now()->addHours(3),
            'status' => 'scheduled',
            'summary' => 'Preventive maintenance slot approved by the customer.',
            'external_event_id' => 'evt-ops-002',
        ]);

        ApiToken::query()->create([
            'name' => 'dispatch-dashboard',
            'token' => config('services.opstrack.api_token'),
            'abilities' => ['tickets:read', 'tickets:write', 'weather:read'],
            'is_active' => true,
        ]);
    }
}
