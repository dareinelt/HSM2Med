<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Icon;
use App\Http\Ribbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Funktionsband (Ribbon): Reiter, Gruppen und Schaltflaechen der Oberflaeche.
 */
final class RibbonTest extends TestCase
{
    // Oberflaeche 5. Aufbau des Funktionsbandes: Reiter, Gruppen und Eintraege.
    public function testStructureIsComplete(): void
    {
        $tabs = Ribbon::tabs();
        $this->assertTrue(count($tabs) >= 5, 'Es sollten mehrere Reiter vorhanden sein.');

        $ids = [];
        foreach ($tabs as $tab) {
            $this->assertTrue($tab['label'] !== '', 'Reiter ohne Beschriftung.');
            $this->assertTrue(str_starts_with($tab['href'], '/'), 'Reiter ' . $tab['id'] . ' hat kein internes Ziel.');
            $this->assertTrue($tab['groups'] !== [], 'Reiter ' . $tab['id'] . ' hat keine Gruppen.');
            $this->assertSame($tab['id'], Ribbon::tab($tab['id'])['id']);
            $ids[] = $tab['id'];

            foreach ($tab['groups'] as $group) {
                $this->assertTrue($group['label'] !== '', 'Gruppe ohne Beschriftung in Reiter ' . $tab['id'] . '.');
                $this->assertTrue($group['items'] !== [], 'Gruppe ' . $group['label'] . ' ist leer.');
                foreach ($group['items'] as $item) {
                    $this->assertTrue($item['label'] !== '', 'Eintrag ohne Beschriftung.');
                    $this->assertTrue($item['title'] !== '', 'Eintrag ' . $item['label'] . ' hat keinen Hilfetext.');
                    $this->assertTrue(str_starts_with($item['href'], '/'), 'Eintrag ' . $item['label'] . ' hat kein internes Ziel.');
                }
            }
        }

        $this->assertSame($ids, array_values(array_unique($ids)), 'Reiter-Kennungen muessen eindeutig sein.');
        $this->assertTrue(in_array(Ribbon::DEFAULT_TAB, $ids, true), 'Der Standardreiter muss existieren.');
    }

    // Oberflaeche 6. Alle Symbole des Funktionsbandes sind in der Symbolbibliothek vorhanden.
    public function testEveryRibbonIconExists(): void
    {
        foreach (Ribbon::tabs() as $tab) {
            $this->assertTrue(Icon::has($tab['icon']), 'Unbekanntes Symbol fuer Reiter ' . $tab['id'] . ': ' . $tab['icon']);
            foreach ($tab['groups'] as $group) {
                foreach ($group['items'] as $item) {
                    $this->assertTrue(Icon::has($item['icon']), 'Unbekanntes Symbol: ' . $item['icon']);
                }
            }
        }
        foreach (Ribbon::quickAccess() as $item) {
            $this->assertTrue(Icon::has($item['icon']), 'Unbekanntes Schnellzugriffs-Symbol: ' . $item['icon']);
        }
        foreach (Ribbon::iconNames() as $name) {
            $this->assertTrue(Icon::has($name), 'iconNames() nennt ein unbekanntes Symbol: ' . $name);
        }
    }

    // Oberflaeche 7. Jede Bereichskennung der Controller ist genau einem Reiter zugeordnet.
    public function testEverySectionMapsToATab(): void
    {
        $known = [];
        foreach (Ribbon::tabs() as $tab) {
            foreach ($tab['sections'] as $section) {
                $this->assertTrue(array_key_exists($section, Ribbon::sections()), 'Unbekannter Bereich: ' . $section);
                $this->assertFalse(isset($known[$section]), 'Bereich ' . $section . ' ist mehrfach zugeordnet.');
                $known[$section] = $tab['id'];
                $this->assertSame($tab['id'], Ribbon::tabIdForSection($section));
            }
        }
        foreach (Ribbon::sections() as $section => $label) {
            $this->assertTrue(isset($known[$section]), 'Bereich ohne Reiter: ' . $section);
            $this->assertTrue($label !== '', 'Bereich ohne Beschriftung: ' . $section);
            $this->assertSame($label, Ribbon::sectionLabel($section));
        }
    }

