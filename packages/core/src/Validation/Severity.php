<?php

declare(strict_types=1);

namespace Quittio\Core\Validation;

enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';
}
