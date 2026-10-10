<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;
use App\Core\DomainError;
use App\Core\ValidationException;
use DateTimeImmutable;

/**
 * Жизненный цикл заявки:
 * draft → submitted → under_review → approved → completed, а также rejected и cancelled.
 *
 * Создание заявки не резервирует автомобиль: резерв появляется только при подтверждении
 * диспетчером, когда создаётся назначение (assignments).
 */
final class RequestService
{
    public const STATUS_LABELS = [
        'draft' => 'Черновик',
        'submitted' => 'Подана',
        'under_review' => 'На рассмотрении',
        'approved' => 'Подтверждена',
        'rejected' => 'Отклонена',
        'cancelled' => 'Отменена',
        'completed' => 'Выполнена',
    ];

    public function __construct(
        private Database $db,
        private AvailabilityService $availability,
        private NotificationService $notifications,
        private AuditLog $audit,
    ) {
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one(
            'SELECT r.*, u.full_name AS requester_name, u.phone AS requester_phone, d.name AS department_name,
                    pv.registration_number AS preferred_vehicle_number, pv.brand AS preferred_vehicle_brand, pv.model AS preferred_vehicle_model
             FROM requests r
             JOIN users u ON u.id = r.requester_id
             JOIN departments d ON d.id = r.department_id
             LEFT JOIN vehicles pv ON pv.id = r.preferred_vehicle_id
             WHERE r.id = ?',
            [$id]
        );
    }

