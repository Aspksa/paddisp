<?php

declare(strict_types=1);

namespace Tests;

use App\Core\ValidationException;

final class ApprovalTest extends TestCase
{
    public function testDispatcherApprovesWithAvailableVehicleAndDriver(): void
    {
        $id = $this->submitRequest();
        $r = $this->client('dispatcher')->post("/dispatch/requests/$id/approve", ['vehicle_id' => $this->vehicles['car1'], 'driver_id' => $this->drivers['driver1']]);
        $this->assertRedirect($r, "/dispatch/requests/$id");
        $this->assertSame('approved', self::$db->value('SELECT status FROM requests WHERE id = ?', [$id]));
        $a = self::$db->one('SELECT * FROM assignments WHERE request_id = ?', [$id]);
        $this->assertSame($this->vehicles['car1'], (int) $a['vehicle_id']);
        $this->assertSame($this->drivers['driver1'], (int) $a['driver_id']);
        $this->assertSame('assigned', $a['status']);
        $this->assertSame('2026-10-15 10:00:00', $a['starts_at']);
    }

    public function testRequesterAndDriverAreNotifiedAfterApproval(): void
    {
        $id = $this->approve($this->submitRequest());
        $this->assertTrue($id > 0);
        $this->assertSame(1, $this->count("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'request_approved'", [$this->users['employee']]));
        $this->assertSame(1, $this->count("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'assignment_created'", [$this->users['driver1']]));
        $this->assertSame(0, $this->count('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$this->users['driver2']]));

        $page = $this->client('employee')->get('/notifications');
        $this->assertContains('подтверждена', $page->body);
        $this->assertContains('<span class="counter">1</span>', $page->body);
        $driverPage = $this->client('driver1')->get('/driver');
        $this->assertContains('Подтвердить получение', $driverPage->body);
    }

    public function testOverlappingTripsCannotShareVehicle(): void
    {
        $first = $this->submitRequest('employee', '2026-10-15', '10:00', '12:00');
        $second = $this->submitRequest('employee2', '2026-10-15', '11:30', '13:00');
        $this->approve($first, 'car1', 'driver1');
        $this->assertDomainError(fn () => $this->approve($second, 'car1', 'driver2'), "занят по заявке №$first");
        $this->assertSame('submitted', self::$db->value('SELECT status FROM requests WHERE id = ?', [$second]));
        $this->assertSame(1, $this->count('SELECT COUNT(*) FROM assignments'));
        // С другой машиной — можно.
        $this->approve($second, 'car2', 'driver2');
        $this->assertSame('approved', self::$db->value('SELECT status FROM requests WHERE id = ?', [$second]));
    }

    public function testOverlappingTripsCannotShareDriver(): void
    {
        $first = $this->submitRequest('employee', '2026-10-15', '10:00', '12:00');
        $second = $this->submitRequest('employee2', '2026-10-15', '09:00', '10:30');
        $this->approve($first, 'car1', 'driver1');
        $this->assertDomainError(fn () => $this->approve($second, 'car2', 'driver1'), 'Водитель Driver1');
        $this->assertSame(1, $this->count('SELECT COUNT(*) FROM assignments'));
    }

    public function testBackToBackTripsAreAllowed(): void
    {
        $first = $this->submitRequest('employee', '2026-10-15', '10:00', '12:00');
        $second = $this->submitRequest('employee2', '2026-10-15', '12:00', '13:00');
        $this->approve($first);
        $this->approve($second);
        $this->assertSame(2, $this->count("SELECT COUNT(*) FROM requests WHERE status = 'approved'"));
    }

    public function testCancelledAssignmentFreesVehicle(): void
    {
        $first = $this->submitRequest();
        $this->approve($first);
        $this->app->requests->cancelByDispatcher($this->user('dispatcher'), $first, 'Встреча перенесена');
        $second = $this->submitRequest('employee2');
        $this->approve($second);
        $this->assertSame('approved', self::$db->value('SELECT status FROM requests WHERE id = ?', [$second]));
        $this->assertSame(1, $this->count("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'request_cancelled'", [$this->users['driver1']]));
    }

    public function testVehicleUnderMaintenanceCannotBeAssigned(): void
    {
        $this->app->fleet->addMaintenance($this->users['dispatcher'], $this->vehicles['car1'], '2026-10-15T08:00', '2026-10-15T11:00', 'Замена масла');
        $id = $this->submitRequest('employee', '2026-10-15', '10:00', '12:00');
        $this->assertDomainError(fn () => $this->approve($id, 'car1'), 'плановый ремонт');
        // После окончания ремонта машина доступна.
        $later = $this->submitRequest('employee', '2026-10-15', '11:00', '12:00');
        $this->approve($later, 'car1');
        $this->assertSame('approved', self::$db->value('SELECT status FROM requests WHERE id = ?', [$later]));
    }

    public function testVehicleInRepairStatusCannotBeAssigned(): void
    {
        $this->app->fleet->setStatus($this->users['dispatcher'], $this->vehicles['car1'], 'repair');
        $id = $this->submitRequest();
        $this->assertDomainError(fn () => $this->approve($id, 'car1'), 'в ремонте');
    }

    public function testDispatcherReviewPageMarksUnavailableOptions(): void
    {
        $this->approve($this->submitRequest('employee2'), 'car1', 'driver1');
        $id = $this->submitRequest();
        $page = $this->client('dispatcher')->get("/dispatch/requests/$id");
        $this->assertSame(200, $page->status);
        $this->assertContains('занят по заявке', $page->body);
        $this->assertContains('disabled', $page->body);
    }

    public function testRejectionRequiresReasonAndNotifiesRequester(): void
    {
        $id = $this->submitRequest();
        $this->assertThrows(ValidationException::class, fn () => $this->app->requests->reject($this->user('dispatcher'), $id, '  '));
        $this->app->requests->reject($this->user('dispatcher'), $id, 'Нет свободных машин');
        $row = self::$db->one('SELECT * FROM requests WHERE id = ?', [$id]);
        $this->assertSame('rejected', $row['status']);
        $this->assertSame('Нет свободных машин', $row['decision_reason']);
        $this->assertSame(1, $this->count("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'request_rejected'", [$this->users['employee']]));
        $this->assertDomainError(fn () => $this->approve($id), 'уже обработана');
    }

    public function testOnlyDispatcherCanApprove(): void
    {
        $id = $this->submitRequest();
        foreach (['employee', 'driver1', 'admin'] as $login) {
            $r = $this->client($login)->post("/dispatch/requests/$id/approve", ['vehicle_id' => $this->vehicles['car1'], 'driver_id' => $this->drivers['driver1']]);
            $this->assertSame(403, $r->status, $login);
        }
        $this->assertSame('submitted', self::$db->value('SELECT status FROM requests WHERE id = ?', [$id]));
    }

    public function testReassignmentChecksConflictsAndNotifiesEveryone(): void
    {
        $id = $this->submitRequest('employee', '2026-10-15', '10:00', '12:00');
        $this->approve($id, 'car1', 'driver1');
        $blocker = $this->submitRequest('employee2', '2026-10-15', '14:00', '16:00');
        $this->approve($blocker, 'car2', 'driver2');

        // Перенос на время, когда car2 и driver2 заняты, — отказ, старое назначение остаётся.
        $this->assertDomainError(fn () => $this->app->requests->reassign($this->user('dispatcher'), $id, $this->vehicles['car2'], $this->drivers['driver2'], '2026-10-15 15:00:00', '2026-10-15 17:00:00'));
        $this->assertSame(1, $this->count("SELECT COUNT(*) FROM assignments WHERE request_id = ? AND status = 'assigned'", [$id]));

        // Смена водителя на свободное время.
        $this->app->requests->reassign($this->user('dispatcher'), $id, $this->vehicles['car1'], $this->drivers['driver2']);
        $this->assertSame(1, $this->count("SELECT COUNT(*) FROM assignments WHERE request_id = ? AND status = 'cancelled'", [$id]));
        $current = self::$db->one("SELECT * FROM assignments WHERE request_id = ? AND status = 'assigned'", [$id]);
        $this->assertSame($this->drivers['driver2'], (int) $current['driver_id']);
        $this->assertSame(1, $this->count("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'assignment_changed'", [$this->users['employee']]));
        $this->assertSame(1, $this->count("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'assignment_changed'", [$this->users['driver2']]));
        $this->assertSame(1, $this->count("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'assignment_cancelled'", [$this->users['driver1']]));

        // Перенос времени на той же машине не конфликтует с самой собой.
        $this->app->requests->reassign($this->user('dispatcher'), $id, $this->vehicles['car1'], $this->drivers['driver2'], '2026-10-15 11:00:00', '2026-10-15 13:00:00');
        $this->assertSame('2026-10-15 11:00:00', self::$db->value('SELECT starts_at FROM requests WHERE id = ?', [$id]));
    }

    public function testApprovalRejectionAndReassignmentAreAudited(): void
    {
        $a = $this->submitRequest('employee', '2026-10-15', '10:00', '12:00');
        $b = $this->submitRequest('employee2', '2026-10-16', '10:00', '12:00');
        $this->app->requests->takeForReview($this->user('dispatcher'), $a);
        $this->approve($a);
        $this->app->requests->reject($this->user('dispatcher'), $b, 'Дублирует другую заявку');
        $this->app->requests->reassign($this->user('dispatcher'), $a, $this->vehicles['car2'], $this->drivers['driver1']);

        $actions = array_column(self::$db->all("SELECT action FROM audit_log WHERE entity_type = 'request' ORDER BY id"), 'action');
        foreach (['submit', 'take_for_review', 'approve', 'reject', 'reassign'] as $expected) {
            $this->assertTrue(in_array($expected, $actions, true), "В журнале нет действия $expected");
        }
        $approve = self::$db->one("SELECT * FROM audit_log WHERE action = 'approve'");
        $this->assertSame($this->users['dispatcher'], (int) $approve['actor_user_id']);
        $this->assertContains('"vehicle_id":' . $this->vehicles['car1'], (string) $approve['after_json']);
        $reassign = self::$db->one("SELECT * FROM audit_log WHERE action = 'reassign'");
        $this->assertContains('"vehicle_id":' . $this->vehicles['car1'], (string) $reassign['before_json']);
        $this->assertContains('"vehicle_id":' . $this->vehicles['car2'], (string) $reassign['after_json']);

        $page = $this->client('dispatcher')->get('/dispatch/audit');
        $this->assertSame(200, $page->status);
        $this->assertContains('reassign', $page->body);
        $this->assertSame(403, $this->client('employee')->get('/dispatch/audit')->status);
    }
}
