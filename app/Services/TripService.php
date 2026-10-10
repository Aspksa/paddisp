<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;
use App\Core\DomainError;

/** Кабинет водителя: только собственные назначения. */
final class TripService
{
    public const STATUS_LABELS = [
        'assigned' => 'Назначен',
        'acknowledged' => 'Получение подтверждено',
        'in_progress' => 'В пути',
        'finished' => 'Завершён',
        'cancelled' => 'Отменён',
    ];

    public function __construct(
        private Database $db,
        private NotificationService $notifications,
        private AuditLog $audit,
    ) {
    }

    public function driverIdForUser(int $userId): ?int
    {
        $id = $this->db->value('SELECT id FROM drivers WHERE user_id = ? AND active = 1', [$userId]);
        return $id === null ? null : (int) $id;
    }

    /** @return list<array<string,mixed>> */
    public function assignmentsForUser(int $userId, bool $history = false): array
    {
        $driverId = $this->driverIdForUser($userId);
        if ($driverId === null) {
            return [];
        }
        $statuses = $history ? "('finished','cancelled')" : "('assigned','acknowledged','in_progress')";
        return $this->db->all(
            "SELECT a.*, r.pickup_location, r.destination, r.purpose, r.passenger_count, r.comment, r.contact_phone,
                    u.full_name AS requester_name, u.phone AS requester_phone, v.brand, v.model, v.registration_number
             FROM assignments a
             JOIN requests r ON r.id = a.request_id
             JOIN users u ON u.id = r.requester_id
             JOIN vehicles v ON v.id = a.vehicle_id
             WHERE a.driver_id = ? AND a.status IN $statuses
             ORDER BY a.starts_at " . ($history ? 'DESC LIMIT 100' : 'ASC'),
            [$driverId]
        );
    }

    public function acknowledge(int $userId, int $assignmentId): void
    {
        $this->transition($userId, $assignmentId, ['assigned'], 'acknowledged', 'acknowledged_at', 'acknowledge');
    }

    public function start(int $userId, int $assignmentId): void
    {
        $this->transition($userId, $assignmentId, ['assigned', 'acknowledged'], 'in_progress', 'trip_started_at', 'start_trip');
    }

    /** Завершение рейса; заявка переходит в «Выполнена» (действие системы). */
    public function finish(int $userId, int $assignmentId): void
    {
        $this->db->transaction(function () use ($userId, $assignmentId) {
            $a = $this->transition($userId, $assignmentId, ['in_progress'], 'finished', 'trip_finished_at', 'finish_trip');
            $requestId = (int) $a['request_id'];
            $request = $this->db->one('SELECT * FROM requests WHERE id = ? FOR UPDATE', [$requestId]);
            if ($request !== null && $request['status'] === 'approved') {
                $this->db->update('requests', $requestId, ['status' => 'completed', 'updated_at' => Clock::nowSql()]);
                $this->audit->record(null, 'request', $requestId, 'complete', ['status' => 'approved'], ['status' => 'completed']);
                $this->notifications->notify((int) $request['requester_id'], 'trip_finished', "Поездка по заявке №$requestId завершена", '', "/requests/$requestId");
            }
        });
    }

    /**
     * @param list<string> $from
     * @return array<string,mixed>
     */
    private function transition(int $userId, int $assignmentId, array $from, string $to, string $timestampField, string $action): array
    {
        return $this->db->transaction(function () use ($userId, $assignmentId, $from, $to, $timestampField, $action) {
            $driverId = $this->driverIdForUser($userId);
            $a = $this->db->one('SELECT * FROM assignments WHERE id = ? FOR UPDATE', [$assignmentId]);
            // Чужое назначение выглядит так же, как несуществующее.
            if ($a === null || $driverId === null || (int) $a['driver_id'] !== $driverId) {
                throw new DomainError('Назначение не найдено.');
            }
            if (!in_array($a['status'], $from, true)) {
                throw new DomainError('Действие недоступно для рейса в статусе «' . self::STATUS_LABELS[$a['status']] . '».');
            }
            $now = Clock::nowSql();
            $data = ['status' => $to, $timestampField => $now];
            if ($to === 'in_progress' && $a['acknowledged_at'] === null) {
                $data['acknowledged_at'] = $now;
            }
            $this->db->update('assignments', $assignmentId, $data);
            $this->audit->record($userId, 'assignment', $assignmentId, $action, ['status' => $a['status']], ['status' => $to]);
            return $a;
        });
    }
}
