<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ExportKind: string implements HasLabel
{
    case PdfSummary = 'pdf_summary';
    case CsvMeasurements = 'csv_measurements';
    case EvidenceBundle = 'evidence_bundle';

    public function getLabel(): string
    {
        return match ($this) {
            self::PdfSummary => 'PDF summary',
            self::CsvMeasurements => 'CSV measurements',
            self::EvidenceBundle => 'Evidence bundle (ZIP)',
        };
    }
}
