<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;

/** Журнал критичных изменений (audit_log). */
final class AuditLog
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    public function record(?int $actorId, string $entityType, int $entityId, string $action, ?array $before = null, ?array $after = null): void
    {
        $this->db->insert('audit_log', [
            'actor_user_id' => $actorId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'before_json' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'after_json' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            'created_at' => Clock::nowSql(),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function latest(int $limit = 200, ?string $entityType = null, ?int $entityId = null): array
    {
        $where = [];
        $params = [];
        if ($entityType !== null) {
            $where[] = 'a.entity_type = ?';
            $params[] = $entityType;
        }
        if ($entityId !== null) {
            $where[] = 'a.entity_id = ?';
            $params[] = $entityId;
        }
        $sql = 'SELECT a.*, u.full_name AS actor_name FROM audit_log a LEFT JOIN users u ON u.id = a.actor_user_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY a.id DESC LIMIT ' . max(1, min(1000, $limit));
        return $this->db->all($sql, $params);
    }
}
