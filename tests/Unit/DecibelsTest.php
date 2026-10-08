<?php

use App\Support\Decibels;

it('energy-averages equal durations at 40 and 60 dBA to about 57.03 dBA', function (): void {
    expect(Decibels::leqOf([[40.0, 1000], [60.0, 1000]]))->toEqualWithDelta(57.0329, 0.0001);
});

it('weights one second at 40 and three seconds at 60 to about 58.77 dBA', function (): void {
    expect(Decibels::leqOf([[40.0, 1000], [60.0, 3000]]))->toEqualWithDelta(58.7651, 0.0001);
});

it('returns null when no valid duration exists', function (): void {
    expect(Decibels::leq(null, 0))->toBeNull()->and(Decibels::leqOf([]))->toBeNull();
});

it('combines partial energy sums exactly like the raw intervals', function (): void {
    $minuteA = Decibels::energy(40.0, 1000) + Decibels::energy(60.0, 1000);
    $minuteB = Decibels::energy(60.0, 2000);

    expect(Decibels::leq($minuteA + $minuteB, 4000))->toEqualWithDelta(58.7651, 0.0001);
});
