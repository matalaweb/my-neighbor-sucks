<?php

namespace App\Support;

/**
 * Duration-weighted energy arithmetic for decibel levels (spec §11).
 *
 *   energy_sum     = Σ(t_i × 10^(L_i / 10))
 *   valid_duration = Σ(t_i)
 *   Leq            = 10 × log10(energy_sum / valid_duration)
 *
 * Decibels are never averaged arithmetically. Missing time contributes
 * neither energy nor duration.
 */
final class Decibels
{
    public static function energy(float $level, int $durationMs): float
    {
        return $durationMs * (10 ** ($level / 10));
    }

    public static function leq(?float $energySum, int $validDurationMs): ?float
    {
        if ($validDurationMs <= 0 || $energySum === null || $energySum <= 0.0) {
            return null;
        }

        return 10 * log10($energySum / $validDurationMs);
    }

    /**
     * @param  iterable<array{0: float, 1: int}>  $levelsWithDurations  [level, duration_ms] pairs
     */
    public static function leqOf(iterable $levelsWithDurations): ?float
    {
        $energy = 0.0;
        $duration = 0;

        foreach ($levelsWithDurations as [$level, $durationMs]) {
            $energy += self::energy($level, $durationMs);
            $duration += $durationMs;
        }

        return self::leq($energy, $duration);
    }

    public static function round(?float $level, int $precision = 1): ?float
    {
        return $level === null ? null : round($level, $precision);
    }
}
