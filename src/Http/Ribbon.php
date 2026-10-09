<?php

declare(strict_types=1);

namespace App\Http;

use InvalidArgumentException;

/**
 * Aufbau des Funktionsbandes (Ribbon) der Oberflaeche.
 *
 * Die Reiter fassen die vorhandenen Bereiche der Anwendung in logische Gruppen
 * zusammen, damit IT-Laien Ziele in hoechstens zwei Schritten finden: Reiter
 * oeffnen, grosse Schaltflaeche mit Symbol und Beschriftung anklicken. Die
 * Zuordnung der Reiter zu den Bereichen erfolgt ueber die Kennung, die jeder
 * Controller an View::render() uebergibt – URLs und Routen bleiben unveraendert.
 */
final class Ribbon
{
    public const string DEFAULT_TAB = 'start';

    /**
     * Bereiche der Anwendung: Kennung (aus den Controllern) => Beschriftung.
     *
     * @var array<string, string>
     */
    private const array SECTIONS = [
        'dashboard' => 'Dashboard',
        'import' => 'Import',
        'reports' => 'Berichte',
        'patients' => 'Patientenakte',
        'patient_cards' => 'Patientenausweise',
        'patient_card_settings' => 'Ausweis-Stammdaten',
        'letters' => 'Briefe',
        'imports' => 'Importprotokoll',
        'system' => 'Systeminformationen',
        'system_settings' => 'Praxis-Informationen',
        'letter_templates' => 'Briefvorlagen',
        'patient_card_templates' => 'Ausweisvorlagen',
        'users' => 'Benutzerverwaltung',
        'logs' => 'Fehlerprotokoll',
    ];

    /**
     * Ziele, die einen aktiven Patienten voraussetzen (patientenbezogene Vorgaenge).
     *
     * Der Patientenvorgang ist fuehrend: Solange kein Patient ausgewaehlt ist, sind diese
     * Schaltflaechen gesperrt. Listen und Nachschlagewerke bleiben erreichbar.
     *
     * @var list<string>
     */
    private const array PATIENT_REQUIRED = [
        '/import',
        '/patient-cards/new',
        '/letters/new',
    ];

