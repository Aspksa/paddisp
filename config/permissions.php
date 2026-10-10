<?php

declare(strict_types=1);

// Права ролей из спецификации (roles.*.permissions).
// view_fleet и view_schedule добавлены всем ролям: автопарк видят все авторизованные сотрудники.
return [
    'employee' => [
        'view_fleet', 'view_schedule', 'create_own_request', 'view_own_requests', 'view_own_notifications',
    ],
    'dispatcher' => [
        'view_fleet', 'view_schedule', 'view_own_notifications',
        'view_all_requests', 'assign_vehicle', 'assign_driver', 'approve_request', 'reject_request',
        'reschedule_request', 'manage_vehicle_status', 'view_conflicts', 'view_audit_log',
    ],
    'driver' => [
        'view_fleet', 'view_schedule', 'view_own_assignments', 'view_own_notifications',
        'acknowledge_assignment', 'start_own_trip', 'finish_own_trip',
    ],
    'admin' => [
        'view_fleet', 'view_schedule', 'view_own_notifications',
        'manage_users', 'manage_roles', 'manage_departments', 'manage_vehicles', 'manage_drivers',
        'configure_integrations', 'view_audit_log',
    ],
];
