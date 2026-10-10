<?php

declare(strict_types=1);

namespace Tests;

final class FleetTest extends TestCase
{
    public function testEmployeeSeesAllVehicleStatesButCannotEditThem(): void
    {
        self::$db->update('vehicles', $this->vehicles['car2'], ['status' => 'repair']);
        // Поездка, которая идёт прямо сейчас (09:00), делает машину «занятой».
        $busy = self::$db->insert('requests', [
            'requester_id' => $this->users['employee2'], 'department_id' => $this->department, 'requested_date' => '2026-10-10',
            'starts_at' => '2026-10-10 08:30:00', 'ends_at' => '2026-10-10 10:00:00', 'pickup_location' => 'A', 'destination' => 'B',
            'purpose' => 'x', 'status' => 'approved', 'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
        self::$db->insert('assignments', [
            'request_id' => $busy, 'vehicle_id' => $this->vehicles['car1'], 'driver_id' => $this->drivers['driver1'],
            'starts_at' => '2026-10-10 08:30:00', 'ends_at' => '2026-10-10 10:00:00', 'status' => 'in_progress',
            'assigned_by' => $this->users['dispatcher'], 'assigned_at' => self::NOW,
        ]);

        $page = $this->client('employee')->get('/fleet');
        $this->assertSame(200, $page->status);
        $this->assertContains('А001АА125', $page->body);
        $this->assertContains('В002ВВ125', $page->body);
        $this->assertContains('Занят', $page->body);
        $this->assertContains('В ремонте', $page->body);
        $this->assertTrue(!str_contains($page->body, 'Статус и ремонты'), 'Сотруднику не показываются ссылки управления');

        $r = $this->client('employee')->post('/dispatch/vehicles/' . $this->vehicles['car1'] . '/status', ['status' => 'repair']);
        $this->assertSame(403, $r->status);
        $this->assertSame('in_service', self::$db->value('SELECT status FROM vehicles WHERE id = ?', [$this->vehicles['car1']]));
    }

    public function testDispatcherCanChangeVehicleStatus(): void
    {
        $r = $this->client('dispatcher')->post('/dispatch/vehicles/' . $this->vehicles['car1'] . '/status', ['status' => 'repair']);
        $this->assertRedirect($r, '/dispatch/vehicles/' . $this->vehicles['car1']);
        $this->assertSame('repair', self::$db->value('SELECT status FROM vehicles WHERE id = ?', [$this->vehicles['car1']]));
        $this->assertSame(1, $this->count("SELECT COUNT(*) FROM audit_log WHERE entity_type = 'vehicle' AND action = 'set_status'"));
    }

    public function testMaintenanceCannotOverlapApprovedTrips(): void
    {
        $this->approve($this->submitRequest());
        $this->assertDomainError(
            fn () => $this->app->fleet->addMaintenance($this->users['dispatcher'], $this->vehicles['car1'], '2026-10-15T11:00', '2026-10-16T09:00', 'ТО'),
            'Сначала переназначьте'
        );
        $this->app->fleet->addMaintenance($this->users['dispatcher'], $this->vehicles['car1'], '2026-10-15T12:00', '2026-10-16T09:00', 'ТО');
        $this->assertSame(1, $this->count('SELECT COUNT(*) FROM maintenance_periods'));
    }
}
