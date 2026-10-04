<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Database;

/** Records security-relevant admin actions (role changes, deactivation, deletions). */
final class AuditLogger
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, scalar|null> $meta */
    public function log(?int $actorId, string $action, ?string $subjectType = null, ?int $subjectId = null, array $meta = []): void
    {
        $this->db->insert('audit_logs', [
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'meta' => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
            'ip_address' => App::instance()->request()?->ip(),
        ]);
    }
}
