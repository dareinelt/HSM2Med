<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Gemeinsame Schnittstelle aller Datei-Parser (Merlin-Text, Biotronik-XML).
 * Jeder Parser liefert einen ParseResult, dessen Datensaetze unveraendert gespeichert werden.
 */
interface ParserInterface
{
    /** Kurzbezeichnung des Quellformats (fuer Importprotokoll und Anzeige). */
    public function name(): string;

    /** Version der Parserlogik; wird bei Import und Bericht festgehalten. */
    public function version(): string;

    /** Erkennt anhand von Dateiinhalt und Dateiname, ob dieser Parser zustaendig ist. */
    public function supports(string $bytes, string $filename = ''): bool;

    public function parse(string $bytes): ParseResult;
}