    /** Текущее (не отменённое) назначение по заявке. @return array<string,mixed>|null */
    public function currentAssignment(int $requestId): ?array
    {
        return $this->db->one(
            "SELECT a.*, v.brand, v.model, v.registration_number, u.full_name AS driver_name, u.phone AS driver_phone, u.id AS driver_user_id
             FROM assignments a
             JOIN vehicles v ON v.id = a.vehicle_id
             JOIN drivers d ON d.id = a.driver_id
             JOIN users u ON u.id = d.user_id
             WHERE a.request_id = ? AND a.status <> 'cancelled'
             ORDER BY a.id DESC LIMIT 1",
            [$requestId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function listForRequester(int $userId): array
    {
        return $this->db->all(
            'SELECT r.*, d.name AS department_name FROM requests r JOIN departments d ON d.id = r.department_id
             WHERE r.requester_id = ? ORDER BY r.starts_at DESC, r.id DESC',
            [$userId]
        );
    }

    /** @param list<string> $statuses @return list<array<string,mixed>> */
    public function listByStatus(array $statuses, string $order = 'ASC'): array
    {
        if (!$statuses) {
            return [];
        }
        $in = implode(',', array_fill(0, count($statuses), '?'));
        $order = $order === 'DESC' ? 'DESC' : 'ASC';
        return $this->db->all(
            "SELECT r.*, d.name AS department_name, u.full_name AS requester_name,
                    v.registration_number, du.full_name AS driver_name, a.status AS assignment_status
             FROM requests r
             JOIN departments d ON d.id = r.department_id
             JOIN users u ON u.id = r.requester_id
             LEFT JOIN assignments a ON a.request_id = r.id AND a.status <> 'cancelled'
             LEFT JOIN vehicles v ON v.id = a.vehicle_id
             LEFT JOIN drivers dr ON dr.id = a.driver_id
             LEFT JOIN users du ON du.id = dr.user_id
             WHERE r.status IN ($in)
             ORDER BY r.starts_at $order, r.id $order
             LIMIT 500",
            $statuses
        );
    }

    /**
     * Создаёт заявку (черновик или сразу поданную).
     *
     * @param array<string,mixed> $user
     * @param array<string,mixed> $input
     * @return array{id:int,warnings:list<string>}
     */
    public function create(array $user, array $input, bool $submit): array
    {
        $data = $this->validate($input, $user);
        $now = Clock::nowSql();
        $status = $submit ? 'submitted' : 'draft';
        $id = $this->db->transaction(function () use ($user, $data, $now, $status) {
            $id = $this->db->insert('requests', $data + [
                'requester_id' => (int) $user['id'],
                'priority' => 'normal',
                'status' => $status,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->audit->record((int) $user['id'], 'request', $id, $status === 'submitted' ? 'submit' : 'create_draft', null, $data + ['status' => $status]);
            if ($status === 'submitted') {
                $this->notifyDispatchersAboutNew($id, $data);
            }
            return $id;
        });
        return ['id' => $id, 'warnings' => $this->preferredVehicleWarnings($data, $id)];
    }

    /**
     * Сотрудник может редактировать только собственный черновик.
     *
     * @param array<string,mixed> $user
     * @param array<string,mixed> $input
     * @return list<string>
     */
    public function updateDraft(array $user, int $id, array $input): array
    {
        $request = $this->ownRequest($user, $id);
        if ($request['status'] !== 'draft') {
            throw new DomainError('Редактировать можно только черновик.');
        }
        $data = $this->validate($input, $user);
        $this->db->transaction(function () use ($user, $id, $data, $request) {
            $this->db->update('requests', $id, $data + ['updated_at' => Clock::nowSql()]);
            $this->audit->record((int) $user['id'], 'request', $id, 'update_draft', $this->snapshot($request), $data);
        });
        return $this->preferredVehicleWarnings($data, $id);
    }

    /** @param array<string,mixed> $user @return list<string> */
    public function submit(array $user, int $id): array
    {
        $request = $this->ownRequest($user, $id);
        if ($request['status'] !== 'draft') {
            throw new DomainError('Подать можно только черновик.');
        }
        if (new DateTimeImmutable($request['starts_at'], Clock::timezone()) <= Clock::now()) {
            throw new DomainError('Время начала поездки уже прошло. Измените дату и время.');
        }
        $this->db->transaction(function () use ($user, $id, $request) {
            $this->db->update('requests', $id, ['status' => 'submitted', 'updated_at' => Clock::nowSql()]);
            $this->audit->record((int) $user['id'], 'request', $id, 'submit', ['status' => 'draft'], ['status' => 'submitted']);
            $this->notifyDispatchersAboutNew($id, $request);
        });
        return $this->preferredVehicleWarnings($request, $id);
    }

    /** Отмена своей заявки до подтверждения. Подтверждённую отменяет диспетчер. @param array<string,mixed> $user */
    public function cancelOwn(array $user, int $id): void
    {
        $this->db->transaction(function () use ($user, $id) {
            $request = $this->lockRequest($id);
            if ((int) $request['requester_id'] !== (int) $user['id']) {
                throw new DomainError('Заявка не найдена.');
            }
            if (!in_array($request['status'], ['draft', 'submitted', 'under_review'], true)) {
                throw new DomainError('Подтверждённую заявку может отменить или перенести только диспетчер.');
            }
            $this->setStatus($id, 'cancelled');
            $this->audit->record((int) $user['id'], 'request', $id, 'cancel', ['status' => $request['status']], ['status' => 'cancelled']);
            if ($request['status'] !== 'draft') {
                $this->notifications->notifyRole('dispatcher', 'request_cancelled', "Заявка №$id отменена заказчиком", '', "/dispatch/requests/$id");
            }
        });
    }

    /** @param array<string,mixed> $dispatcher */
    public function takeForReview(array $dispatcher, int $id): void
    {
        $this->db->transaction(function () use ($dispatcher, $id) {
            $request = $this->lockRequest($id);
            if ($request['status'] !== 'submitted') {
                throw new DomainError('Взять в работу можно только поданную заявку.');
            }
            $this->setStatus($id, 'under_review');
            $this->audit->record((int) $dispatcher['id'], 'request', $id, 'take_for_review', ['status' => 'submitted'], ['status' => 'under_review']);
            $this->notifications->notify((int) $request['requester_id'], 'request_under_review', "Заявка №$id на рассмотрении", 'Диспетчер начал рассмотрение заявки.', "/requests/$id");
        });
    }

    /**
     * Подтверждение с назначением автомобиля и водителя.
     * Поданная заявка сначала переводится в under_review (переход фиксируется в журнале).
     *
     * @param array<string,mixed> $dispatcher
     */
    public function approve(array $dispatcher, int $id, int $vehicleId, int $driverId): int
    {
        return $this->db->transaction(function () use ($dispatcher, $id, $vehicleId, $driverId) {
            $request = $this->lockRequest($id);
            if (!in_array($request['status'], ['submitted', 'under_review'], true)) {
                throw new DomainError('Заявка уже обработана (статус: ' . self::STATUS_LABELS[$request['status']] . ').');
            }
            if (new DateTimeImmutable($request['ends_at'], Clock::timezone()) <= Clock::now()) {
                throw new DomainError('Время поездки уже прошло.');
            }
            if ($vehicleId <= 0 || $driverId <= 0) {
                throw new DomainError('Выберите автомобиль и водителя.');
            }
            $actor = (int) $dispatcher['id'];
            if ($request['status'] === 'submitted') {
                $this->audit->record($actor, 'request', $id, 'take_for_review', ['status' => 'submitted'], ['status' => 'under_review']);
            }

            $passengers = $request['passenger_count'] !== null ? (int) $request['passenger_count'] : null;
            $this->availability->lockAndAssert($vehicleId, $driverId, $request['starts_at'], $request['ends_at'], $id, $passengers);

            $assignmentId = $this->insertAssignment($id, $vehicleId, $driverId, $actor, $request['starts_at'], $request['ends_at']);
            $this->db->update('requests', $id, ['status' => 'approved', 'decision_reason' => null, 'updated_at' => Clock::nowSql()]);
            $this->audit->record($actor, 'request', $id, 'approve', ['status' => 'under_review'], [
                'status' => 'approved', 'assignment_id' => $assignmentId, 'vehicle_id' => $vehicleId, 'driver_id' => $driverId,
            ]);

            $a = $this->currentAssignment($id);
            $when = fmt_range($request['starts_at'], $request['ends_at']);
            $car = "{$a['brand']} {$a['model']}, {$a['registration_number']}";
            $this->notifications->notify(
                (int) $request['requester_id'],
                'request_approved',
                "Заявка №$id подтверждена",
                "$when. Автомобиль: $car. Водитель: {$a['driver_name']}" . ($a['driver_phone'] ? ", тел. {$a['driver_phone']}" : '') . '.',
                "/requests/$id"
            );
            $this->notifications->notify(
                (int) $a['driver_user_id'],
                'assignment_created',
                "Новый рейс по заявке №$id",
                "$when. {$request['pickup_location']} → {$request['destination']}. Автомобиль: $car. Подтвердите получение.",
                '/driver'
            );
            return $assignmentId;
        });
    }

    /** @param array<string,mixed> $dispatcher */
    public function reject(array $dispatcher, int $id, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ValidationException(['decision_reason' => 'Укажите причину отклонения.']);
        }
        $this->db->transaction(function () use ($dispatcher, $id, $reason) {
            $request = $this->lockRequest($id);
            if (!in_array($request['status'], ['submitted', 'under_review'], true)) {
                throw new DomainError('Заявка уже обработана.');
            }
            $this->db->update('requests', $id, ['status' => 'rejected', 'decision_reason' => mb_substr($reason, 0, 1000), 'updated_at' => Clock::nowSql()]);
            $this->audit->record((int) $dispatcher['id'], 'request', $id, 'reject', ['status' => $request['status']], ['status' => 'rejected', 'decision_reason' => $reason]);
            $this->notifications->notify((int) $request['requester_id'], 'request_rejected', "Заявка №$id отклонена", "Причина: $reason", "/requests/$id");
        });
    }

    /**
     * Изменение подтверждённой поездки: другой автомобиль, водитель и/или время.
     * Старое назначение отменяется, создаётся новое; водители и заказчик получают уведомления.
     *
     * @param array<string,mixed> $dispatcher
     */
    public function reassign(array $dispatcher, int $id, int $vehicleId, int $driverId, ?string $startsAt = null, ?string $endsAt = null): int
    {
        return $this->db->transaction(function () use ($dispatcher, $id, $vehicleId, $driverId, $startsAt, $endsAt) {
            $request = $this->lockRequest($id);
            if ($request['status'] !== 'approved') {
                throw new DomainError('Изменить можно только подтверждённую заявку.');
            }
            $old = $this->db->one(
                "SELECT a.*, d.user_id AS driver_user_id FROM assignments a JOIN drivers d ON d.id = a.driver_id
                 WHERE a.request_id = ? AND a.status IN ('assigned','acknowledged','in_progress') FOR UPDATE",
                [$id]
            );
            if ($old !== null && $old['status'] === 'in_progress') {
                throw new DomainError('Поездка уже началась, изменить назначение нельзя.');
            }
            $start = $startsAt ?? $request['starts_at'];
            $end = $endsAt ?? $request['ends_at'];
            $this->assertInterval($start, $end, 'starts_at');

            $passengers = $request['passenger_count'] !== null ? (int) $request['passenger_count'] : null;
            // Текущее назначение этой же заявки не считается конфликтом.
            $this->availability->lockAndAssert($vehicleId, $driverId, $start, $end, $id, $passengers);

            if ($old !== null) {
                $this->db->update('assignments', (int) $old['id'], ['status' => 'cancelled']);
            }
            $assignmentId = $this->insertAssignment($id, $vehicleId, $driverId, (int) $dispatcher['id'], $start, $end);
            $this->db->update('requests', $id, [
                'starts_at' => $start,
                'ends_at' => $end,
                'requested_date' => substr($start, 0, 10),
                'updated_at' => Clock::nowSql(),
            ]);
            $this->audit->record((int) $dispatcher['id'], 'request', $id, 'reassign', [
                'assignment_id' => $old['id'] ?? null, 'vehicle_id' => $old['vehicle_id'] ?? null, 'driver_id' => $old['driver_id'] ?? null,
                'starts_at' => $request['starts_at'], 'ends_at' => $request['ends_at'],
            ], [
                'assignment_id' => $assignmentId, 'vehicle_id' => $vehicleId, 'driver_id' => $driverId, 'starts_at' => $start, 'ends_at' => $end,
            ]);

            $a = $this->currentAssignment($id);
            $when = fmt_range($start, $end);
            $car = "{$a['brand']} {$a['model']}, {$a['registration_number']}";
            $this->notifications->notify((int) $request['requester_id'], 'assignment_changed', "Изменения по заявке №$id", "$when. Автомобиль: $car. Водитель: {$a['driver_name']}.", "/requests/$id");
            $this->notifications->notify((int) $a['driver_user_id'], 'assignment_changed', "Рейс по заявке №$id: новые данные", "$when. {$request['pickup_location']} → {$request['destination']}. Автомобиль: $car. Подтвердите получение.", '/driver');
            if ($old !== null && (int) $old['driver_user_id'] !== (int) $a['driver_user_id']) {
                $this->notifications->notify((int) $old['driver_user_id'], 'assignment_cancelled', "Рейс по заявке №$id снят", 'Диспетчер назначил другого водителя.', '/driver');
            }
            return $assignmentId;
        });
    }

    /** Отмена диспетчером (в том числе подтверждённой поездки, пока она не началась). @param array<string,mixed> $dispatcher */
    public function cancelByDispatcher(array $dispatcher, int $id, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ValidationException(['decision_reason' => 'Укажите причину отмены.']);
        }
        $this->db->transaction(function () use ($dispatcher, $id, $reason) {
            $request = $this->lockRequest($id);
            if (!in_array($request['status'], ['submitted', 'under_review', 'approved'], true)) {
                throw new DomainError('Эту заявку отменить нельзя.');
            }
            $a = $this->db->one(
                "SELECT a.*, d.user_id AS driver_user_id FROM assignments a JOIN drivers d ON d.id = a.driver_id
                 WHERE a.request_id = ? AND a.status IN ('assigned','acknowledged','in_progress') FOR UPDATE",
                [$id]
            );
            if ($a !== null && $a['status'] === 'in_progress') {
                throw new DomainError('Поездка уже началась, отменить её нельзя.');
            }
            if ($a !== null) {
                $this->db->update('assignments', (int) $a['id'], ['status' => 'cancelled']);
            }
            $this->db->update('requests', $id, ['status' => 'cancelled', 'decision_reason' => mb_substr($reason, 0, 1000), 'updated_at' => Clock::nowSql()]);
            $this->audit->record((int) $dispatcher['id'], 'request', $id, 'cancel', ['status' => $request['status']], ['status' => 'cancelled', 'decision_reason' => $reason]);
            $this->notifications->notify((int) $request['requester_id'], 'request_cancelled', "Заявка №$id отменена диспетчером", "Причина: $reason", "/requests/$id");
            if ($a !== null) {
                $this->notifications->notify((int) $a['driver_user_id'], 'request_cancelled', "Рейс по заявке №$id отменён", "Причина: $reason", '/driver');
            }
        });
    }

    // ---------------------------------------------------------------------

    /**
     * Проверка формы заявки. Возвращает данные для таблицы requests.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    public function validate(array $input, array $user): array
    {
        $errors = [];
        $str = static fn (string $k): string => trim((string) ($input[$k] ?? ''));

        $date = $str('requested_date');
        $endDate = $str('end_date') !== '' ? $str('end_date') : $date;
        $startTime = $str('start_time');
        $endTime = $str('end_time');

        $startsAt = $this->parseDateTime($date, $startTime);
        $endsAt = $this->parseDateTime($endDate, $endTime);
        if ($startsAt === null) {
            $errors['requested_date'] = 'Укажите дату и время начала поездки.';
        }
        if ($endsAt === null) {
            $errors['end_time'] = 'Укажите время окончания поездки.';
        }

        $departmentId = (int) ($input['department_id'] ?? 0);
        if ($departmentId <= 0 || !$this->db->value('SELECT id FROM departments WHERE id = ? AND active = 1', [$departmentId])) {
            $errors['department_id'] = 'Выберите подразделение.';
        }

        foreach (['pickup_location' => 'Укажите место подачи.', 'destination' => 'Укажите пункт назначения.'] as $k => $msg) {
            if ($str($k) === '') {
                $errors[$k] = $msg;
            } elseif (mb_strlen($str($k)) > 255) {
                $errors[$k] = 'Не более 255 символов.';
            }
        }
        if ($str('purpose') === '') {
            $errors['purpose'] = 'Укажите цель поездки.';
        } elseif (mb_strlen($str('purpose')) > 1000) {
            $errors['purpose'] = 'Не более 1000 символов.';
        }
        if (mb_strlen($str('comment')) > 1000) {
            $errors['comment'] = 'Не более 1000 символов.';
        }
        $phone = $str('contact_phone');
        if ($phone !== '' && !preg_match('/^[0-9+()\-\s]{5,32}$/u', $phone)) {
            $errors['contact_phone'] = 'Телефон указан в неверном формате.';
        }

        $passengers = null;
        if ($str('passenger_count') !== '') {
            if (!ctype_digit($str('passenger_count')) || (int) $str('passenger_count') < 1 || (int) $str('passenger_count') > 50) {
                $errors['passenger_count'] = 'Количество пассажиров — от 1 до 50.';
            } else {
                $passengers = (int) $str('passenger_count');
            }
        }

        $preferred = null;
        if ($str('preferred_vehicle_id') !== '') {
            $preferred = (int) $str('preferred_vehicle_id');
            if (!$this->db->value("SELECT id FROM vehicles WHERE id = ? AND active = 1 AND status <> 'decommissioned'", [$preferred])) {
                $errors['preferred_vehicle_id'] = 'Автомобиль не найден.';
            }
        }

        if ($startsAt !== null && $endsAt !== null) {
            try {
                $this->assertInterval($startsAt, $endsAt, 'requested_date');
            } catch (ValidationException $e) {
                $errors += $e->errors;
            }
        }

        if ($errors) {
            throw new ValidationException($errors);
        }

        return [
            'department_id' => $departmentId,
            'requested_date' => substr((string) $startsAt, 0, 10),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'pickup_location' => $str('pickup_location'),
            'destination' => $str('destination'),
            'purpose' => $str('purpose'),
            'passenger_count' => $passengers,
            'preferred_vehicle_id' => $preferred,
            'comment' => $str('comment') !== '' ? $str('comment') : null,
            'contact_phone' => $phone !== '' ? $phone : null,
        ];
    }

    private function assertInterval(string $start, string $end, string $field): void
    {
        $tz = Clock::timezone();
        $s = new DateTimeImmutable($start, $tz);
        $f = new DateTimeImmutable($end, $tz);
        if ($s >= $f) {
            throw new ValidationException(['end_time' => 'Время окончания должно быть позже времени начала.']);
        }
        if ($s <= Clock::now()) {
            throw new ValidationException([$field => 'Нельзя подать заявку на прошедшее время.']);
        }
        if ($f > $s->modify('+7 days')) {
            throw new ValidationException(['end_time' => 'Поездка не может длиться дольше 7 суток.']);
        }
    }

    private function parseDateTime(string $date, string $time): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i', "$date $time", Clock::timezone());
        if ($dt === false || $dt->format('Y-m-d H:i') !== "$date $time") {
            return null;
        }
        return $dt->format('Y-m-d H:i:s');
    }

    /** Предупреждение (не запрет), если желаемый автомобиль уже занят — вопрос Q01. @param array<string,mixed> $data @return list<string> */
    private function preferredVehicleWarnings(array $data, int $requestId): array
    {
        if (empty($data['preferred_vehicle_id'])) {
            return [];
        }
        $vehicle = $this->db->one('SELECT * FROM vehicles WHERE id = ?', [(int) $data['preferred_vehicle_id']]);
        if ($vehicle === null) {
            return [];
        }
        $problems = $this->availability->vehicleProblems($vehicle, $data['starts_at'], $data['ends_at'], $requestId);
        if (!$problems) {
            return [];
        }
        return ['Желаемый автомобиль ' . $vehicle['registration_number'] . ' на это время недоступен: ' . implode('; ', $problems) . '. Диспетчер подберёт другой.'];
    }

    /** @param array<string,mixed> $data */
    private function notifyDispatchersAboutNew(int $id, array $data): void
    {
        $this->notifications->notifyRole(
            'dispatcher',
            'request_submitted',
            "Новая заявка №$id",
            fmt_range($data['starts_at'], $data['ends_at']) . ". {$data['pickup_location']} → {$data['destination']}.",
            "/dispatch/requests/$id"
        );
    }

    /** @param array<string,mixed> $user @return array<string,mixed> */
    private function ownRequest(array $user, int $id): array
    {
        $request = $this->db->one('SELECT * FROM requests WHERE id = ?', [$id]);
        if ($request === null || (int) $request['requester_id'] !== (int) $user['id']) {
            throw new DomainError('Заявка не найдена.');
        }
        return $request;
    }

    /** @return array<string,mixed> */
    private function lockRequest(int $id): array
    {
        $request = $this->db->one('SELECT * FROM requests WHERE id = ? FOR UPDATE', [$id]);
        if ($request === null) {
            throw new DomainError('Заявка не найдена.');
        }
        return $request;
    }

    private function setStatus(int $id, string $status): void
    {
        $this->db->update('requests', $id, ['status' => $status, 'updated_at' => Clock::nowSql()]);
    }

    private function insertAssignment(int $requestId, int $vehicleId, int $driverId, int $actor, string $start, string $end): int
    {
        return $this->db->insert('assignments', [
            'request_id' => $requestId,
            'vehicle_id' => $vehicleId,
            'driver_id' => $driverId,
            'starts_at' => $start,
            'ends_at' => $end,
            'status' => 'assigned',
            'assigned_by' => $actor,
            'assigned_at' => Clock::nowSql(),
        ]);
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function snapshot(array $r): array
    {
        return array_intersect_key($r, array_flip([
            'department_id', 'starts_at', 'ends_at', 'pickup_location', 'destination', 'purpose',
            'passenger_count', 'preferred_vehicle_id', 'comment', 'contact_phone', 'status',
        ]));
    }
}
