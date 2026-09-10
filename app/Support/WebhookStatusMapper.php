<?php

namespace App\Support;

/**
 * Traduit le statut envoye par le systeme externe (webhook) en statut de ticket.
 *
 * Statuts de ticket autorises : new, scheduled, in_progress, resolved, closed.
 */
class WebhookStatusMapper
{
    /** @var array<int, string> */
    public const KNOWN_STATUSES = [
        'scheduled', 'planned',
        'in_progress', 'started',
        'completed', 'done',
        'closed',
        'cancelled', 'canceled',
    ];

    public static function toTicketStatus(string $externalStatus): string
    {
        $statut = strtolower(trim($externalStatus));

        switch ($statut) {
            case 'scheduled':
            case 'planned':
                return 'scheduled';

            case 'in_progress':
            case 'started':
                return 'in_progress';

            case 'completed':
            case 'done':
                return 'resolved';

            case 'closed':
                return 'closed';

            case 'cancelled':
            case 'canceled':
                return 'new';

            default:
                // Statut inconnu : on ne casse pas le flux entrant.
                return 'new';
        }
    }

    public static function isKnown(string $externalStatus): bool
    {
        if (in_array(strtolower(trim($externalStatus)), self::KNOWN_STATUSES, true)) {
            return true;
        }

        return false;
    }
}
