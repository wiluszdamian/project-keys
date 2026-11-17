<?php

namespace App\Enums;

enum ScrapeRunStatus: string
{
    case Running = 'running';
    case Ok = 'ok';
    case Blocked = 'blocked';
    case Error = 'error';
}
