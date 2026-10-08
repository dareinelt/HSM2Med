<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\UserRepository;
use App\Support\Clock;
use App\User\User;

/**
 * Anmeldestatus der Sitzung.
 *
 * In der Sitzung steht nur die Kennung des Benutzers, niemals ein Kennwort oder ein Recht.
 * Gruppen und Rechte werden bei jedem Zugriff aus der Datenbank gelesen; eine Aenderung der
 * Rechtematrix wirkt damit sofort, ohne erneute Anmeldung.
 *
 * Die Sitzung verfaellt nach einer Ruhezeit (AUTH_IDLE_MINUTES): ohne Anfrage in dieser Zeit
 * ist eine erneute Anmeldung noetig. Die Sitzungs-Kennung wird bei An- und Abmeldung erneuert
 * (Schutz vor Session-Fixation).
 *
 * An- und Abmeldung verwerfen den gesamten Sitzungsinhalt (aktiver Patient, offene Uploads,
 * CSRF-Token, Meldungen): nichts aus einer frueheren Anmeldung darf sichtbar bleiben oder an
 * die naechste Person am selben Arbeitsplatz uebergehen.
 *
 * Wird das Kennwort geaendert, enden alle anderen Sitzungen dieses Kontos: die Sitzung haelt
 * einen Fingerabdruck des Kennwort-Hashes und wird bei Abweichung beendet.
 */
final class Auth
{
    private const string SESSION_USER = '_auth_user_id';
    private const string SESSION_SEEN = '_auth_seen_at';
    private const string SESSION_CREDENTIAL = '_auth_credential';

    private ?User $user = null;
    private bool $loaded = false;

    public function __construct(
        private readonly UserRepository $users,
        private readonly Clock $clock,
        private readonly int $idleSeconds,
    ) {
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Angemeldete Person; null ohne Anmeldung, nach Ablauf der Ruhezeit, bei deaktiviertem
     * Konto oder nach einer Kennwortaenderung in einer anderen Sitzung (die Sitzung wird
     * dann beendet).
     */
    public function user(): ?User
    {
        if ($this->loaded) {
            return $this->user;
        }
        $this->loaded = true;

        $id = $this->userId();
        if ($id === null) {
            return null;
        }

        $now = $this->clock->now()->getTimestamp();
        $seen = $_SESSION[self::SESSION_SEEN] ?? null;
        if (!is_int($seen) || $now - $seen > $this->idleSeconds || $seen > $now) {
            $this->logout();
            return null;
        }

        $user = $this->users->find($id);
        if ($user === null || !$user->isActive || !$this->credentialMatches($id)) {
            // Security fix: eine Kennwortaenderung beendet auch alle anderen Sitzungen.
            $this->logout();
            return null;
        }
        $_SESSION[self::SESSION_SEEN] = $now;
        $this->user = $user;
        return $user;
    }

    public function id(): ?int
    {
        return $this->user()?->id;
    }

    /**
     * Darf die angemeldete Person diesen Bereich nutzen? Ohne Bereich ist der Zugriff frei.
     */
    public function can(?string $permission): bool
    {
        return $this->user()?->hasPermission($permission) ?? false;
    }

    public function login(User $user): void
    {
        $this->regenerate();
        // Security fix: keine Uebernahme von Sitzungsdaten aus der Zeit vor der Anmeldung
        // (aktiver Patient, offene Uploads, CSRF-Token einer frueheren Person).
        $_SESSION = [];
        $_SESSION[self::SESSION_USER] = $user->id;
        $_SESSION[self::SESSION_SEEN] = $this->clock->now()->getTimestamp();
        $_SESSION[self::SESSION_CREDENTIAL] = $this->users->credentialFingerprint($user->id);
        $this->user = $user;
        $this->loaded = true;
    }

    /**
     * Nach einer Aenderung des eigenen Kennworts: Sitzung bleibt bestehen, erhaelt aber eine
     * neue Kennung und den neuen Fingerabdruck. Andere Sitzungen des Kontos enden.
     */
    public function refreshCredential(): void
    {
        $id = $this->userId();
        if ($id === null) {
            return;
        }
        $this->regenerate();
        $_SESSION[self::SESSION_CREDENTIAL] = $this->users->credentialFingerprint($id);
    }

    public function logout(): void
    {
        // Security fix: frueher wurde nur die Benutzerkennung entfernt. Der aktive Patient
        // blieb in der Sitzung und erschien (Name, Geburtsdatum) im Anmeldefenster bzw. ging an
        // die naechste angemeldete Person ueber. Jetzt wird der gesamte Sitzungsinhalt verworfen.
        $_SESSION = [];
        $this->user = null;
        $this->loaded = true;
        $this->regenerate();
    }

    private function credentialMatches(int $id): bool
    {
        $stored = $_SESSION[self::SESSION_CREDENTIAL] ?? null;
        $current = $this->users->credentialFingerprint($id);
        return is_string($stored) && $current !== null && hash_equals($current, $stored);
    }

    private function userId(): ?int
    {
        $value = $_SESSION[self::SESSION_USER] ?? null;
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }
        return null;
    }

    /**
     * Erneuert die Sitzungs-Kennung, sofern eine Sitzung laeuft (in Tests nicht).
     */
    private function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}
