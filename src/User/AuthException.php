<?php

declare(strict_types=1);

namespace App\User;

use RuntimeException;

/**
 * Fehlgeschlagene Anmeldung.
 *
 * Die Meldungen sind bewusst allgemein gehalten und enthalten niemals Kennwoerter oder
 * Hinweise auf die Existenz einzelner Konten; die Sperre nach Fehlversuchen nennt nur die
 * Wartezeit, damit die Ursache fuer die Benutzer erkennbar bleibt.
 */
final class AuthException extends RuntimeException
{
    public static function invalidCredentials(): self
    {
        return new self('Benutzername oder Kennwort ist falsch.');
    }

    public static function locked(int $minutes): self
    {
        return new self(sprintf(
            'Zu viele Fehlanmeldungen. Das Benutzerkonto ist für %d Minuten gesperrt.',
            max(1, $minutes),
        ));
    }

    public static function disabled(): self
    {
        return new self('Das Benutzerkonto ist deaktiviert. Bitte die Administration informieren.');
    }
}
