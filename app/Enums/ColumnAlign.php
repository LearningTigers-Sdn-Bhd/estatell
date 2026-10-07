<?php

namespace App\Enums;

/**
 * The alignment of a report column. Numbers and money align right.
 */
enum ColumnAlign: string
{
    case Left = 'left';
    case Right = 'right';
}
