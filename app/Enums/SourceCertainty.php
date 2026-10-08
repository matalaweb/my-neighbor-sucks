<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SourceCertainty: string implements HasLabel
{
    case Observed = 'observed';
    case Suspected = 'suspected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Observed => 'Observed',
            self::Suspected => 'Suspected',
        };
    }
}
