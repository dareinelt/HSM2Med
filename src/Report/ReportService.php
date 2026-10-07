<?php

declare(strict_types=1);

namespace App\Report;

use App\Repository\ImportRepository;
use App\Repository\ReportRepository;
use App\Security\FileName;
use DateTimeImmutable;
use JsonException;
use RuntimeException;

/**
 * Laedt Berichts-Snapshots aus der Datenbank und erzeugt daraus PDFs.
 * Die Originaldatei bzw. das Import-Archiv wird dafuer nicht benoetigt.
 */
final class ReportService
{
    public function __construct(
        private readonly ReportRepository $reports,
        private readonly ImportRepository $imports,
        private readonly PdfGenerator $pdfGenerator = new PdfGenerator(),
    ) {
    }

    public function load(int $reportId): ?ReportData
    {
        $report = $this->reports->find($reportId);
        if ($report === null) {
            return null;
        }
        $import = $this->imports->find((int) $report['import_id']);
        if ($import === null) {
            throw new RuntimeException('Import zum Bericht fehlt (Datenintegritaet verletzt).');
        }
        try {
            $summary = json_decode((string) $report['summary_snapshot'], true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Zusammenfassungs-Snapshot des Berichts ist nicht lesbar.', 0, $e);
        }
        $parameters = $this->reports->parameters($reportId);
        if (count($parameters) !== (int) $report['parameter_count']) {
            throw new RuntimeException(sprintf(
                'Bericht %d ist unvollstaendig: %d von %d Parametern vorhanden.',
                $reportId,
                count($parameters),
                $report['parameter_count'],
            ));
        }
        return new ReportData($report, $import, is_array($summary) ? $summary : [], $parameters, $this->imports->issues((int) $import['id']));
    }

    public function renderPdf(ReportData $data, bool $includeRawAppendix, DateTimeImmutable $generatedAt): string
    {
        return match ($data->reportVersion()) {
            PdfGenerator::SUPPORTED_REPORT_VERSION => $this->pdfGenerator->generate($data, $includeRawAppendix, $generatedAt),
            default => throw new RuntimeException(sprintf('Keine PDF-Vorlage fuer Berichtsversion %d vorhanden.', $data->reportVersion())),
        };
    }

    public static function pdfFilename(ReportData $data): string
    {
        $r = $data->report;
        $timestamp = $r['session_timestamp'] ?? $r['interrogation_timestamp'] ?? null;
        $date = $timestamp !== null ? substr((string) $timestamp, 0, 10) : substr((string) $r['created_at'], 0, 10);
        $parts = ['Bericht', (string) $r['id'], $date];
        if (($r['device_serial_snapshot'] ?? '') !== '') {
            $parts[] = 'SN' . $r['device_serial_snapshot'];
        }
        return FileName::downloadName(implode('_', $parts) . '.pdf');
    }
}
