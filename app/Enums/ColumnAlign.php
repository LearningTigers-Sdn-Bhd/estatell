<?php

namespace App\Enums;

/**
 * The alignment of a report column. Numbers and money align right by default; a layout may override it.
 */
enum ColumnAlign: string
{
    case Left = 'left';
    case Center = 'center';
    case Right = 'right';
}
