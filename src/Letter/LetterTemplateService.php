<?php

declare(strict_types=1);

namespace App\Letter;

use App\Support\Clock;
use PDOException;

/**
 * Versionierte Briefvorlage: aktuelle Fassung lesen, neue Fassung speichern, Verlauf zeigen.
 *
 * Jede Speicherung erzeugt eine neue, unveraenderliche Fassung. Gibt es noch keine Fassung,
 * wird die Standardvorlage als Fassung 1 angelegt.
 *
 * Vorlagen werden je Empfaengerart gepflegt (Patient, Hausarzt, ueberweisender Arzt); jede Art
 * hat einen eigenen Fassungsverlauf.
 */
final class LetterTemplateService
{
    private const int MAX_COMMENT = 500;

    public function __construct(
        private readonly LetterTemplateRepository $repository,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Aktuelle Fassung einer Empfaengerart; legt bei Bedarf die Standardvorlage als Fassung 1 an.
     *
     * @return array{id: int, type: string, version_no: int, name: string, comment: string, created_at: string, content_sha256: string, content: array<string, mixed>}
     */
    public function current(string $type = LetterRecipient::PATIENT): array
    {
        $type = self::type($type);
        $row = $this->repository->current($type);
        if ($row === null) {
            $default = LetterTemplate::default($type);
            try {
                $this->repository->insert($type, 1, (string) $default['name'], 'Standardvorlage nach DIN 5008', LetterTemplate::encode($default), $this->now());
            } catch (PDOException $e) {
                // Parallel angelegt (eindeutige Fassungsnummer): die vorhandene Fassung verwenden.
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
            }
            $row = $this->repository->current($type) ?? throw new \RuntimeException('Die Briefvorlage konnte nicht angelegt werden.');
        }
        return self::present($row);
    }

    /**
     * Vorlagen aller Empfaengerarten (legt fehlende Standardvorlagen an).
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $all = [];
        foreach (array_keys(LetterTemplate::types()) as $type) {
            $all[$type] = $this->current($type);
        }
        return $all;
    }

    /**
     * @return array{id: int, type: string, version_no: int, name: string, comment: string, created_at: string, content_sha256: string, content: array<string, mixed>}|null
     */
    public function version(int $id): ?array
    {
        $row = $this->repository->version($id);
        return $row === null ? null : self::present($row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function versions(string $type = LetterRecipient::PATIENT): array
    {
        $this->current($type);
        return $this->repository->versions(self::type($type));
    }

    /**
     * Speichert eine neue Fassung. $baseVersionId ist die Fassung, auf der die Bearbeitung beruht;
     * wurde inzwischen eine neuere Fassung gespeichert, wird abgelehnt (keine stillen Ueberschreibungen).
     *
     * @return array{id: int, type: string, version_no: int, name: string, comment: string, created_at: string, content_sha256: string, content: array<string, mixed>}
     * @throws LetterException
     */
    public function save(mixed $content, string $comment, ?int $baseVersionId, string $type = LetterRecipient::PATIENT): array
    {
        $type = self::type($type);
        $template = LetterTemplate::normalize($content);
        $comment = trim(str_replace(["\r", "\n"], ' ', $comment));
        if (mb_strlen($comment) > self::MAX_COMMENT) {
            throw LetterException::rule('comment', sprintf('Die Änderungsnotiz darf höchstens %d Zeichen lang sein.', self::MAX_COMMENT));
        }
        $current = $this->current($type);
        if ($baseVersionId !== null && $baseVersionId !== $current['id']) {
            throw LetterException::rule('base_version', sprintf(
                'Inzwischen wurde Fassung %d gespeichert. Bitte die aktuelle Fassung laden und die Änderungen erneut vornehmen.',
                $current['version_no'],
            ));
        }
        $encoded = LetterTemplate::encode($template);
        if ($encoded === LetterTemplate::encode($current['content'])) {
            throw LetterException::rule('template', 'Die Vorlage ist unverändert; es wurde keine neue Fassung angelegt.');
        }

        try {
            $id = $this->repository->insert(
                $type,
                $this->repository->nextVersionNo($type),
                (string) $template['name'],
                $comment === '' ? null : $comment,
                $encoded,
                $this->now(),
            );
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw LetterException::rule('base_version', 'Gleichzeitig wurde eine andere Fassung gespeichert. Bitte erneut versuchen.');
            }
            throw $e;
        }
        return $this->version($id) ?? throw new \RuntimeException('Die gespeicherte Fassung wurde nicht gefunden.');
    }

    /**
     * Unbekannte Empfaengerarten fallen auf die Patientenvorlage zurueck.
     */
    private static function type(string $type): string
    {
        return LetterTemplate::isType($type) ? $type : LetterRecipient::PATIENT;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, type: string, version_no: int, name: string, comment: string, created_at: string, content_sha256: string, content: array<string, mixed>}
     */
    private static function present(array $row): array
    {
        $decoded = json_decode((string) $row['content'], true);
        try {
            $content = LetterTemplate::normalize($decoded);
        } catch (LetterException) {
            // Gespeicherte Fassungen wurden beim Speichern geprueft; Abweichungen nur ergaenzen.
            $content = is_array($decoded) ? $decoded : LetterTemplate::default((string) $row['template_type']);
        }
        return [
            'id' => (int) $row['id'],
            'type' => (string) $row['template_type'],
            'version_no' => (int) $row['version_no'],
            'name' => (string) $row['name'],
            'comment' => (string) ($row['comment'] ?? ''),
            'created_at' => (string) $row['created_at'],
            'content_sha256' => (string) $row['content_sha256'],
            'content' => $content,
        ];
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
