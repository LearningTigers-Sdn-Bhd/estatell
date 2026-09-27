<?php

namespace App\Enums;

/**
 * Display tone for statuses. Maps to the status tokens in `StatusBadge`.
 */
enum Tone: string
{
    case Neutral = 'neutral';
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Danger = 'danger';
}
