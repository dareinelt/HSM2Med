<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Ribbon;
use App\User\Permission;
use Tests\TestCase;

/**
 * Rechtematrix: Vollstaendigkeit, Zuordnung von Pfaden und Normalisierung.
 *
 * Die Zuordnung Pfad -> Recht ist die einzige Quelle fuer Sperren (Router/Kernel) und fuer die
 * Anzeige (Funktionsband, Schnellzugriff). Weichen beide auseinander, sieht man Schaltflaechen,
 * die nicht funktionieren – oder umgekehrt. Deshalb wird sie hier vollstaendig geprueft.
 */
final class PermissionTest extends TestCase
{
    public function testCatalogMatchesRibbonSections(): void
    {
        $sections = array_keys(Ribbon::sections());
        $permissions = Permission::all();

        $this->assertSame([], array_values(array_diff($sections, $permissions)), 'Bereich ohne Recht.');
        $this->assertSame([], array_values(array_diff($permissions, $sections)), 'Recht ohne Bereich.');
    }

    public function testEveryPermissionIsDescribed(): void
    {
        foreach (Permission::all() as $permission) {
            $this->assertTrue(Permission::label($permission) !== '', 'Beschriftung fehlt: ' . $permission);
            $this->assertTrue(Permission::description($permission) !== '', 'Beschreibung fehlt: ' . $permission);
            $this->assertTrue(Permission::isKnown($permission));
        }
        $this->assertSame(13, count(Permission::all()));
        $this->assertFalse(Permission::isKnown(Permission::ACCOUNT), 'Das eigene Kennwort ist kein Recht.');
        $this->assertFalse(Permission::isKnown('unbekannt'));
        $this->assertSame('', Permission::label('unbekannt'));
        $this->assertSame('', Permission::description('unbekannt'));
    }

    public function testForPathMapsEveryAreaToItsPermission(): void
    {
        $cases = [
            '/' => Permission::DASHBOARD,
            '/import' => Permission::IMPORT,
            '/import/abc' => Permission::IMPORT,
            '/imports' => Permission::IMPORTS,
            '/imports/7' => Permission::IMPORTS,
            '/reports' => Permission::REPORTS,
            '/reports/7' => Permission::REPORTS,
            '/patients' => Permission::PATIENTS,
            '/patients/new' => Permission::PATIENTS,
            '/patient-cards' => Permission::PATIENT_CARDS,
            '/patient-cards/new' => Permission::PATIENT_CARDS,
            '/patient-cards/settings' => Permission::PATIENT_CARD_SETTINGS,
            '/letters' => Permission::LETTERS,
            '/letters/new' => Permission::LETTERS,
            '/system' => Permission::SYSTEM,
            '/system/settings' => Permission::SYSTEM_SETTINGS,
            '/system/letter-templates' => Permission::LETTER_TEMPLATES,
            '/system/logs' => Permission::LOGS,
            '/system/users' => Permission::USERS,
            '/system/users/new' => Permission::USERS,
            '/system/users/groups/2' => Permission::USERS,
            // Das eigene Kennwort ist unabhaengig von der Gruppe erlaubt.
            '/account/password' => null,
            '/unbekannt' => null,
        ];

        foreach ($cases as $path => $expected) {
            $this->assertSame($expected, Permission::forPath($path), 'Pfad: ' . $path);
        }
    }

    public function testSpecificPathsWinOverTheirPrefixes(): void
    {
        // Reihenfolge der Pruefungen ist bindend: /imports vor /import, /system/<bereich> vor /system.
        $this->assertSame(Permission::IMPORTS, Permission::forPath('/imports'));
        $this->assertSame(Permission::PATIENT_CARD_SETTINGS, Permission::forPath('/patient-cards/settings'));
        $this->assertSame(Permission::LETTER_TEMPLATES, Permission::forPath('/system/letter-templates'));
        $this->assertSame(Permission::SYSTEM_SETTINGS, Permission::forPath('/system/settings'));
        $this->assertSame(Permission::USERS, Permission::forPath('/system/users'));
        $this->assertSame(Permission::LOGS, Permission::forPath('/system/logs'));
    }

    public function testPathsAreNormalized(): void
    {
        $this->assertSame(Permission::IMPORT, Permission::forPath('import'));
        $this->assertSame(Permission::IMPORT, Permission::forPath('//import'));
        $this->assertSame(Permission::DASHBOARD, Permission::forPath(''));
    }

    public function testNormalizeDropsUnknownEntriesAndDuplicates(): void
    {
        $normalized = Permission::normalize([
            Permission::IMPORT,
            'unbekannt',
            Permission::IMPORT,
            '',
            Permission::USERS,
            7,
            Permission::ACCOUNT,
        ]);

        $this->assertSame([Permission::IMPORT, Permission::USERS], $normalized);
        $this->assertSame([], Permission::normalize([]));
    }
}
