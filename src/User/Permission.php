<?php

declare(strict_types=1);

namespace App\User;

/**
 * Rechtematrix der Anwendung: ein Recht je Bereich (Kennung des Funktionsbandes).
 *
 * Die Kennungen entsprechen den Bereichen aus Ribbon::sections() zuzueglich zweier Bereiche
 * ohne eigenen Reiter: 'letter_templates' (Vorlageneditor, eigenstaendige Seite) und 'users'
 * (Benutzerverwaltung, liegt im Reiter System).
 *
 * Ausnahme: 'account' (eigenes Kennwort aendern) ist bewusst kein Recht – jede angemeldete
 * Person darf ihr eigenes Kennwort aendern, unabhaengig von ihrer Gruppe.
 *
 * Die Rechte werden je Gruppe gepflegt (Tabelle user_group_permissions). Fehlt ein Eintrag,
 * ist der Bereich fuer die Gruppe gesperrt.
 */
final class Permission
{
    public const string DASHBOARD = 'dashboard';
    public const string IMPORT = 'import';
    public const string REPORTS = 'reports';
    public const string IMPORTS = 'imports';
    public const string PATIENTS = 'patients';
    public const string PATIENT_CARDS = 'patient_cards';
    public const string PATIENT_CARD_SETTINGS = 'patient_card_settings';
    public const string LETTERS = 'letters';
    public const string LETTER_TEMPLATES = 'letter_templates';
    public const string SYSTEM = 'system';
    public const string SYSTEM_SETTINGS = 'system_settings';
    public const string LOGS = 'logs';
    public const string USERS = 'users';

    /** Bereich ohne Recht: fuer alle angemeldeten Personen erlaubt. */
    public const string ACCOUNT = 'account';

    /**
     * Alle vergebbaren Rechte: Kennung => Beschriftung (Reihenfolge der Matrix).
     *
     * @var array<string, string>
     */
    public const array CATALOG = [
        self::DASHBOARD => 'Dashboard',
        self::IMPORT => 'Import',
        self::REPORTS => 'Berichte',
        self::IMPORTS => 'Importprotokoll',
        self::PATIENTS => 'Patientenakte',
        self::PATIENT_CARDS => 'Patientenausweise',
        self::PATIENT_CARD_SETTINGS => 'Ausweis-Stammdaten',
        self::LETTERS => 'Briefe',
        self::LETTER_TEMPLATES => 'Briefvorlagen (Editor)',
        self::SYSTEM => 'Systeminformationen',
        self::SYSTEM_SETTINGS => 'Praxis-Informationen',
        self::LOGS => 'Fehlerprotokoll',
        self::USERS => 'Benutzerverwaltung',
    ];

    /**
     * Kurzbeschreibung je Recht fuer die Rechtematrix.
     *
     * @var array<string, string>
     */
    public const array DESCRIPTIONS = [
        self::DASHBOARD => 'Kennzahlen und letzte Vorgänge',
        self::IMPORT => 'Merlin-Auslesedaten einlesen',
        self::REPORTS => 'Berichte ansehen und drucken',
        self::IMPORTS => 'Protokoll vergangener Importe',
        self::PATIENTS => 'Patientenakten ansehen und bearbeiten',
        self::PATIENT_CARDS => 'Patientenausweise erstellen und verwalten',
        self::PATIENT_CARD_SETTINGS => 'Hinweistexte der Ausweise pflegen',
        self::LETTERS => 'Briefe schreiben und versenden',
        self::LETTER_TEMPLATES => 'Briefvorlagen bearbeiten',
        self::SYSTEM => 'Systeminformationen und Diagnose',
        self::SYSTEM_SETTINGS => 'Praxis-Informationen und Logo pflegen',
        self::LOGS => 'Fehlerprotokoll einsehen',
        self::USERS => 'Benutzerkonten und Gruppen verwalten',
    ];

    /**
     * Alle Rechte in der Reihenfolge der Matrix.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::CATALOG);
    }

    public static function isKnown(string $permission): bool
    {
        return isset(self::CATALOG[$permission]);
    }

    public static function label(string $permission): string
    {
        return self::CATALOG[$permission] ?? '';
    }

    public static function description(string $permission): string
    {
        return self::DESCRIPTIONS[$permission] ?? '';
    }

    /**
     * Verwirft unbekannte Kennungen und Mehrfachnennungen.
     *
     * @param array<int|string, mixed> $permissions
     * @return list<string>
     */
    public static function normalize(array $permissions): array
    {
        $clean = [];
        foreach ($permissions as $permission) {
            if (!is_string($permission) || !self::isKnown($permission) || in_array($permission, $clean, true)) {
                continue;
            }
            $clean[] = $permission;
        }
        return $clean;
    }

    /**
     * Recht, das ein Pfad voraussetzt; null bedeutet "kein Recht noetig".
     *
     * Die Reihenfolge der Pruefungen ist bindend: spezielle Pfade vor ihren Praefixen
     * (/imports vor /import, /patient-cards/settings vor /patient-cards,
     * /system/<bereich> vor /system). Die Zuordnung wird von Router und Oberflaeche
     * gemeinsam genutzt, damit Sperren und Anzeige nie auseinanderlaufen.
     */
    public static function forPath(string $path): ?string
    {
        $path = '/' . ltrim($path, '/');
        if ($path === '/') {
            return self::DASHBOARD;
        }
        if (str_starts_with($path, '/account')) {
            return null;
        }

        return match (true) {
            str_starts_with($path, '/imports') => self::IMPORTS,
            str_starts_with($path, '/import') => self::IMPORT,
            str_starts_with($path, '/reports') => self::REPORTS,
            str_starts_with($path, '/patients') => self::PATIENTS,
            str_starts_with($path, '/patient-cards/settings') => self::PATIENT_CARD_SETTINGS,
            str_starts_with($path, '/patient-cards') => self::PATIENT_CARDS,
            str_starts_with($path, '/letters') => self::LETTERS,
            str_starts_with($path, '/system/letter-templates') => self::LETTER_TEMPLATES,
            str_starts_with($path, '/system/settings') => self::SYSTEM_SETTINGS,
            str_starts_with($path, '/system/users') => self::USERS,
            str_starts_with($path, '/system/logs') => self::LOGS,
            str_starts_with($path, '/system') => self::SYSTEM,
            default => null,
        };
    }
}
