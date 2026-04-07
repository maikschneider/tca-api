<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Enum;

enum AccessRole: string
{
    case PUBLIC = 'PUBLIC';
    case FE_USER = 'FE_USER';
    case BE_USER = 'BE_USER';
    case BE_ADMIN = 'BE_ADMIN';
}
