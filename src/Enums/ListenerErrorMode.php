<?php

declare(strict_types=1);

namespace Laraflow\Enums;

enum ListenerErrorMode: string
{
    case Throw = 'throw';
    case Collect = 'collect';
    case Swallow = 'swallow';
}
