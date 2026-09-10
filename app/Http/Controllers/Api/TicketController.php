<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTicketRequest;
use App\Http\Requests\UpdateTicketRequest;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Services\EventLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TicketController extends Controller
{
    public function __construct(private readonly EventLogService $eventLogService)
    {
    }

    public function index(Request $request)
    {
        $query = Ticket::query()->with(['site', 'openedBy', 'assignedTo', 'interventions']);

        if ($search = $request->string('search')->toString()) {
            // Le OR est groupe dans une sous-requete : sans les parentheses,
            // AND est prioritaire sur OR en SQL et le filtre de priorite
            // ci-dessous ne s'applique qu'a une branche du OR.
            // Les deux comparaisons passent par des requetes preparees :
            // plus aucune concatenation du terme de recherche dans le SQL.
            $query->where(function ($sousRequete) use ($search) {
                $sousRequete->where('title', 'like', '%'.$search.'%')
                    ->orWhere('reference', 'like', '%'.$search.'%');
            });
        }

        if ($priority = $request->string('priority')->toString()) {
            $query->where('priority', $priority);
        }

        $tickets = $query->latest()->paginate($request->integer('per_page', 15));

        $this->eventLogService->record('api', 'tickets.index', [
            'filters' => $request->query(),
        ]);

        return TicketResource::collection($tickets);
    }

    public function store(StoreTicketRequest $request)
    {
        $ticket = Ticket::query()->create([
            ...$request->validated(),
            'reference' => 'INC-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
            'status' => $request->validated('status', 'new'),
        ]);

        $ticket->load(['site', 'openedBy', 'assignedTo', 'interventions']);

        $this->eventLogService->record('api', 'ticket.created', [
            'ticket_id' => $ticket->id,
            'reference' => $ticket->reference,
        ]);

        return (new TicketResource($ticket))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Ticket $ticket)
    {
        $ticket->load(['site', 'openedBy', 'assignedTo', 'interventions']);

        return new TicketResource($ticket);
    }

    public function update(UpdateTicketRequest $request, Ticket $ticket)
    {
        $ticket->fill($request->validated());

        if ($ticket->isDirty('status') && in_array($ticket->status, ['resolved', 'closed'], true)) {
            $ticket->closed_at = now();
        }

        $ticket->save();
        $changes = $ticket->getChanges();
        $ticket->load(['site', 'openedBy', 'assignedTo', 'interventions']);

        $this->eventLogService->record('api', 'ticket.updated', [
            'ticket_id' => $ticket->id,
            'changes' => $changes,
            'request_id' => (string) Str::uuid(),
        ]);

        return new TicketResource($ticket);
    }
}
