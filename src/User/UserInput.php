<?php

declare(strict_types=1);

namespace App\User;

/**
 * Gepruefte und normalisierte Eingaben der Benutzerverwaltung.
 *
 * Regeln:
 *  * Der Anmeldename wird kleingeschrieben und ist eindeutig. Erlaubt sind Buchstaben, Ziffern
 *    sowie Punkt, Unterstrich und Bindestrich (keine Leerzeichen, keine Umlaute), 3 bis 64
 *    Zeichen, Beginn und Ende alphanumerisch.
 *  * Kennwoerter haben mindestens 12 Zeichen (laenger als jede uebliche Vorgabe bei
 *    Gesundheitsdaten) und werden nie im Klartext gespeichert oder protokolliert.
 *  * Ein Benutzer ohne Gruppe hat keine Rechte; die Zuordnung erfolgt ueber Gruppenkennungen.
 *  * Benutzer werden nie geloescht, nur deaktiviert.
 */
final class UserInput
{
    public const int MIN_USERNAME_LENGTH = 3;
    public const int MAX_USERNAME_LENGTH = 64;
    public const int MAX_DISPLAY_NAME = 128;
    public const int MIN_PASSWORD_LENGTH = 12;
    public const int MAX_PASSWORD_LENGTH = 200;

    /**
     * @param list<int> $groupIds
     */
    private function __construct(
        public readonly string $username,
        public readonly string $displayName,
        public readonly bool $isActive,
        public readonly array $groupIds,
    ) {
    }

    /**
     * Prueft das Formular der Benutzerverwaltung. Kennwort und Gruppen werden getrennt geprueft.
     *
     * @param array<string, mixed> $post
     */
    public static function fromPost(array $post): self
    {
        $errors = [];
        $username = self::normalizeUsername(self::text($post['username'] ?? ''));
        if ($username === '') {
            $errors['username'] = 'Bitte einen Anmeldenamen angeben.';
        } elseif (!self::usernameIsValid($username)) {
            $errors['username'] = sprintf(
                'Der Anmeldename braucht %d–%d Zeichen: Buchstaben, Ziffern, Punkt, Unterstrich oder Bindestrich.',
                self::MIN_USERNAME_LENGTH,
                self::MAX_USERNAME_LENGTH,
            );
        }

        $displayName = self::cleanText(self::text($post['display_name'] ?? ''), self::MAX_DISPLAY_NAME);
        if ($displayName === '') {
            $errors['display_name'] = 'Bitte einen Anzeigenamen angeben (Vor- und Nachname).';
        }

        if ($errors !== []) {
            throw UserException::validation($errors);
        }

        return new self(
            username: $username,
            displayName: $displayName,
            isActive: self::bool($post['is_active'] ?? null),
            groupIds: self::groupIds($post['groups'] ?? []),
        );
    }

    /**
     * Prueft ein neues Kennwort; leere Rueckgabe bedeutet "in Ordnung".
     *
     * @return array<string, string>
     */
    public static function validatePassword(string $password, string $username = ''): array
    {
        $length = mb_strlen($password);
        if ($length < self::MIN_PASSWORD_LENGTH) {
            return ['password' => sprintf('Das Kennwort braucht mindestens %d Zeichen.', self::MIN_PASSWORD_LENGTH)];
        }
        if ($length > self::MAX_PASSWORD_LENGTH) {
            return ['password' => sprintf('Das Kennwort darf höchstens %d Zeichen haben.', self::MAX_PASSWORD_LENGTH)];
        }
        if ($username !== '' && mb_strtolower($password) === mb_strtolower($username)) {
            return ['password' => 'Das Kennwort darf nicht dem Anmeldenamen entsprechen.'];
        }
        return [];
    }

    /**
     * Wie validatePassword(), wirft aber bei Verstoss.
     */
    public static function requirePassword(string $password, string $username = ''): void
    {
        $errors = self::validatePassword($password, $username);
        if ($errors !== []) {
            throw UserException::validation($errors);
        }
    }

    /**
     * Werte fuer das erneute Anzeigen des Formulars.
     *
     * @return array{username:string,display_name:string,is_active:bool,groups:list<int>}
     */
    public function values(): array
    {
        return [
            'username' => $this->username,
            'display_name' => $this->displayName,
            'is_active' => $this->isActive,
            'groups' => $this->groupIds,
        ];
    }

    public static function normalizeUsername(string $username): string
    {
        return mb_strtolower(trim($username));
    }

    public static function usernameIsValid(string $username): bool
    {
        $length = mb_strlen($username);
        if ($length < self::MIN_USERNAME_LENGTH || $length > self::MAX_USERNAME_LENGTH) {
            return false;
        }
        return preg_match('/^[a-z0-9][a-z0-9._-]*[a-z0-9]$/', $username) === 1;
    }

    /**
     * Gruppe von Formularwerten: nur positive Ganzzahlen, ohne Wiederholungen.
     *
     * @param mixed $raw
     * @return list<int>
     */
    public static function groupIds(mixed $raw): array
    {
        $values = is_array($raw) ? $raw : [$raw];
        $ids = [];
        foreach ($values as $value) {
            if (is_string($value) && ctype_digit($value)) {
                $value = (int) $value;
            }
            if (is_int($value) && $value > 0 && !in_array($value, $ids, true)) {
                $ids[] = $value;
            }
        }
        return $ids;
    }

    /**
     * @param mixed $value
     */
    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * Entfernt Steuerzeichen und begrenzt die Laenge (Anzeigename).
     */
    private static function cleanText(string $value, int $maxLength): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? '');
        return mb_substr($clean, 0, $maxLength);
    }

    /**
     * @param mixed $value
     */
    private static function bool(mixed $value): bool
    {
        if (is_array($value)) {
            return false;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}
