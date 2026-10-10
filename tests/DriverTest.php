<?php

declare(strict_types=1);

namespace Tests;

final class DriverTest extends TestCase
{
    public function testDriverSeesOnlyOwnAssignments(): void
    {
        $mine = $this->submitRequest('employee', '2026-10-15', '10:00', '12:00', ['destination' => 'Мой объект']);
        $theirs = $this->submitRequest('employee2', '2026-10-15', '10:00', '12:00', ['destination' => 'Чужой объект']);
        $this->approve($mine, 'car1', 'driver1');
        $this->approve($theirs, 'car2', 'driver2');

        $page = $this->client('driver1')->get('/driver');
        $this->assertContains('Мой объект', $page->body);
        $this->assertTrue(!str_contains($page->body, 'Чужой объект'), 'Видна чужая поездка');
        $this->assertSame(404, $this->client('driver1')->get("/requests/$theirs")->status);
        $this->assertSame(200, $this->client('driver1')->get("/requests/$mine")->status);
    }

    public function testDriverCannotAcknowledgeOtherDriversAssignment(): void
    {
        $id = $this->submitRequest();
        $assignmentId = $this->approve($id, 'car1', 'driver2');

        $r = $this->client('driver1')->post("/driver/assignments/$assignmentId/ack");
        $this->assertRedirect($r, '/driver');
        $this->assertSame('assigned', self::$db->value('SELECT status FROM assignments WHERE id = ?', [$assignmentId]));
        $this->assertDomainError(fn () => $this->app->trips->acknowledge($this->users['driver1'], $assignmentId), 'не найдено');
        $this->assertDomainError(fn () => $this->app->trips->start($this->users['driver1'], $assignmentId), 'не найдено');
        // Сотрудник тоже не может.
        $this->assertSame(403, $this->client('employee')->post("/driver/assignments/$assignmentId/ack")->status);
    }

    public function testTripLifecycleCompletesRequest(): void
    {
        $id = $this->submitRequest();
        $assignmentId = $this->approve($id, 'car1', 'driver1');
        $client = $this->client('driver1');
        $client->post("/driver/assignments/$assignmentId/ack");
        $this->assertSame('acknowledged', self::$db->value('SELECT status FROM assignments WHERE id = ?', [$assignmentId]));
        $client->post("/driver/assignments/$assignmentId/start");
        $this->assertSame('in_progress', self::$db->value('SELECT status FROM assignments WHERE id = ?', [$assignmentId]));
        // Начатую поездку нельзя переназначить.
        $this->assertDomainError(fn () => $this->app->requests->reassign($this->user('dispatcher'), $id, $this->vehicles['car2'], $this->drivers['driver2']), 'уже началась');
        $client->post("/driver/assignments/$assignmentId/finish");
        $this->assertSame('finished', self::$db->value('SELECT status FROM assignments WHERE id = ?', [$assignmentId]));
        $this->assertSame('completed', self::$db->value('SELECT status FROM requests WHERE id = ?', [$id]));
        $this->assertSame(1, $this->count("SELECT COUNT(*) FROM audit_log WHERE action = 'complete' AND actor_user_id IS NULL"));
        $this->assertSame(1, $this->count("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'trip_finished'", [$this->users['employee']]));
    }
}
