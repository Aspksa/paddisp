<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;

/** Уведомления внутри сайта. */
final class NotificationService
{
    public function __construct(private Database $db)
    {
    }

    public function notify(int $userId, string $eventType, string $title, string $body = '', ?string $link = null): void
    {
        $this->db->insert('notifications', [
            'user_id' => $userId,
            'event_type' => $eventType,
            'title' => $title,
            'body' => $body,
            'link' => $link,
            'created_at' => Clock::nowSql(),
        ]);
    }

    public function notifyRole(string $role, string $eventType, string $title, string $body = '', ?string $link = null): void
    {
        foreach ($this->db->all('SELECT id FROM users WHERE role = ? AND active = 1', [$role]) as $u) {
            $this->notify((int) $u['id'], $eventType, $title, $body, $link);
        }
    }

    public function unreadCount(int $userId): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }

    /** @return list<array<string,mixed>> */
    public function forUser(int $userId, int $limit = 100): array
    {
        return $this->db->all(
            'SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)),
            [$userId]
        );
    }

    /** Отмечает прочитанным только собственное уведомление; возвращает его ссылку. */
    public function markRead(int $userId, int $id): ?string
    {
        $this->db->run(
            'UPDATE notifications SET read_at = ? WHERE id = ? AND user_id = ? AND read_at IS NULL',
            [Clock::nowSql(), $id, $userId]
        );
        $link = $this->db->value('SELECT link FROM notifications WHERE id = ? AND user_id = ?', [$id, $userId]);
        return $link === null ? null : (string) $link;
    }

    public function markAllRead(int $userId): void
    {
        $this->db->run('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [Clock::nowSql(), $userId]);
    }
}
