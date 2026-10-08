<?php

namespace App\Services\Exports;

use Carbon\CarbonImmutable;

/**
 * Minimal server-side time-series chart rendered as SVG for PDF reports.
 * No remote resources. Missing intervals are drawn as gaps, never as zero
 * and never bridged by a line.
 */
class SvgChart
{
    /**
     * @param  list<array{label: string, color: string, points: list<array{0: int, 1: float|null}>, dashed?: bool}>  $series  points are [unix seconds, value|null]
     */
    public function render(
        array $series,
        int $fromTs,
        int $toTs,
        int $stepSeconds,
        string $timezone,
        string $yLabel,
        int $width = 720,
        int $height = 240,
        string $title = '',
        ?array $highlight = null,
    ): string {
        $left = 52;
        $right = 12;
        $top = $title === '' ? 12 : 30;
        $bottom = 48;
        $plotW = $width - $left - $right;
        $plotH = $height - $top - $bottom;

        $values = [];

        foreach ($series as $line) {
            foreach ($line['points'] as [, $value]) {
                if ($value !== null) {
                    $values[] = $value;
                }
            }
        }

        $min = $values === [] ? 30.0 : floor((min($values) - 2) / 10) * 10;
        $max = $values === [] ? 90.0 : ceil((max($values) + 2) / 10) * 10;

        if ($max - $min < 10) {
            $max = $min + 10;
        }

        $span = max(1, $toTs - $fromTs);
        $x = fn (int $ts): float => $left + ($ts - $fromTs) / $span * $plotW;
        $y = fn (float $value): float => $top + ($max - $value) / ($max - $min) * $plotH;

        $svg = [];
        $svg[] = sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d">', $width, $height, $width, $height);
        $svg[] = sprintf('<rect x="0" y="0" width="%d" height="%d" fill="#ffffff"/>', $width, $height);

        if ($title !== '') {
            $svg[] = sprintf('<text x="%d" y="18" font-family="Helvetica" font-size="12" font-weight="bold" fill="#111827">%s</text>', $left, $this->e($title));
        }

        $gridStep = ($max - $min) > 40 ? 20 : 10;

        for ($value = $min; $value <= $max + 0.001; $value += $gridStep) {
            $py = $y($value);
            $svg[] = sprintf('<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" stroke="#e5e7eb" stroke-width="1"/>', $left, $py, $left + $plotW, $py);
            $svg[] = sprintf('<text x="%d" y="%.1f" font-family="Helvetica" font-size="9" fill="#374151" text-anchor="end">%s</text>', $left - 4, $py + 3, (int) $value);
        }

        if ($highlight !== null) {
            $hx1 = max($left, $x(max($fromTs, $highlight[0])));
            $hx2 = min($left + $plotW, $x(min($toTs, $highlight[1])));

            if ($hx2 > $hx1) {
                $svg[] = sprintf('<rect x="%.1f" y="%d" width="%.1f" height="%d" fill="#fef3c7"/>', $hx1, $top, $hx2 - $hx1, $plotH);
            }
        }

        $svg[] = sprintf('<text x="10" y="%d" font-family="Helvetica" font-size="9" fill="#374151" transform="rotate(-90 10 %d)">%s</text>', $top + $plotH / 2 + 20, $top + $plotH / 2 + 20, $this->e($yLabel));
        $svg[] = sprintf('<rect x="%d" y="%d" width="%d" height="%d" fill="none" stroke="#9ca3af" stroke-width="1"/>', $left, $top, $plotW, $plotH);

        foreach ($this->ticks($fromTs, $toTs) as $tick) {
            $px = $x($tick);
            $label = CarbonImmutable::createFromTimestampUTC($tick)->setTimezone($timezone)->format($span > 2 * 86400 ? 'M j' : ($span > 3600 * 6 ? 'H:i' : 'H:i:s'));
            $svg[] = sprintf('<line x1="%.1f" y1="%d" x2="%.1f" y2="%d" stroke="#9ca3af" stroke-width="1"/>', $px, $top + $plotH, $px, $top + $plotH + 4);
            $svg[] = sprintf('<text x="%.1f" y="%d" font-family="Helvetica" font-size="9" fill="#374151" text-anchor="middle">%s</text>', $px, $top + $plotH + 16, $this->e($label));
        }

        foreach ($series as $line) {
            foreach ($this->segments($line['points'], $stepSeconds) as $segment) {
                if (count($segment) === 1) {
                    [$ts, $value] = $segment[0];
                    $svg[] = sprintf('<circle cx="%.1f" cy="%.1f" r="1.5" fill="%s"/>', $x($ts), $y($value), $line['color']);

                    continue;
                }

                $path = [];

                foreach ($segment as $index => [$ts, $value]) {
                    $path[] = sprintf('%s%.1f %.1f', $index === 0 ? 'M' : 'L', $x($ts), $y($value));
                }

                $svg[] = sprintf('<path d="%s" fill="none" stroke="%s" stroke-width="1.2"%s/>', implode(' ', $path), $line['color'], ($line['dashed'] ?? false) ? ' stroke-dasharray="3 2"' : '');
            }
        }

        $legendX = $left;

        foreach ($series as $line) {
            $svg[] = sprintf('<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="2"/>', $legendX, $height - 12, $legendX + 14, $height - 12, $line['color']);
            $svg[] = sprintf('<text x="%d" y="%d" font-family="Helvetica" font-size="9" fill="#111827">%s</text>', $legendX + 18, $height - 9, $this->e($line['label']));
            $legendX += 30 + (int) (mb_strlen($line['label']) * 6.0);
        }

        $svg[] = sprintf('<text x="%d" y="%d" font-family="Helvetica" font-size="8" fill="#6b7280" text-anchor="end">Gaps = no valid data (not silence). Times: %s</text>', $left + $plotW, max(9, $top - 4), $this->e($timezone));
        $svg[] = '</svg>';

        return implode("\n", $svg);
    }

    public function dataUri(string $svg): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Split points into contiguous runs; a null value or a time jump larger
     * than 1.5 steps starts a new run.
     *
     * @param  list<array{0: int, 1: float|null}>  $points
     * @return list<list<array{0: int, 1: float}>>
     */
    public function segments(array $points, int $stepSeconds): array
    {
        usort($points, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $segments = [];
        $current = [];
        $previous = null;

        foreach ($points as [$ts, $value]) {
            if ($value === null || ($previous !== null && $ts - $previous > $stepSeconds * 1.5)) {
                if ($current !== []) {
                    $segments[] = $current;
                }

                $current = [];
            }

            if ($value !== null) {
                $current[] = [$ts, (float) $value];
                $previous = $ts;
            } else {
                $previous = null;
            }
        }

        if ($current !== []) {
            $segments[] = $current;
        }

        return $segments;
    }

    /**
     * @return list<int>
     */
    private function ticks(int $from, int $to): array
    {
        $span = max(1, $to - $from);
        $candidates = [1, 5, 10, 15, 30, 60, 120, 300, 600, 900, 1800, 3600, 7200, 10800, 21600, 43200, 86400, 172800, 604800];
        $step = 604800;

        foreach ($candidates as $candidate) {
            if ($span / $candidate <= 8) {
                $step = $candidate;

                break;
            }
        }

        $ticks = [];

        for ($tick = (int) (ceil($from / $step) * $step); $tick <= $to; $tick += $step) {
            $ticks[] = $tick;
        }

        return $ticks;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
