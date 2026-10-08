<?php

declare(strict_types=1);

namespace App\Patient;

use App\Support\Clock;
use App\Support\DateInput;

/**
 * Ablauf der Aktenbausteine (Anamnese, Vormedikation, Epikrise, Notiz, Schrittmacher-/ICD-Abfrage).
 *
 * Regeln:
 *  * Ein Baustein ist eine Kette unveraenderlicher Fassungen. Speichern erzeugt eine neue
 *    Fassung; fruehere Fassungen bleiben unveraendert erhalten und sind einsehbar.
 *  * Ein inhaltsgleicher Speichervorgang erzeugt keine neue Fassung.
 *  * Ein Baustein wird nie leer gespeichert.
 *  * Fassungen tragen die freie Angabe "erfasst von" (die Anwendung kennt keine Benutzer).
 *  * Die Abfrage richtet sich nach config/device_check_template.php; der Geraetetyp bestimmt
 *    die zulaessigen Abschnitte und Felder.
 */
final class PatientRecordService
{
    public function __construct(
        private readonly PatientRecordRepository $repository,
        private readonly PatientRepository $patients,
        private readonly Clock $clock,
        private readonly DeviceCheckTemplate $deviceCheck,
    ) {
    }

    /**
     * Vorlage des Bausteins "Schrittmacher-/ICD-Abfrage".
     */
    public function deviceCheckTemplate(): DeviceCheckTemplate
    {
        return $this->deviceCheck;
    }

    /**
     * Alle vorhandenen Bausteine eines Patienten mit ihrer aktuellen Fassung.
     *
     * @return array<string, array<string, mixed>> Bausteintyp => Anzeigedaten
     */
    public function overview(int $patientId): array
    {
        $result = [];
        foreach ($this->repository->currentRecords($patientId) as $typeValue => $row) {
            $type = PatientRecordType::fromValue($typeValue);
            if ($type === null) {
                continue;
            }
            $result[$type->value] = $this->present($type, $row);
        }
        return $result;
    }

    /**
     * Fassungshistorie eines Bausteins, neueste zuerst.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $patientId, PatientRecordType $type): array
    {
        $record = $this->repository->record($patientId, $type);
        if ($record === null) {
            return [];
        }
        $history = [];
        foreach ($this->repository->versions((int) $record['id']) as $version) {
            $history[] = $this->present($type, $version + ['version_count' => 1]);
        }
        return $history;
    }

    /**
     * Aktuelle Fassung eines Bausteins (fuer Formular und Vorschau).
     *
     * @return array<string, mixed>|null
     */
    public function current(int $patientId, PatientRecordType $type): ?array
    {
        $record = $this->repository->record($patientId, $type);
        if ($record === null) {
            return null;
        }
        $version = $this->repository->currentVersion((int) $record['id']);
        if ($version === null) {
            return null;
        }
        $version['version_count'] = count($this->repository->versions((int) $record['id']));
        return $this->present($type, $version);
    }

    /**
     * Speichert die Eingabe als neue Fassung.
     *
     * @param array<string, mixed> $post
     * @return array{version: int, unchanged: bool}
     */
    public function save(int $patientId, PatientRecordType $type, array $post): array
    {
        $input = PatientRecordInput::fromPost($type, $post, $this->deviceCheck);
        $content = $input->content();
        $json = (string) json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $result = $this->repository->appendVersion(
            $patientId,
            $type,
            $json,
            $input->contentText(),
            hash('sha256', $json),
            $input->authorName(),
            $this->now(),
        );

        return ['version' => $result['version'], 'unchanged' => $result['unchanged']];
    }

    /**
     * Anzeigedaten einer Fassung: Inhalt, Textfassung, Medikamentenzeilen oder Abfragewerte.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(PatientRecordType $type, array $row): array
    {
        $content = json_decode((string) ($row['content'] ?? ''), true);
        $content = is_array($content) ? $content : [];
        $entries = [];
        foreach ($content['entries'] ?? [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entries[] = $entry + array_fill_keys(array_keys(PatientRecordInput::ENTRY_FIELDS), '');
        }

        $text = $content['text'] ?? '';
        $text = is_string($text) ? trim($text) : '';

        $deviceCheck = null;
        if ($type->isDeviceCheck()) {
            $deviceCheck = DeviceCheckInput::describe($content, $this->deviceCheck);
            $rendered = DeviceCheckInput::renderText($content, $this->deviceCheck);
        } else {
            $rendered = PatientRecordInput::renderText($content);
        }

        return [
            'type' => $type->value,
            'label' => $type->label(),
            'structured' => $type->isStructured(),
            'device_check' => $deviceCheck,
            'record_id' => isset($row['record_id']) ? (int) $row['record_id'] : null,
            'version' => isset($row['version']) ? (int) $row['version'] : null,
            'version_count' => isset($row['version_count']) ? (int) $row['version_count'] : 1,
            'version_created_at' => $row['version_created_at'] ?? $row['created_at'] ?? null,
            'updated_at' => $row['record_updated_at'] ?? $row['version_created_at'] ?? null,
            'author_name' => $row['author_name'] ?? null,
            'text' => $text,
            'entries' => $entries,
            'lines' => $rendered === '' ? [] : explode("\n", $rendered),
            'empty' => $deviceCheck !== null
                ? $deviceCheck['filled'] === 0
                : ($text === '' && $entries === []),
        ];
    }

    /**
     * Textfassung eines Bausteins fuer die Vorschau im Brief (Phase 2).
     */
    public function text(int $patientId, PatientRecordType $type): string
    {
        $record = $this->repository->record($patientId, $type);
        if ($record === null) {
            return '';
        }
        $version = $this->repository->currentVersion((int) $record['id']);
        if ($version === null) {
            return '';
        }
        $stored = (string) ($version['content_text'] ?? '');
        if ($stored !== '') {
            return $stored;
        }
        $content = json_decode((string) ($version['content'] ?? ''), true);
        $content = is_array($content) ? $content : [];
        return $type->isDeviceCheck()
            ? DeviceCheckInput::renderText($content, $this->deviceCheck)
            : PatientRecordInput::renderText($content);
    }

    /**
     * Anzeige der Fassung: "Fassung 3, vom 07.10.2026, erfasst von Dr. Beispiel".
     *
     * @param array<string, mixed> $record
     */
    public static function versionLabel(array $record): string
    {
        $parts = [sprintf('Fassung %d', (int) ($record['version'] ?? 1))];
        $created = $record['version_created_at'] ?? null;
        if (is_string($created) && $created !== '') {
            $parts[] = 'vom ' . DateInput::format(substr($created, 0, 10));
        }
        $author = $record['author_name'] ?? null;
        if (is_string($author) && trim($author) !== '') {
            $parts[] = 'erfasst von ' . trim($author);
        }
        return implode(', ', $parts);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
