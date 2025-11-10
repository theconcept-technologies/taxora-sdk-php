<?php
declare(strict_types=1);

namespace Taxora\Sdk\Enums;

enum LoginIdentifier: string
{
    case EMAIL = 'email';
    case CLIENT_ID = 'client_id';
}
