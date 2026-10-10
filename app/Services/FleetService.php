<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;
use App\Core\DomainError;
use App\Core\ValidationException;
use DateTimeImmutable;

/** Автопарк: текущее состояние машин, расписание на день, ремонты. */
final class FleetService
{
    public const STATE_LABELS = [
        'free' => 'Свободен',
        'busy' => 'Занят',
        'repair' => 'В ремонте',
        'decommissioned' => 'Выведен из эксплуатации',
    ];

    public const STATUS_LABELS = [
        'in_service' => 'В эксплуатации',
        'repair' => 'В ремонте',
        'decommissioned' => 'Выведен из эксплуатации',
    ];

    public function __construct(private Database $db, private AuditLog $audit)
    {
    }

    /**
     * Все автомобили с текущим состоянием и занятостью на выбранный день.
     *
     * @return list<array<string,mixed>>
     */
    public function overview(string $date): array
    {
        $dayStart = $date . ' 00:00:00';
        $dayEnd = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        $now = Clock::nowSql();

        $vehicles = $this->db->all('SELECT * FROM vehicles WHERE active = 1 ORDER BY brand, model, registration_number');
        $bookings = $this->db->all(
            "SELECT a.vehicle_id, a.starts_at, a.ends_at, a.status, a.request_id, d.name AS department_name
             FROM assignments a JOIN requests r ON r.id = a.request_id JOIN departments d ON d.id = r.department_id
             WHERE a.status IN ('assigned','acknowledged','in_progress','finished') AND a.starts_at < ? AND a.ends_at > ?
             ORDER BY a.starts_at",
            [$dayEnd, $dayStart]
        );
        $maintenance = $this->db->all(
            'SELECT * FROM maintenance_periods WHERE starts_at < ? AND ends_at > ? ORDER BY starts_at',
            [$dayEnd, $dayStart]
        );
        $busyNow = array_column($this->db->all(
            "SELECT DISTINCT vehicle_id FROM assignments WHERE status IN ('assigned','acknowledged','in_progress') AND starts_at <= ? AND ends_at > ?",
            [$now, $now]
        ), 'vehicle_id');
        $repairNow = array_column($this->db->all(
            'SELECT DISTINCT vehicle_id FROM maintenance_periods WHERE starts_at <= ? AND ends_at > ?',
            [$now, $now]
        ), 'vehicle_id');

        foreach ($vehicles as &$v) {
            $id = (int) $v['id'];
            $v['state'] = match (true) {
                $v['status'] === 'decommissioned' => 'decommissioned',
                $v['status'] === 'repair' || in_array($id, array_map('intval', $repairNow), true) => 'repair',
                in_array($id, array_map('intval', $busyNow), true) => 'busy',
                default => 'free',
            };
            $v['bookings'] = array_values(array_filter($bookings, static fn ($b) => (int) $b['vehicle_id'] === $id));
            $v['maintenance'] = array_values(array_filter($maintenance, static fn ($m) => (int) $m['vehicle_id'] === $id));
        }
        return $vehicles;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM vehicles WHERE id = ?', [$id]);
    }

    /** @return list<array<string,mixed>> */
    public function maintenanceFor(int $vehicleId): array
    {
        return $this->db->all(
            'SELECT * FROM maintenance_periods WHERE vehicle_id = ? AND ends_at > ? ORDER BY starts_at',
            [$vehicleId, Clock::nowSql()]
        );
    }

    /** @return list<array<string,mixed>> */
    public function activeVehicles(): array
    {
        return $this->db->all("SELECT * FROM vehicles WHERE active = 1 AND status <> 'decommissioned' ORDER BY brand, model, registration_number");
    }

    public function setStatus(int $actorId, int $vehicleId, string $status): void
    {
        if (!isset(self::STATUS_LABELS[$status])) {
            throw new DomainError('Неизвестный статус.');
        }
        $this->db->transaction(function () use ($actorId, $vehicleId, $status) {
            $v = $this->db->one('SELECT * FROM vehicles WHERE id = ? FOR UPDATE', [$vehicleId]);
            if ($v === null) {
                throw new DomainError('Автомобиль не найден.');
            }
            $this->db->update('vehicles', $vehicleId, ['status' => $status]);
            $this->audit->record($actorId, 'vehicle', $vehicleId, 'set_status', ['status' => $v['status']], ['status' => $status]);
        });
    }

    /**
     * Плановый ремонт. Если на период уже есть подтверждённые поездки — отказ:
     * сначала их нужно переназначить.
     */
    public function addMaintenance(int $actorId, int $vehicleId, string $startsAt, string $endsAt, string $reason): int
    {
        $s = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $startsAt, Clock::timezone());
        $f = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $endsAt, Clock::timezone());
        if ($s === false || $f === false || $s >= $f) {
            throw new ValidationException(['maintenance' => 'Укажите корректный период ремонта.']);
        }
        $start = $s->format('Y-m-d H:i:s');
        $end = $f->format('Y-m-d H:i:s');
        return $this->db->transaction(function () use ($actorId, $vehicleId, $start, $end, $reason) {
            if ($this->db->one('SELECT id FROM vehicles WHERE id = ? FOR UPDATE', [$vehicleId]) === null) {
                throw new DomainError('Автомобиль не найден.');
            }
            $conflicts = $this->db->all(
                "SELECT request_id FROM assignments WHERE vehicle_id = ? AND status IN ('assigned','acknowledged','in_progress')
                 AND starts_at < ? AND ends_at > ?",
                [$vehicleId, $end, $start]
            );
            if ($conflicts) {
                throw new DomainError('На этот период автомобиль назначен по заявкам №' . implode(', №', array_column($conflicts, 'request_id')) . '. Сначала переназначьте их.');
            }
            $id = $this->db->insert('maintenance_periods', [
                'vehicle_id' => $vehicleId,
                'starts_at' => $start,
                'ends_at' => $end,
                'reason' => mb_substr(trim($reason), 0, 255),
                'created_by' => $actorId,
            ]);
            $this->audit->record($actorId, 'vehicle', $vehicleId, 'add_maintenance', null, ['maintenance_id' => $id, 'starts_at' => $start, 'ends_at' => $end, 'reason' => $reason]);
            return $id;
        });
    }

    public function deleteMaintenance(int $actorId, int $vehicleId, int $maintenanceId): void
    {
        $m = $this->db->one('SELECT * FROM maintenance_periods WHERE id = ? AND vehicle_id = ?', [$maintenanceId, $vehicleId]);
        if ($m === null) {
            throw new DomainError('Период ремонта не найден.');
        }
        $this->db->transaction(function () use ($actorId, $vehicleId, $maintenanceId, $m) {
            $this->db->run('DELETE FROM maintenance_periods WHERE id = ?', [$maintenanceId]);
            $this->audit->record($actorId, 'vehicle', $vehicleId, 'delete_maintenance', $m, null);
        });
    }
}
