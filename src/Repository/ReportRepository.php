<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Lesezugriffe auf Berichte. Alle Abfragen nutzen Prepared Statements.
 */
final class ReportRepository
{
    public const array FILTER_KEYS = ['q', 'patient', 'patient_id', 'serial', 'model', 'filename', 'date_from', 'date_to'];

    private const string SORT_EXPRESSION = 'COALESCE(r.session_timestamp, r.interrogation_timestamp, r.created_at)';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, string> $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function search(array $filters, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $from = ' FROM reports r JOIN imports i ON i.id = r.import_id' . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '');

        $count = $this->pdo->prepare('SELECT COUNT(*)' . $from);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $sql = 'SELECT r.id, r.import_id, r.session_timestamp, r.session_timestamp_raw, r.interrogation_timestamp,'
            . ' r.interrogation_timestamp_raw, r.patient_name_snapshot, r.patient_identifier_snapshot, r.patient_dob_snapshot,'
            . ' r.device_manufacturer_snapshot, r.device_model_name_snapshot, r.device_model_number_snapshot,'
            . ' r.device_serial_snapshot, r.parameter_count, r.created_at, r.report_version,'
            . ' i.filename, i.status, i.error_count, i.warning_count, i.imported_at'
            . $from
            . ' ORDER BY ' . self::SORT_EXPRESSION . ' DESC, r.id DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM reports WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function findIdByImport(int $importId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM reports WHERE import_id = ?');
        $stmt->execute([$importId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parameters(int $reportId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT parameter_id, parameter_name, display_name, value, unit, category, category_label, category_sort,'
            . ' mapping_source, original_position, raw_record'
            . ' FROM report_parameters WHERE report_id = ? ORDER BY original_position'
        );
        $stmt->execute([$reportId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Weitere Berichte desselben Geraets (Verlauf).
     *
     * @return list<array<string, mixed>>
     */
    public function relatedByDevice(int $deviceId, int $excludeReportId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.session_timestamp, r.session_timestamp_raw, r.created_at, i.filename FROM reports r'
            . ' JOIN imports i ON i.id = r.import_id WHERE r.device_id = :device AND r.id <> :exclude'
            . ' ORDER BY ' . self::SORT_EXPRESSION . ' DESC, r.id DESC LIMIT :limit'
        );
        $stmt->bindValue(':device', $deviceId, PDO::PARAM_INT);
        $stmt->bindValue(':exclude', $excludeReportId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array{reports: int, patients: int, devices: int, imports: int, failed_imports: int, parameters: int}
     */
    public function statistics(): array
    {
        $row = $this->pdo->query(
            'SELECT (SELECT COUNT(*) FROM reports) AS reports, (SELECT COUNT(*) FROM patients) AS patients,'
            . ' (SELECT COUNT(*) FROM devices) AS devices, (SELECT COUNT(*) FROM imports) AS imports,'
            . " (SELECT COUNT(*) FROM imports WHERE status = 'failed') AS failed_imports,"
            . ' (SELECT COUNT(*) FROM report_parameters) AS parameters'
        )->fetch(PDO::FETCH_ASSOC);
        return array_map('intval', $row);
    }

    /**
     * @param array<string, string> $filters
     * @return array{0: list<string>, 1: array<string, string>}
     */
    private function buildWhere(array $filters): array
    {
        $where = [];
        $params = [];
        $like = static fn (string $value): string => '%' . addcslashes($value, '%_\\') . '%';

        $q = trim($filters['q'] ?? '');
        if ($q !== '') {
            $where[] = '(r.patient_name_snapshot LIKE :q1 OR r.patient_identifier_snapshot LIKE :q2'
                . ' OR r.device_serial_snapshot LIKE :q3 OR r.device_model_name_snapshot LIKE :q4'
                . ' OR r.device_model_number_snapshot LIKE :q5 OR i.filename LIKE :q6)';
            for ($n = 1; $n <= 6; $n++) {
                $params[':q' . $n] = $like($q);
            }
        }
        $map = [
            'patient' => 'r.patient_name_snapshot',
            'patient_id' => 'r.patient_identifier_snapshot',
            'serial' => 'r.device_serial_snapshot',
            'filename' => 'i.filename',
        ];
        foreach ($map as $key => $column) {
            $value = trim($filters[$key] ?? '');
            if ($value !== '') {
                $where[] = $column . ' LIKE :' . $key;
                $params[':' . $key] = $like($value);
            }
        }
        $model = trim($filters['model'] ?? '');
        if ($model !== '') {
            $where[] = '(r.device_model_name_snapshot LIKE :model1 OR r.device_model_number_snapshot LIKE :model2)';
            $params[':model1'] = $like($model);
            $params[':model2'] = $like($model);
        }
        $from = self::validDate($filters['date_from'] ?? '');
        if ($from !== null) {
            $where[] = self::SORT_EXPRESSION . ' >= :date_from';
            $params[':date_from'] = $from . ' 00:00:00';
        }
        $to = self::validDate($filters['date_to'] ?? '');
        if ($to !== null) {
            $where[] = self::SORT_EXPRESSION . ' <= :date_to';
            $params[':date_to'] = $to . ' 23:59:59';
        }
        return [$where, $params];
    }

    public static function validDate(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return $value;
    }
}
