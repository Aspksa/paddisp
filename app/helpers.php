<?php

declare(strict_types=1);

/** Экранирование для HTML. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Дата-время из БД в формате «дд.мм.гггг чч:мм». */
function fmt_dt(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    return (new DateTimeImmutable($value))->format('d.m.Y H:i');
}

function fmt_time(?string $value): string
{
    return $value ? (new DateTimeImmutable($value))->format('H:i') : '—';
}

/** Интервал поездки; дата окончания показывается, только если отличается. */
function fmt_range(string $start, string $end): string
{
    $s = new DateTimeImmutable($start);
    $f = new DateTimeImmutable($end);
    if ($s->format('Y-m-d') === $f->format('Y-m-d')) {
        return $s->format('d.m.Y H:i') . '–' . $f->format('H:i');
    }
    return $s->format('d.m.Y H:i') . ' – ' . $f->format('d.m.Y H:i');
}

function request_status_badge(string $status): string
{
    $label = App\Services\RequestService::STATUS_LABELS[$status] ?? $status;
    return '<span class="badge badge-' . e($status) . '">' . e($label) . '</span>';
}

function trip_status_badge(string $status): string
{
    $label = App\Services\TripService::STATUS_LABELS[$status] ?? $status;
    return '<span class="badge badge-trip-' . e($status) . '">' . e($label) . '</span>';
}

/** Скрытое поле CSRF для POST-форм. */
function csrf_field(string $token): string
{
    return '<input type="hidden" name="_csrf" value="' . e($token) . '">';
}
