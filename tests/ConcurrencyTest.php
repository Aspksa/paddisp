<?php

declare(strict_types=1);

namespace Tests;

use App\Core\App;
use App\Core\Database;
use App\Core\DomainError;

/**
 * Защита от двойного бронирования при одновременном подтверждении.
 * Параллельная транзакция держит блокировку автомобиля и создаёт пересекающееся назначение;
 * подтверждение в другом процессе ждёт блокировку и после неё обязано увидеть конфликт.
 */
final class ConcurrencyTest extends TestCase
{
    public function testConcurrentApprovalOfOverlappingRequestsBooksVehicleOnce(): void
    {
        if (!function_exists('pcntl_fork')) {
            echo 'S';
            return;
        }
        $first = $this->submitRequest('employee', '2026-10-15', '10:00', '12:00');
        $second = $this->submitRequest('employee2', '2026-10-15', '11:00', '13:00');

        $holder = Database::connect(self::$config->get('database'));
        $holder->pdo->beginTransaction();
        $holder->one('SELECT id FROM vehicles WHERE id = ? FOR UPDATE', [$this->vehicles['car1']]);

        $resultFile = tempnam(sys_get_temp_dir(), 'paddisp');
        $pid = pcntl_fork();
        if ($pid === 0) {
            // Дочерний процесс: собственное соединение, подтверждает вторую заявку.
            $db = Database::connect(self::$config->get('database'));
            $app = new App(self::$config, $db);
            try {
                $dispatcher = $db->one('SELECT * FROM users WHERE id = ?', [$this->users['dispatcher']]);
                $app->requests->approve($dispatcher, $second, $this->vehicles['car1'], $this->drivers['driver2']);
                file_put_contents($resultFile, 'approved');
            } catch (DomainError $e) {
                file_put_contents($resultFile, 'conflict: ' . $e->getMessage());
            } catch (\Throwable $e) {
                file_put_contents($resultFile, 'error: ' . $e->getMessage());
            }
            posix_kill(getmypid(), SIGKILL); // выход без деструкторов общего соединения родителя
        }

        // Даём дочернему процессу упереться в блокировку, затем «первая» транзакция бронирует машину.
        usleep(500_000);
        $holder->insert('assignments', [
            'request_id' => $first, 'vehicle_id' => $this->vehicles['car1'], 'driver_id' => $this->drivers['driver1'],
            'starts_at' => '2026-10-15 10:00:00', 'ends_at' => '2026-10-15 12:00:00', 'status' => 'assigned',
            'assigned_by' => $this->users['dispatcher'], 'assigned_at' => self::NOW,
        ]);
        $holder->update('requests', $first, ['status' => 'approved']);
        $holder->pdo->commit();

        pcntl_waitpid($pid, $status);
        $result = (string) file_get_contents($resultFile);
        unlink($resultFile);

        $this->assertContains('conflict', $result, 'Результат второго подтверждения');
        $this->assertContains("занят по заявке №$first", $result);
        $this->assertSame(1, $this->count("SELECT COUNT(*) FROM assignments WHERE vehicle_id = ? AND status = 'assigned'", [$this->vehicles['car1']]));
        $this->assertSame('submitted', self::$db->value('SELECT status FROM requests WHERE id = ?', [$second]));
    }
}
