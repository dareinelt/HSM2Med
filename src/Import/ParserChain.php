<?php

declare(strict_types=1);

namespace App\Import;

use RuntimeException;

/**
 * Waehlt anhand des Dateiinhalts den passenden Parser und reicht parse() an ihn weiter.
 * Der erste Parser der Liste gilt als Rueckfall, wenn kein Parser die Datei erkennt.
 */
final class ParserChain implements ParserInterface
{
    /** @var list<ParserInterface> */
    private array $parsers;

    /**
     * @param list<ParserInterface> $parsers Parser in Pruefreihenfolge
     */
    public function __construct(array $parsers)
    {
        if ($parsers === []) {
            throw new RuntimeException('Es muss mindestens ein Parser konfiguriert sein.');
        }
        $this->parsers = array_values($parsers);
    }

    public static function default(): self
    {
        return new self([new MerlinParser(), new BiotronikParser()]);
    }

    /**
     * Parser, der fuer diese Datei zustaendig ist.
     */
    public function forBytes(string $bytes, string $filename = ''): ParserInterface
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($bytes, $filename)) {
                return $parser;
            }
        }
        return $this->parsers[0];
    }

    public function parse(string $bytes): ParseResult
    {
        return $this->forBytes($bytes)->parse($bytes);
    }

    public function name(): string
    {
        return implode(' / ', array_map(static fn (ParserInterface $p): string => $p->name(), $this->parsers));
    }

    public function version(): string
    {
        return implode(' / ', $this->descriptions());
    }

    /**
     * Liste der Parser mit Version, z.B. "Merlin (Abbott / St. Jude Medical) 1.0.0".
     *
     * @return list<string>
     */
    public function descriptions(): array
    {
        return array_map(
            static fn (ParserInterface $p): string => $p->name() . ' ' . $p->version(),
            $this->parsers,
        );
    }

    public function supports(string $bytes, string $filename = ''): bool
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($bytes, $filename)) {
                return true;
            }
        }
        return false;
    }
}
