<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\DomainError;
use App\Core\ValidationException;

/** Справочники и учётные записи (кабинет администратора). */
final class AdminService
{
    public const ROLE_LABELS = [
        'employee' => 'Сотрудник',
        'dispatcher' => 'Диспетчер',
        'driver' => 'Водитель',
        'admin' => 'Администратор',
    ];

    public function __construct(private Database $db, private AuditLog $audit)
    {
    }

    /** @return list<array<string,mixed>> */
    public function users(): array
    {
        return $this->db->all(
            'SELECT u.*, d.name AS department_name FROM users u LEFT JOIN departments d ON d.id = u.department_id ORDER BY u.active DESC, u.full_name'
        );
    }

    /** @return list<array<string,mixed>> */
    public function departments(bool $onlyActive = false): array
    {
        return $this->db->all('SELECT * FROM departments' . ($onlyActive ? ' WHERE active = 1' : '') . ' ORDER BY name');
    }

    /** @return list<array<string,mixed>> */
    public function vehicles(): array
    {
        return $this->db->all('SELECT * FROM vehicles ORDER BY active DESC, brand, model');
    }

    /**
     * Создаёт пользователя с временным паролем, который нужно сменить при первом входе.
     *
     * @param array<string,mixed> $input
     * @return array{id:int,password:string}
     */
    public function createUser(?int $actorId, array $input): array
    {
        $login = mb_strtolower(trim((string) ($input['login'] ?? '')));
        $fullName = trim((string) ($input['full_name'] ?? ''));
        $role = (string) ($input['role'] ?? '');
        $departmentId = (int) ($input['department_id'] ?? 0) ?: null;
        $phone = trim((string) ($input['phone'] ?? ''));

        $errors = [];
        if (!preg_match('/^[a-z0-9._-]{3,64}$/', $login)) {
            $errors['login'] = 'Логин: 3–64 символа, латиница, цифры, точка, дефис, подчёркивание.';
        } elseif ($this->db->value('SELECT id FROM users WHERE login = ?', [$login])) {
            $errors['login'] = 'Такой логин уже есть.';
        }
        if ($fullName === '' || mb_strlen($fullName) > 190) {
            $errors['full_name'] = 'Укажите ФИО.';
        }
        if (!isset(self::ROLE_LABELS[$role])) {
            $errors['role'] = 'Выберите роль.';
        }
        if ($phone !== '' && !preg_match('/^[0-9+()\-\s]{5,32}$/u', $phone)) {
            $errors['phone'] = 'Телефон указан в неверном формате.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }

        $password = self::temporaryPassword();
        $id = $this->db->transaction(function () use ($actorId, $login, $fullName, $role, $departmentId, $phone, $password) {
            $id = $this->db->insert('users', [
                'login' => $login,
                'password_hash' => AuthService::hash($password),
                'full_name' => $fullName,
                'phone' => $phone !== '' ? $phone : null,
                'department_id' => $departmentId,
                'role' => $role,
                'active' => 1,
                'must_change_password' => 1,
                'created_at' => \App\Core\Clock::nowSql(),
            ]);
            if ($role === 'driver') {
                $this->db->insert('drivers', ['user_id' => $id, 'active' => 1]);
            }
            $this->audit->record($actorId, 'user', $id, 'create', null, ['login' => $login, 'role' => $role]);
            return $id;
        });
        return ['id' => $id, 'password' => $password];
    }

    public function resetPassword(int $actorId, int $userId): string
    {
        if ($this->db->value('SELECT id FROM users WHERE id = ?', [$userId]) === null) {
            throw new DomainError('Пользователь не найден.');
        }
        $password = self::temporaryPassword();
        $this->db->update('users', $userId, ['password_hash' => AuthService::hash($password), 'must_change_password' => 1]);
        $this->audit->record($actorId, 'user', $userId, 'reset_password');
        return $password;
    }

    public function toggleUser(int $actorId, int $userId): void
    {
        if ($actorId === $userId) {
            throw new DomainError('Нельзя заблокировать собственную учётную запись.');
        }
        $u = $this->db->one('SELECT * FROM users WHERE id = ?', [$userId]);
        if ($u === null) {
            throw new DomainError('Пользователь не найден.');
        }
        $active = (int) $u['active'] ? 0 : 1;
        $this->db->update('users', $userId, ['active' => $active]);
        $this->audit->record($actorId, 'user', $userId, $active ? 'activate' : 'deactivate');
    }

    public function createDepartment(int $actorId, string $name): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 190) {
            throw new ValidationException(['name' => 'Укажите название подразделения.']);
        }
        if ($this->db->value('SELECT id FROM departments WHERE name = ?', [$name])) {
            throw new ValidationException(['name' => 'Такое подразделение уже есть.']);
        }
        $id = $this->db->insert('departments', ['name' => $name, 'active' => 1]);
        $this->audit->record($actorId, 'department', $id, 'create', null, ['name' => $name]);
    }

    /** @param array<string,mixed> $input */
    public function createVehicle(int $actorId, array $input): void
    {
        $brand = trim((string) ($input['brand'] ?? ''));
        $model = trim((string) ($input['model'] ?? ''));
        $reg = mb_strtoupper(trim((string) ($input['registration_number'] ?? '')));
        $seats = (int) ($input['seat_count'] ?? 0);
        $errors = [];
        if ($brand === '' || mb_strlen($brand) > 64) {
            $errors['brand'] = 'Укажите марку.';
        }
        if ($model === '' || mb_strlen($model) > 64) {
            $errors['model'] = 'Укажите модель.';
        }
        if ($reg === '' || mb_strlen($reg) > 20) {
            $errors['registration_number'] = 'Укажите госномер.';
        } elseif ($this->db->value('SELECT id FROM vehicles WHERE registration_number = ?', [$reg])) {
            $errors['registration_number'] = 'Автомобиль с таким номером уже есть.';
        }
        if ($seats < 1 || $seats > 8) {
            $errors['seat_count'] = 'Пассажирских мест: от 1 до 8 (только легковые).';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $id = $this->db->insert('vehicles', [
            'brand' => $brand, 'model' => $model, 'registration_number' => $reg,
            'seat_count' => $seats, 'status' => 'in_service', 'active' => 1,
        ]);
        $this->audit->record($actorId, 'vehicle', $id, 'create', null, ['registration_number' => $reg]);
    }

    private static function temporaryPassword(): string
    {
        // Без похожих символов (0/O, 1/l/I), чтобы пароль легко продиктовать.
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 12; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }
}
