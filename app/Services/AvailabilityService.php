<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\DomainError;

/**
 * Проверка занятости автомобилей и водителей.
 *
 * Интервалы полуоткрытые: [starts_at, ends_at). Поездка 10:00–12:00 не конфликтует с 12:00–14:00.
 */
final class AvailabilityService
{
    /** Статусы назначений, которые занимают автомобиль и водителя. */
    public const ACTIVE_ASSIGNMENT = ['assigned', 'acknowledged', 'in_progress'];

    public function __construct(private Database $db)
    {
    }

    private static function activeIn(): string
    {
        return "'" . implode("','", self::ACTIVE_ASSIGNMENT) . "'";
    }

    /** @return list<array<string,mixed>> */
    public function vehicleConflicts(int $vehicleId, string $start, string $end, int $excludeRequestId = 0): array
    {
        return $this->db->all(
            'SELECT a.id, a.request_id, a.starts_at, a.ends_at FROM assignments a
             WHERE a.vehicle_id = ? AND a.status IN (' . self::activeIn() . ')
               AND a.starts_at < ? AND a.ends_at > ? AND a.request_id <> ?
             ORDER BY a.starts_at',
            [$vehicleId, $end, $start, $excludeRequestId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function driverConflicts(int $driverId, string $start, string $end, int $excludeRequestId = 0): array
    {
        return $this->db->all(
            'SELECT a.id, a.request_id, a.starts_at, a.ends_at FROM assignments a
             WHERE a.driver_id = ? AND a.status IN (' . self::activeIn() . ')
               AND a.starts_at < ? AND a.ends_at > ? AND a.request_id <> ?
             ORDER BY a.starts_at',
            [$driverId, $end, $start, $excludeRequestId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function maintenanceOverlaps(int $vehicleId, string $start, string $end): array
    {
        return $this->db->all(
            'SELECT * FROM maintenance_periods WHERE vehicle_id = ? AND starts_at < ? AND ends_at > ? ORDER BY starts_at',
            [$vehicleId, $end, $start]
        );
    }

    /**
     * Причины, по которым автомобиль нельзя назначить на интервал.
     *
     * @param array<string,mixed> $vehicle
     * @return list<string>
     */
    public function vehicleProblems(array $vehicle, string $start, string $end, int $excludeRequestId = 0, ?int $passengers = null): array
    {
        $problems = [];
        if (!(int) $vehicle['active'] || $vehicle['status'] === 'decommissioned') {
            $problems[] = 'автомобиль выведен из эксплуатации';
        } elseif ($vehicle['status'] === 'repair') {
            $problems[] = 'автомобиль в ремонте';
        }
        foreach ($this->maintenanceOverlaps((int) $vehicle['id'], $start, $end) as $m) {
            $problems[] = 'плановый ремонт ' . fmt_range($m['starts_at'], $m['ends_at']);
        }
        foreach ($this->vehicleConflicts((int) $vehicle['id'], $start, $end, $excludeRequestId) as $c) {
            $problems[] = 'занят по заявке №' . $c['request_id'] . ' (' . fmt_range($c['starts_at'], $c['ends_at']) . ')';
        }
        if ($passengers !== null && $passengers > (int) $vehicle['seat_count']) {
            $problems[] = 'недостаточно мест (' . $vehicle['seat_count'] . ')';
        }
        return $problems;
    }

    /**
     * @param array<string,mixed> $driver строка drivers + users.active AS user_active
     * @return list<string>
     */
    public function driverProblems(array $driver, string $start, string $end, int $excludeRequestId = 0): array
    {
        $problems = [];
        if (!(int) $driver['active'] || !(int) $driver['user_active']) {
            $problems[] = 'водитель неактивен';
        }
        foreach ($this->driverConflicts((int) $driver['id'], $start, $end, $excludeRequestId) as $c) {
            $problems[] = 'занят по заявке №' . $c['request_id'] . ' (' . fmt_range($c['starts_at'], $c['ends_at']) . ')';
        }
        return $problems;
    }

    /**
     * Блокирует строки автомобиля и водителя (SELECT ... FOR UPDATE) и заново проверяет занятость.
     * Вызывается только внутри транзакции: параллельное подтверждение на тот же автомобиль
     * или водителя ждёт освобождения блокировки и после этого видит уже созданное назначение.
     */
    public function lockAndAssert(int $vehicleId, int $driverId, string $start, string $end, int $excludeRequestId, ?int $passengers): void
    {
        if (!$this->db->pdo->inTransaction()) {
            throw new \LogicException('lockAndAssert() must run inside a transaction');
        }
        // Порядок блокировок всегда одинаковый (автомобиль, затем водитель) — без взаимных блокировок.
        $vehicle = $this->db->one('SELECT * FROM vehicles WHERE id = ? FOR UPDATE', [$vehicleId]);
        $driver = $this->db->one(
            'SELECT d.*, u.active AS user_active, u.full_name FROM drivers d JOIN users u ON u.id = d.user_id WHERE d.id = ? FOR UPDATE',
            [$driverId]
        );
        if ($vehicle === null) {
            throw new DomainError('Автомобиль не найден.');
        }
        if ($driver === null) {
            throw new DomainError('Водитель не найден.');
        }
        $errors = [];
        foreach ($this->vehicleProblems($vehicle, $start, $end, $excludeRequestId, $passengers) as $p) {
            $errors[] = 'Автомобиль ' . $vehicle['registration_number'] . ': ' . $p . '.';
        }
        foreach ($this->driverProblems($driver, $start, $end, $excludeRequestId) as $p) {
            $errors[] = 'Водитель ' . $driver['full_name'] . ': ' . $p . '.';
        }
        if ($errors) {
            throw new DomainError(implode(' ', $errors));
        }
    }

    /** @return list<array<string,mixed>> автомобили с полем problems */
    public function vehicleOptions(string $start, string $end, int $excludeRequestId = 0, ?int $passengers = null): array
    {
        $rows = $this->db->all("SELECT * FROM vehicles WHERE active = 1 AND status <> 'decommissioned' ORDER BY brand, model, registration_number");
        foreach ($rows as &$v) {
            $v['problems'] = $this->vehicleProblems($v, $start, $end, $excludeRequestId, $passengers);
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> водители с полем problems */
    public function driverOptions(string $start, string $end, int $excludeRequestId = 0): array
    {
        $rows = $this->db->all(
            'SELECT d.*, u.full_name, u.phone, u.active AS user_active FROM drivers d JOIN users u ON u.id = d.user_id
             WHERE d.active = 1 AND u.active = 1 ORDER BY u.full_name'
        );
        foreach ($rows as &$d) {
            $d['problems'] = $this->driverProblems($d, $start, $end, $excludeRequestId);
        }
        return $rows;
    }
}
