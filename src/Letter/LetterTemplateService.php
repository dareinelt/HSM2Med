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
     * Aktuelle Fassung; legt bei Bedarf die Standardvorlage als Fassung 1 an.
     *
     * @return array{id: int, version_no: int, name: string, comment: string, created_at: string, content_sha256: string, content: array<string, mixed>}
     */
    public function current(): array
    {
        $row = $this->repository->current();
        if ($row === null) {
            $default = LetterTemplate::default();
            try {
                $this->repository->insert(1, (string) $default['name'], 'Standardvorlage nach DIN 5008', LetterTemplate::encode($default), $this->now());
            } catch (PDOException $e) {
                // Parallel angelegt (eindeutige Fassungsnummer): die vorhandene Fassung verwenden.
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
            }
            $row = $this->repository->current() ?? throw new \RuntimeException('Die Briefvorlage konnte nicht angelegt werden.');
        }
        return self::present($row);
    }

    /**
     * @return array{id: int, version_no: int, name: string, comment: string, created_at: string, content_sha256: string, content: array<string, mixed>}|null
     */
    public function version(int $id): ?array
    {
        $row = $this->repository->version($id);
        return $row === null ? null : self::present($row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function versions(): array
    {
        $this->current();
        return $this->repository->versions();
    }

    /**
     * Speichert eine neue Fassung. $baseVersionId ist die Fassung, auf der die Bearbeitung beruht;
     * wurde inzwischen eine neuere Fassung gespeichert, wird abgelehnt (keine stillen Ueberschreibungen).
     *
     * @return array{id: int, version_no: int, name: string, comment: string, created_at: string, content_sha256: string, content: array<string, mixed>}
     * @throws LetterException
     */
    public function save(mixed $content, string $comment, ?int $baseVersionId): array
    {
        $template = LetterTemplate::normalize($content);
        $comment = trim(str_replace(["\r", "\n"], ' ', $comment));
        if (mb_strlen($comment) > self::MAX_COMMENT) {
            throw LetterException::rule('comment', sprintf('Die Änderungsnotiz darf höchstens %d Zeichen lang sein.', self::MAX_COMMENT));
        }
        $current = $this->current();
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
                $this->repository->nextVersionNo(),
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
     * @param array<string, mixed> $row
     * @return array{id: int, version_no: int, name: string, comment: string, created_at: string, content_sha256: string, content: array<string, mixed>}
     */
    private static function present(array $row): array
    {
        $decoded = json_decode((string) $row['content'], true);
        try {
            $content = LetterTemplate::normalize($decoded);
        } catch (LetterException) {
            // Gespeicherte Fassungen wurden beim Speichern geprueft; Abweichungen nur ergaenzen.
            $content = is_array($decoded) ? $decoded : LetterTemplate::default();
        }
        return [
            'id' => (int) $row['id'],
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