    /**
     * @return array<string, array{id:string,label:string,icon:string,href:string,sections:list<string>,groups:list<array{label:string,items:list<array{label:string,icon:string,href:string,title:string,match:list<string>,target?:string}>}>}>
     */
    private static function definition(): array
    {
        return [
            'start' => [
                'id' => 'start',
                'label' => 'Start',
                'icon' => 'dashboard',
                'href' => '/',
                'sections' => ['dashboard'],
                'groups' => [
                    [
                        'label' => 'Überblick',
                        'items' => [
                            ['label' => 'Dashboard', 'icon' => 'dashboard', 'href' => '/', 'title' => 'Kennzahlen und letzte Vorgänge', 'match' => ['dashboard']],
                            ['label' => 'Berichte', 'icon' => 'reports', 'href' => '/reports', 'title' => 'Alle importierten Berichte', 'match' => []],
                            ['label' => 'Patientenakten', 'icon' => 'patients', 'href' => '/patients', 'title' => 'Patienten mit ihren Nachsorgeuntersuchungen', 'match' => []],
                            ['label' => 'Patientenausweise', 'icon' => 'cards', 'href' => '/patient-cards', 'title' => 'Ausweise mit MRT-Kompatibilität und Nachsorgeplan', 'match' => []],
                            ['label' => 'Briefe', 'icon' => 'letters', 'href' => '/letters', 'title' => 'Arztbriefe aus den Auslesedaten', 'match' => []],
                        ],
                    ],
                    [
                        'label' => 'Neu anlegen',
                        'items' => [
                            ['label' => 'Patient anlegen', 'icon' => 'user-plus', 'href' => '/patients/new', 'title' => 'Neuen Patienten mit Stammdaten erfassen', 'match' => []],
                            ['label' => 'Ausweis erstellen', 'icon' => 'card-plus', 'href' => '/patient-cards/new', 'title' => 'Patientenausweis aus einem Bericht erzeugen', 'match' => []],
                            ['label' => 'Brief erstellen', 'icon' => 'mail-new', 'href' => '/letters/new', 'title' => 'Arztbrief aus einem Bericht erzeugen', 'match' => []],
                        ],
                    ],
                    [
                        'label' => 'Nachschlagen',
                        'items' => [
                            ['label' => 'Importprotokoll', 'icon' => 'log', 'href' => '/imports', 'title' => 'Alle Importe mit Status und Fehlern', 'match' => []],
                            ['label' => 'Systeminformationen', 'icon' => 'system', 'href' => '/system', 'title' => 'Version, Datenbank und Speicherorte', 'match' => []],
                            ['label' => 'Fehlerprotokoll', 'icon' => 'warning', 'href' => '/system/logs', 'title' => 'Technische Fehlermeldungen, Suche nach Referenz', 'match' => []],
                        ],
                    ],
                ],
            ],
            'import' => [
                'id' => 'import',
                'label' => 'Import',
                'icon' => 'import',
                'href' => '/import',
                'sections' => ['import', 'imports'],
                'groups' => [
                    [
                        'label' => 'Auslesedaten einlesen',
                        'items' => [
                            ['label' => 'Datei importieren', 'icon' => 'import', 'href' => '/import', 'title' => 'Exportdatei des Programmiergeräts prüfen und übernehmen', 'match' => ['import']],
                            ['label' => 'Importprotokoll', 'icon' => 'log', 'href' => '/imports', 'title' => 'Alle Importe mit Status und Fehlern', 'match' => ['imports']],
                        ],
                    ],
                    [
                        'label' => 'Weiter zur Auswertung',
                        'items' => [
                            ['label' => 'Berichte ansehen', 'icon' => 'reports', 'href' => '/reports', 'title' => 'Alle importierten Berichte', 'match' => []],
                            ['label' => 'Patientenausweise', 'icon' => 'cards', 'href' => '/patient-cards', 'title' => 'Ausweise mit MRT-Kompatibilität und Nachsorgeplan', 'match' => []],
                        ],
                    ],
                ],
            ],
            'reports' => [
                'id' => 'reports',
                'label' => 'Berichte',
                'icon' => 'reports',
                'href' => '/reports',
                'sections' => ['reports'],
                'groups' => [
                    [
                        'label' => 'Berichte',
                        'items' => [
                            ['label' => 'Berichtsübersicht', 'icon' => 'reports', 'href' => '/reports', 'title' => 'Alle importierten Berichte', 'match' => ['reports']],
                            ['label' => 'Importprotokoll', 'icon' => 'log', 'href' => '/imports', 'title' => 'Alle Importe mit Status und Fehlern', 'match' => []],
                        ],
                    ],
                    [
                        'label' => 'Aus dem Bericht erstellen',
                        'items' => [
                            ['label' => 'Patientenausweis', 'icon' => 'card-plus', 'href' => '/patient-cards/new', 'title' => 'Patientenausweis aus einem Bericht erzeugen', 'match' => []],
                            ['label' => 'Arztbrief', 'icon' => 'mail-new', 'href' => '/letters/new', 'title' => 'Arztbrief aus einem Bericht erzeugen', 'match' => []],
                        ],
                    ],
                ],
            ],
            'patients' => [
                'id' => 'patients',
                'label' => 'Patientenakte',
                'icon' => 'patients',
                'href' => '/patients',
                'sections' => ['patients'],
                'groups' => [
                    [
                        'label' => 'Akten',
                        'items' => [
                            ['label' => 'Patientenübersicht', 'icon' => 'patients', 'href' => '/patients', 'title' => 'Patienten mit ihren Nachsorgeuntersuchungen', 'match' => ['patients']],
                            ['label' => 'Patient anlegen', 'icon' => 'user-plus', 'href' => '/patients/new', 'title' => 'Neuen Patienten mit Stammdaten erfassen', 'match' => []],
                        ],
                    ],
                    [
                        'label' => 'Weiterverarbeiten',
                        'items' => [
                            ['label' => 'Ausweise und Nachsorge', 'icon' => 'cards', 'href' => '/patient-cards', 'title' => 'Ausweise mit MRT-Kompatibilität und Nachsorgeplan', 'match' => []],
                            ['label' => 'Briefe', 'icon' => 'letters', 'href' => '/letters', 'title' => 'Arztbriefe aus den Auslesedaten', 'match' => []],
                        ],
                    ],
                    [
                        'label' => 'Nachschlagen',
                        'items' => [
                            ['label' => 'Importprotokoll', 'icon' => 'log', 'href' => '/imports', 'title' => 'Alle Importe mit Status und Fehlern', 'match' => []],
                        ],
                    ],
                ],
            ],
            'cards' => [
                'id' => 'cards',
                'label' => 'Patientenausweise',
                'icon' => 'cards',
                'href' => '/patient-cards',
                'sections' => ['patient_cards', 'patient_card_settings'],
                'groups' => [
                    [
                        'label' => 'Ausweise',
                        'items' => [
                            ['label' => 'Ausweisübersicht', 'icon' => 'cards', 'href' => '/patient-cards', 'title' => 'Ausweise mit MRT-Kompatibilität und Nachsorgeplan', 'match' => ['patient_cards']],
                            ['label' => 'Ausweis erstellen', 'icon' => 'card-plus', 'href' => '/patient-cards/new', 'title' => 'Patientenausweis aus einem Bericht erzeugen', 'match' => []],
                            ['label' => 'Ausweis-Stammdaten', 'icon' => 'settings', 'href' => '/patient-cards/settings', 'title' => 'Hinweistexte des Ausweises pflegen', 'match' => ['patient_card_settings']],
                        ],
                    ],
                    [
                        'label' => 'Quellen',
                        'items' => [
                            ['label' => 'Patientenakten', 'icon' => 'patients', 'href' => '/patients', 'title' => 'Patienten mit ihren Nachsorgeuntersuchungen', 'match' => []],
                            ['label' => 'Berichte', 'icon' => 'reports', 'href' => '/reports', 'title' => 'Alle importierten Berichte', 'match' => []],
                        ],
                    ],
                ],
            ],
            'letters' => [
                'id' => 'letters',
                'label' => 'Briefe',
                'icon' => 'letters',
                'href' => '/letters',
                'sections' => ['letters'],
                'groups' => [
                    [
                        'label' => 'Briefe',
                        'items' => [
                            ['label' => 'Briefübersicht', 'icon' => 'letters', 'href' => '/letters', 'title' => 'Arztbriefe aus den Auslesedaten', 'match' => ['letters']],
                            ['label' => 'Brief erstellen', 'icon' => 'mail-new', 'href' => '/letters/new', 'title' => 'Arztbrief aus einem Bericht erzeugen', 'match' => []],
                        ],
                    ],
                    [
                        'label' => 'Vorlagen',
                        'items' => [
                            ['label' => 'Praxis-Informationen', 'icon' => 'settings', 'href' => '/system/settings', 'title' => 'Praxis, Kontaktangaben, Logo und Rücksendeangaben für die Briefe pflegen', 'match' => []],
                            ['label' => 'Briefvorlage', 'icon' => 'edit', 'href' => '/system/letter-templates', 'title' => 'Feste Texte und Aufbau der Briefe (DIN 5008) bearbeiten – öffnet in einem neuen Tab', 'match' => ['letter_templates'], 'target' => '_blank'],
                        ],
                    ],
                    [
                        'label' => 'Quellen',
                        'items' => [
                            ['label' => 'Patientenakten', 'icon' => 'patients', 'href' => '/patients', 'title' => 'Patienten mit ihren Nachsorgeuntersuchungen', 'match' => []],
                            ['label' => 'Berichte', 'icon' => 'reports', 'href' => '/reports', 'title' => 'Alle importierten Berichte', 'match' => []],
                        ],
                    ],
                ],
            ],
            'system' => [
                'id' => 'system',
                'label' => 'System',
                'icon' => 'system',
                'href' => '/system',
                'sections' => ['system', 'system_settings', 'logs', 'letter_templates', 'patient_card_templates', 'users'],
                'groups' => [
                    [
                        'label' => 'Praxis',
                        'items' => [
                            ['label' => 'Praxis-Informationen', 'icon' => 'settings', 'href' => '/system/settings', 'title' => 'Praxis, Kontaktangaben, Logo und Rücksendeangaben für Briefe und Ausweise pflegen', 'match' => ['system_settings']],
                        ],
                    ],
                    [
                        'label' => 'Zugang',
                        'items' => [
                            ['label' => 'Benutzerverwaltung', 'icon' => 'users', 'href' => '/system/users', 'title' => 'Benutzerkonten, Gruppen und deren Rechte verwalten', 'match' => ['users']],
                            ['label' => 'Eigenes Kennwort', 'icon' => 'key', 'href' => '/account/password', 'title' => 'Das eigene Kennwort ändern', 'match' => []],
                        ],
                    ],
                    [
                        'label' => 'Betrieb',
                        'items' => [
                            ['label' => 'Systeminformationen', 'icon' => 'system', 'href' => '/system', 'title' => 'Version, Datenbank und Speicherorte', 'match' => ['system']],
                            ['label' => 'Fehlerprotokoll', 'icon' => 'warning', 'href' => '/system/logs', 'title' => 'Technische Fehlermeldungen, Suche nach Referenz', 'match' => ['logs']],
                            ['label' => 'Importprotokoll', 'icon' => 'log', 'href' => '/imports', 'title' => 'Alle Importe mit Status und Fehlern', 'match' => []],
                        ],
                    ],
                    [
                        'label' => 'Vorlagen',
                        'items' => [
                            ['label' => 'Briefvorlage', 'icon' => 'edit', 'href' => '/system/letter-templates', 'title' => 'Feste Texte und Aufbau der Briefe (DIN 5008) bearbeiten – öffnet in einem neuen Tab', 'match' => ['letter_templates'], 'target' => '_blank'],
                            ['label' => 'Ausweisvorlage', 'icon' => 'edit', 'href' => '/system/patient-card-templates', 'title' => 'Feste Texte und Aufbau der Patientenausweise bearbeiten – öffnet in einem neuen Tab', 'match' => ['patient_card_templates'], 'target' => '_blank'],
                        ],
                    ],
                    [
                        'label' => 'Daten und Datenschutz',
                        'items' => [
                            ['label' => 'Datenschutz', 'icon' => 'shield', 'href' => '/system#datenschutz', 'title' => 'Wo die Daten gespeichert werden und wie sie geschützt sind', 'match' => []],
                            ['label' => 'Berichte', 'icon' => 'reports', 'href' => '/reports', 'title' => 'Alle importierten Berichte', 'match' => []],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Alle Reiter des Funktionsbandes.
     *
     * @return list<array{id:string,label:string,icon:string,href:string,sections:list<string>,groups:list<array{label:string,items:list<array{label:string,icon:string,href:string,title:string,match:list<string>,target?:string}>}>}>
     */
    public static function tabs(): array
    {
        return array_values(self::definition());
    }

    /**
     * @return array{id:string,label:string,icon:string,href:string,sections:list<string>,groups:list<array{label:string,items:list<array{label:string,icon:string,href:string,title:string,match:list<string>,target?:string}>}>}
     */
    public static function tab(string $id): array
    {
        $tabs = self::definition();
        if (!isset($tabs[$id])) {
            throw new InvalidArgumentException('Unbekannter Reiter: ' . $id);
        }
        return $tabs[$id];
    }

    /**
     * Reiter, der zu einem Bereich der Anwendung gehoert.
     */
    public static function tabIdForSection(string $section): string
    {
        foreach (self::definition() as $tab) {
            if (in_array($section, $tab['sections'], true)) {
                return $tab['id'];
            }
        }
        return self::DEFAULT_TAB;
    }

    /**
     * Beschriftung eines Bereichs (fuer Titel- und Statusleiste).
     */
    public static function sectionLabel(string $section): string
    {
        return self::SECTIONS[$section] ?? '';
    }

    /**
     * Bereiche der Anwendung samt Beschriftung.
     *
     * @return array<string, string>
     */
    public static function sections(): array
    {
        return self::SECTIONS;
    }

    /**
     * Setzt ein Ziel einen aktiven Patienten voraus?
     */
    public static function requiresPatient(string $href): bool
    {
        return in_array($href, self::PATIENT_REQUIRED, true);
    }

    /**
     * Alle gesperrten Ziele (Reiter, Schaltflaechen und Schnellzugriff) – Grundlage fuer Tests.
     *
     * @return list<string>
     */
    public static function patientRequiredTargets(): array
    {
        $targets = [];
        foreach (self::tabs() as $tab) {
            if (self::requiresPatient($tab['href'])) {
                $targets[] = $tab['href'];
            }
            foreach ($tab['groups'] as $group) {
                foreach ($group['items'] as $item) {
                    if (self::requiresPatient($item['href'])) {
                        $targets[] = $item['href'];
                    }
                }
            }
        }
        foreach (self::quickAccess() as $item) {
            if (self::requiresPatient($item['href'])) {
                $targets[] = $item['href'];
            }
        }
        return array_values(array_unique($targets));
    }

    /**
     * Schnellzugriff in der Titelleiste: die haeufigsten Einstiegspunkte,
     * als reine Symbolschaltflaechen mit Beschriftung fuer Vorleseprogramme.
     *
     * @return list<array{label:string,icon:string,href:string,title:string}>
     */
    public static function quickAccess(): array
    {
        return [
            ['label' => 'Import', 'icon' => 'import', 'href' => '/import', 'title' => 'Datei importieren'],
            ['label' => 'Patient anlegen', 'icon' => 'user-plus', 'href' => '/patients/new', 'title' => 'Neuen Patienten erfassen'],
            ['label' => 'Ausweis erstellen', 'icon' => 'card-plus', 'href' => '/patient-cards/new', 'title' => 'Patientenausweis aus Bericht erzeugen'],
            ['label' => 'Brief erstellen', 'icon' => 'mail-new', 'href' => '/letters/new', 'title' => 'Arztbrief aus Bericht erzeugen'],
        ];
    }

    /**
     * Alle im Funktionsband verwendeten Symbolnamen – Grundlage fuer Tests,
     * die eine vollstaendige Symbolbibliothek sicherstellen.
     *
     * @return list<string>
     */
    public static function iconNames(): array
    {
        $names = [];
        foreach (self::tabs() as $tab) {
            $names[] = $tab['icon'];
            foreach ($tab['groups'] as $group) {
                foreach ($group['items'] as $item) {
                    $names[] = $item['icon'];
                }
            }
        }
        foreach (self::quickAccess() as $item) {
            $names[] = $item['icon'];
        }
        return array_values(array_unique($names));
    }
}
