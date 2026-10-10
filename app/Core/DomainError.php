<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Нарушение бизнес-правила (конфликт расписания, недопустимый переход статуса и т. п.). */
final class DomainError extends RuntimeException
{
}
