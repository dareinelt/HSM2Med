<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Import\ImportService;
use App\Import\MerlinParser;
use App\Mapping\ParameterMapping;
use App\Report\ReportData;
use App\Report\ReportSummaryBuilder;

/**
 * Erzeugt ReportData ohne Datenbank (gleiche Struktur wie ReportService::load()).
 */
final class ReportDataFactory
{
    public static function mapping(): ParameterMapping
    {
        return new ParameterMapping(require dirname(__DIR__, 2) . '/config/parameter_mapping.php');
    }

    public static function fromText(string $text, int $reportId = 1, string $filename = 'test.log'): ReportData
    {
        $mapping = self::mapping();
        $result = (new MerlinParser())->parse($text);
        $builder = new ReportSummaryBuilder($mapping);
        $summary = $builder->build($result->records);

        $parameters = [];
        foreach ($result->records as $record) {
            $assignment = $mapping->resolve($record->parameterId, $record->name);
            $parameters[] = [
                'parameter_id' => $record->parameterId,
                'parameter_name' => $record->name,
                'display_name' => $assignment->displayName,
                'value' => $record->value,
                'unit' => $record->unit,
                'category' => $assignment->key,
                'category_label' => $assignment->label,
                'category_sort' => $assignment->sort,
                'mapping_source' => $assignment->source,
                'original_position' => $record->position,
                'raw_record' => $record->rawRecord,
            ];
        }
        $issues = array_map(static fn ($issue): array => [
            'severity' => $issue->severity,
            'error_code' => $issue->code,
            'message' => $issue->message,
            'record_position' => $issue->position,
            'raw_record' => $issue->rawRecord,
        ], $result->issues);
        $errors = count(array_filter($result->issues, static fn ($i) => $i->isError()));

        $report = [
            'id' => $reportId,
            'import_id' => $reportId,
            'device_id' => null,
            'session_timestamp' => '2026-10-07 07:03:24',
            'interrogation_timestamp' => null,
            'report_version' => ImportService::REPORT_VERSION,
            'parser_version' => MerlinParser::VERSION,
            'mapping_version' => $mapping->version(),
            'patient_name_snapshot' => $summary->nonEmpty('patient_name'),
            'patient_identifier_snapshot' => $summary->nonEmpty('patient_identifier'),
            'device_model_name_snapshot' => $summary->nonEmpty('device_model_name'),
            'device_serial_snapshot' => $summary->nonEmpty('device_serial'),
            'parameter_count' => count($parameters),
            'created_at' => '2026-10-07 08:00:00',
        ];
        $import = [
            'id' => $reportId,
            'filename' => $filename,
            'file_hash' => hash('sha256', $text),
            'file_size' => strlen($text),
            'encoding' => $result->encoding,
            'imported_at' => '2026-10-07 08:00:00',
            'record_count' => $result->recordCount,
            'valid_record_count' => count($parameters),
            'error_count' => $errors,
            'warning_count' => count($result->issues) - $errors,
            'status' => $errors > 0 ? 'completed_with_errors' : 'completed',
        ];
        $snapshot = json_decode(json_encode($builder->toSnapshot($summary), JSON_THROW_ON_ERROR), true);
        return new ReportData($report, $import, $snapshot, $parameters, $issues);
    }
}
