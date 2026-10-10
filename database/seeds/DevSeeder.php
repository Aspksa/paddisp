<?php

declare(strict_types=1);

namespace Database\Seeds;

use App\Core\Clock;
use App\Core\Database;
use App\Services\AuthService;

/**
 * Демо-данные только для разработки. В production не запускать:
 * bin/seed-dev.php отказывается работать при APP_ENV=production.
 */
final class DevSeeder
{
    public const PASSWORD = 'demo-password';

    public function __construct(private Database $db)
    {
    }

    public function run(): void
    {
        $now = Clock::nowSql();
        $hash = AuthService::hash(self::PASSWORD);

        $deps = [];
        foreach (['Администрация', 'Производственно-технический отдел', 'Бухгалтерия'] as $name) {
            $deps[$name] = $this->db->insert('departments', ['name' => $name, 'active' => 1]);
        }

        $user = function (string $login, string $name, string $role, ?string $dep, ?string $phone = null) use ($hash, $now, $deps): int {
            return $this->db->insert('users', [
                'login' => $login, 'password_hash' => $hash, 'full_name' => $name, 'phone' => $phone,
                'department_id' => $dep ? $deps[$dep] : null, 'role' => $role, 'active' => 1,
                'must_change_password' => 0, 'created_at' => $now,
            ]);
        };
        $user('admin', 'Администратор системы', 'admin', 'Администрация');
        $user('dispatcher', 'Диспетчер Иванова А. П.', 'dispatcher', 'Администрация', '+7 900 000-00-01');
        $user('employee', 'Петров С. В.', 'employee', 'Производственно-технический отдел', '+7 900 000-00-02');
        $user('employee2', 'Сидорова Е. Н.', 'employee', 'Бухгалтерия', '+7 900 000-00-03');
        foreach ([['driver', 'Водитель Кузнецов И. И.'], ['driver2', 'Водитель Смирнов О. Л.'], ['driver3', 'Водитель Орлов Д. А.']] as $i => [$login, $name]) {
            $uid = $user($login, $name, 'driver', null, '+7 900 000-00-1' . $i);
            $this->db->insert('drivers', ['user_id' => $uid, 'active' => 1]);
        }

        $vehicles = [
            ['Toyota', 'Camry', 'А101АА125', 4, 'in_service'],
            ['Toyota', 'Corolla', 'В202ВВ125', 4, 'in_service'],
            ['Hyundai', 'Solaris', 'Е303ЕЕ125', 4, 'in_service'],
            ['Lada', 'Vesta', 'К404КК125', 4, 'repair'],
            ['Toyota', 'Land Cruiser Prado', 'М505ММ125', 6, 'in_service'],
        ];
        foreach ($vehicles as [$brand, $model, $reg, $seats, $status]) {
            $id = $this->db->insert('vehicles', [
                'brand' => $brand, 'model' => $model, 'registration_number' => $reg,
                'seat_count' => $seats, 'status' => $status, 'active' => 1,
            ]);
            if ($reg === 'Е303ЕЕ125') {
                $start = Clock::now()->modify('+2 days')->setTime(8, 0);
                $this->db->insert('maintenance_periods', [
                    'vehicle_id' => $id,
                    'starts_at' => $start->format('Y-m-d H:i:s'),
                    'ends_at' => $start->modify('+1 day')->format('Y-m-d H:i:s'),
                    'reason' => 'Плановое ТО',
                ]);
            }
        }
    }
}
