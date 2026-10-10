<?php

declare(strict_types=1);

namespace Tests;

use App\Core\ValidationException;

final class RequestFlowTest extends TestCase
{
    public function testEmployeeSubmitsFutureRequestViaHttp(): void
    {
        $client = $this->client('employee');
        $this->assertSame(200, $client->get('/requests/new')->status);
        $r = $client->post('/requests', $this->requestInput('2027-03-01', '09:30', '17:00') + ['action' => 'submit']);
        $id = (int) self::$db->value('SELECT MAX(id) FROM requests');
        $this->assertRedirect($r, "/requests/$id");
        $row = self::$db->one('SELECT * FROM requests WHERE id = ?', [$id]);
        $this->assertSame('submitted', $row['status']);
        $this->assertSame('2027-03-01 09:30:00', $row['starts_at']);
        $this->assertSame($this->users['employee'], (int) $row['requester_id']);
        $this->assertSame(200, $client->get("/requests/$id")->status);
    }

    public function testSubmittingDoesNotReserveVehicle(): void
    {
        $id = $this->submitRequest('employee', '2026-10-15', '10:00', '12:00', ['preferred_vehicle_id' => (string) $this->vehicles['car1']]);
        $this->assertSame('submitted', self::$db->value('SELECT status FROM requests WHERE id = ?', [$id]));
        $this->assertSame(0, $this->count('SELECT COUNT(*) FROM assignments'));
        // Машина остаётся доступной для другой заявки на то же время.
        $other = $this->submitRequest('employee2', '2026-10-15', '10:00', '12:00');
        $this->approve($other, 'car1');
        $this->assertSame('approved', self::$db->value('SELECT status FROM requests WHERE id = ?', [$other]));
    }

    public function testDispatchersAreNotifiedAboutNewRequest(): void
    {
        $id = $this->submitRequest();
        $this->assertSame(1, $this->count("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'request_submitted'", [$this->users['dispatcher']]));
        $this->assertSame(0, $this->count('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$this->users['employee2']]));
        $this->assertTrue($id > 0);
    }

    public function testPastAndInvertedTimesAreRejected(): void
    {
        $e = $this->assertThrows(ValidationException::class, fn () => $this->submitRequest('employee', '2026-10-09', '10:00', '12:00'));
        $this->assertContains('прошедшее', implode(' ', $e->errors));
        $this->assertThrows(ValidationException::class, fn () => $this->submitRequest('employee', '2026-10-10', '08:00', '10:00'), 'прошедшее');
        $this->assertThrows(ValidationException::class, fn () => $this->submitRequest('employee', '2026-10-15', '12:00', '10:00'), 'позже времени начала');
        $this->assertThrows(ValidationException::class, fn () => $this->submitRequest('employee', '2026-10-15', '12:00', '12:00'), 'позже времени начала');
        $this->assertThrows(ValidationException::class, fn () => $this->submitRequest('employee', '2026-02-30', '10:00', '12:00'));
        $this->assertSame(0, $this->count('SELECT COUNT(*) FROM requests'));
    }

    public function testRequiredFieldsAreValidated(): void
    {
        $r = $this->client('employee')->post('/requests', ['requested_date' => '2026-10-15', 'start_time' => '10:00', 'end_time' => '11:00']);
        $this->assertSame(422, $r->status);
        $this->assertContains('Укажите место подачи', $r->body);
        $this->assertContains('Укажите цель поездки', $r->body);
        $this->assertSame(0, $this->count('SELECT COUNT(*) FROM requests'));
    }

    public function testOvernightTripIsSupported(): void
    {
        $id = $this->submitRequest('employee', '2026-10-15', '22:00', '06:00', ['end_date' => '2026-10-16']);
        $row = self::$db->one('SELECT starts_at, ends_at FROM requests WHERE id = ?', [$id]);
        $this->assertSame('2026-10-16 06:00:00', $row['ends_at']);
    }

    public function testDraftCanBeEditedOnlyByOwner(): void
    {
        $id = $this->app->requests->create($this->user('employee'), $this->requestInput(), false)['id'];
        $this->assertSame('draft', self::$db->value('SELECT status FROM requests WHERE id = ?', [$id]));
        $this->assertSame(0, $this->count("SELECT COUNT(*) FROM notifications WHERE event_type = 'request_submitted'"));

        $other = $this->client('employee2');
        $this->assertSame(404, $other->get("/requests/$id")->status);
        $this->assertSame(404, $other->get("/requests/$id/edit")->status);
        $this->assertSame(404, $other->post("/requests/$id", $this->requestInput() + ['destination' => 'Взлом'])->status);
        $this->assertSame('Объект №3', self::$db->value('SELECT destination FROM requests WHERE id = ?', [$id]));

        $owner = $this->client('employee');
        $this->assertRedirect($owner->post("/requests/$id", $this->requestInput(extra: ['destination' => 'Порт']) + ['action' => 'draft']), "/requests/$id");
        $this->assertSame('Порт', self::$db->value('SELECT destination FROM requests WHERE id = ?', [$id]));
        $owner->post("/requests/$id/submit");
        $this->assertSame('submitted', self::$db->value('SELECT status FROM requests WHERE id = ?', [$id]));
        // После подачи редактировать нельзя.
        $this->assertSame(403, $owner->get("/requests/$id/edit")->status);
    }

    public function testPreferredBusyVehicleGivesWarningButRequestIsAccepted(): void
    {
        $this->approve($this->submitRequest('employee2'), 'car1');
        $result = $this->app->requests->create($this->user('employee'), $this->requestInput(extra: ['preferred_vehicle_id' => (string) $this->vehicles['car1']]), true);
        $this->assertSame('submitted', self::$db->value('SELECT status FROM requests WHERE id = ?', [$result['id']]));
        $this->assertSame(1, count($result['warnings']));
        $this->assertContains('недоступен', $result['warnings'][0]);
    }

    public function testEmployeeCanCancelPendingButNotApprovedRequest(): void
    {
        $pending = $this->submitRequest();
        $this->app->requests->cancelOwn($this->user('employee'), $pending);
        $this->assertSame('cancelled', self::$db->value('SELECT status FROM requests WHERE id = ?', [$pending]));

        $approved = $this->submitRequest();
        $this->approve($approved);
        $this->assertDomainError(fn () => $this->app->requests->cancelOwn($this->user('employee'), $approved), 'только диспетчер');
    }
}