    // Oberflaeche 8. Unbekannte Reiter und Bereiche fallen sauber zurueck.
    public function testUnknownValuesFallBack(): void
    {
        $this->assertSame(Ribbon::DEFAULT_TAB, Ribbon::tabIdForSection('gibt-es-nicht'));
        $this->assertSame(Ribbon::DEFAULT_TAB, Ribbon::tabIdForSection(''));
        $this->assertSame('', Ribbon::sectionLabel('gibt-es-nicht'));
        $this->assertThrows(InvalidArgumentException::class, static fn () => Ribbon::tab('gibt-es-nicht'));
    }

    // Oberflaeche 9. Alle Ziele bleiben die bestehenden, unveraenderten URLs der Anwendung.
    public function testTargetsKeepExistingRoutes(): void
    {
        $hrefs = [];
        $tabHrefs = [];
        foreach (Ribbon::tabs() as $tab) {
            $tabHrefs[$tab['id']] = $tab['href'];
            $hrefs[] = $tab['href'];
            foreach ($tab['groups'] as $group) {
                foreach ($group['items'] as $item) {
                    $hrefs[] = $item['href'];
                }
            }
        }
        foreach (Ribbon::quickAccess() as $item) {
            $hrefs[] = $item['href'];
        }

        $this->assertSame('/', $tabHrefs['start']);
        $this->assertSame('/import', $tabHrefs['import']);
        $this->assertSame('/reports', $tabHrefs['reports']);
        $this->assertSame('/patients', $tabHrefs['patients']);
        $this->assertSame('/patient-cards', $tabHrefs['cards']);
        $this->assertSame('/letters', $tabHrefs['letters']);
        $this->assertSame('/system', $tabHrefs['system']);

        foreach ([
            '/import', '/reports', '/patients', '/patients/new', '/patient-cards',
            '/patient-cards/new', '/patient-cards/settings', '/letters', '/letters/new',
            '/imports', '/system',
        ] as $expected) {
            $this->assertTrue(in_array($expected, $hrefs, true), 'Ziel fehlt im Funktionsband: ' . $expected);
        }
    }

    // Oberflaeche 10. Der Patientenvorgang ist fuehrend: nur patientenbezogene Ziele sind gesperrt.
    public function testPatientRequiredTargets(): void
    {
        foreach (['/import', '/patient-cards/new', '/letters/new'] as $gated) {
            $this->assertTrue(Ribbon::requiresPatient($gated), 'Ziel muss einen Patienten voraussetzen: ' . $gated);
        }

        foreach ([
            '/', '/reports', '/reports/1', '/imports', '/patients', '/patients/new',
            '/patient-cards', '/patient-cards/settings', '/letters', '/letters/1', '/system',
        ] as $open) {
            $this->assertFalse(Ribbon::requiresPatient($open), 'Ziel darf keinen Patienten voraussetzen: ' . $open);
        }
    }

    // Oberflaeche 11. Die gesperrten Ziele sind genau die Ziele des Funktionsbandes,
    // die einen Patientenbezug herstellen – Listen und Berichte bleiben erreichbar.
    public function testGatedTargetsExistInTheRibbon(): void
    {
        $targets = Ribbon::patientRequiredTargets();
        sort($targets);

        $this->assertSame(['/import', '/letters/new', '/patient-cards/new'], $targets);

        $hrefs = [];
        foreach (Ribbon::tabs() as $tab) {
            $hrefs[] = $tab['href'];
            foreach ($tab['groups'] as $group) {
                foreach ($group['items'] as $item) {
                    $hrefs[] = $item['href'];
                }
            }
        }
        foreach (Ribbon::quickAccess() as $item) {
            $hrefs[] = $item['href'];
        }

        foreach ($targets as $target) {
            $this->assertTrue(in_array($target, $hrefs, true), 'Gesperrtes Ziel fehlt im Funktionsband: ' . $target);
        }
    }
}
