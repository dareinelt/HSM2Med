<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application;
use App\Config\Config;
use App\Http\Controller\PatientController;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\View;
use App\Patient\PatientRecordType;

/**
 * Rendert die Oberflaechen der Patientenakte ueber die echten Controller und Templates.
 *
 * Schuetzt vor Fehlern, die ausschliesslich in der HTML-Schicht auftreten: unbekannte
 * Klassenreferenzen in Templates, fehlende Template-Variablen, unvollstaendige Formulare
 * und Fehlerseiten, die sonst erst im Browser auffallen.
 */
final class PatientViewTest extends DatabaseTestCase
{
    private Application $app;

    public function setUp(): void
    {
        parent::setUp();
        $this->app = new Application(Config::fromEnvironment(), dirname(__DIR__, 2), $this->clock);
    }

    private function patients(): PatientController
    {
        return new PatientController($this->app, new View(dirname(__DIR__, 2) . '/templates'));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function post(array $overrides = []): array
    {
        return array_replace([
            'last_name' => 'Mustermann',
            'first_name' => 'Erika',
            'date_of_birth' => '21.10.1938',
            'patient_identifier' => 'P-100',
            'street' => 'Musterstraße 12',
            'postal_code' => '12345',
            'city' => 'Beispielstadt',
            'phone' => '01234/56789',
            'indication' => 'Bradykardie, geplante Schrittmacherimplantation',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function create(array $overrides = []): int
    {
        $response = $this->patients()->create(new Request('POST', '/patients', [], $this->post($overrides)));
        $location = (string) ($response->headers['Location'] ?? '');
        return (int) substr($location, (int) strrpos($location, '/') + 1);
    }

    /** Uebersicht: leerer Zustand und gefuellte Liste mit Kennzahlen der Akte. */
    public function testIndexRendersEmptyAndFilled(): void
    {
        $empty = $this->patients()->index(new Request('GET', '/patients'));

        $this->assertSame(200, $empty->status);
        $this->assertContains('0 Patient(en) gefunden.', $empty->body);
        $this->assertContains('Kein Patient gefunden.', $empty->body);
        $this->assertContains('href="/patients/new"', $empty->body);

        $patientId = $this->create();

        $filled = $this->patients()->index(new Request('GET', '/patients', ['q' => 'Mustermann']));
        $this->assertSame(200, $filled->status);
        $this->assertContains('1 Patient(en) gefunden.', $filled->body);
        $this->assertContains('href="/patients/' . $patientId . '"', $filled->body);
        $this->assertContains('21.10.1938', $filled->body);
        $this->assertContains('P-100', $filled->body);
    }

    /** Suchfilter: ungueltiges Geburtsdatum wird gemeldet, gueltiges filtert. */
    public function testIndexReportsInvalidBirthFilter(): void
    {
        $this->create();

        $invalid = $this->patients()->index(new Request('GET', '/patients', ['birth' => '31.02.1938']));
        $this->assertContains('Das Geburtsdatum im Filter ist ungültig', $invalid->body);

        $valid = $this->patients()->index(new Request('GET', '/patients', ['birth' => '21.10.1938']));
        $this->assertContains('1 Patient(en) gefunden.', $valid->body);

        $noMatch = $this->patients()->index(new Request('GET', '/patients', ['birth' => '01.01.1970']));
        $this->assertContains('0 Patient(en) gefunden.', $noMatch->body);
    }

    /** Formular der Neuanlage: CSRF-Feld, Pflichtfelder und Hinweis auf die Bausteine. */
    public function testNewFormRenders(): void
    {
        $response = $this->patients()->newForm(new Request('GET', '/patients/new'));

        $this->assertSame(200, $response->status);
        $this->assertContains('Patient anlegen', $response->body);
        $this->assertContains('name="_csrf"', $response->body);
        $this->assertContains('name="last_name"', $response->body);
        $this->assertContains('name="date_of_birth"', $response->body);
        $this->assertContains('Patienten können unabhängig von einem Import angelegt werden.', $response->body);
    }

    /** Anlegen ohne Import: Weiterleitung in die Akte, Patient und Stammdaten gespeichert. */
    public function testCreateRedirectsToRecord(): void
    {
        $response = $this->patients()->create(new Request('POST', '/patients', [], $this->post()));

        $this->assertSame(303, $response->status);
        $this->assertContains('/patients/', (string) $response->headers['Location']);
        $this->assertSame(1, $this->rowCount('patients'));
        $this->assertSame(0, $this->rowCount('reports'));
        $this->assertSame(1, $this->rowCount('patient_card_master_data'));
    }

    /** Pflichtfelder fehlen: 422 mit feldbezogenen Meldungen, kein Patient. */
    public function testCreateRejectsIncompleteInput(): void
    {
        $response = $this->patients()->create(new Request('POST', '/patients', [], $this->post([
            'last_name' => '',
            'date_of_birth' => '31.02.1938',
        ])));

        $this->assertSame(422, $response->status);
        $this->assertContains('Der Nachname ist erforderlich.', $response->body);
        $this->assertContains('Das Geburtsdatum ist ungültig', $response->body);
        $this->assertSame(0, $this->rowCount('patients'));
    }

    /** Dublette: 422 mit Trefferliste und Bestaetigungsfeld. */
    public function testCreateRendersDuplicateWarning(): void
    {
        $patientId = $this->create();

        $response = $this->patients()->create(new Request('POST', '/patients', [], $this->post([
            'patient_identifier' => '',
        ])));

        $this->assertSame(422, $response->status);
        $this->assertContains('Mögliche Dublette', $response->body);
        $this->assertContains('name="confirm_duplicate"', $response->body);
        $this->assertContains('/patients/' . $patientId, $response->body);
        $this->assertSame(1, $this->rowCount('patients'));
    }

    /** Dublette bestaetigt: zweiter Patient wird angelegt. */
    public function testCreateAcceptsConfirmedDuplicate(): void
    {
        $this->create();

        $response = $this->patients()->create(new Request('POST', '/patients', [], $this->post([
            'patient_identifier' => '',
            'confirm_duplicate' => '1',
        ])));

        $this->assertSame(303, $response->status);
        $this->assertSame(2, $this->rowCount('patients'));
    }

    /** Patienten-ID doppelt: 422 mit Meldung am Feld. */
    public function testCreateRejectsUsedIdentifier(): void
    {
        $this->create();

        $response = $this->patients()->create(new Request('POST', '/patients', [], $this->post([
            'last_name' => 'Anders',
            'first_name' => 'Anna',
            'date_of_birth' => '01.01.1970',
        ])));

        $this->assertSame(422, $response->status);
        $this->assertContains('Die Patienten-ID ist bereits einem anderen Patienten zugeordnet.', $response->body);
    }

    /** Akte: Stammdaten, alle Bausteine im Ausgangszustand und Hinweis auf den Import. */
    public function testShowRendersMasterDataAndEmptyRecords(): void
    {
        $patientId = $this->create();

        $response = $this->patients()->show(new Request('GET', '/patients/' . $patientId), ['id' => (string) $patientId]);

        $this->assertSame(200, $response->status);
        $this->assertContains('Mustermann, Erika', $response->body);
        $this->assertContains('Patient Nr. ' . $patientId, $response->body);
        $this->assertContains('geboren am 21.10.1938', $response->body);
        $this->assertContains('Musterstraße 12', $response->body);
        $this->assertContains('Bradykardie, geplante Schrittmacherimplantation', $response->body);
        foreach (['Anamnese', 'Vormedikation', 'Epikrise', 'Notiz'] as $label) {
            $this->assertContains($label, $response->body);
        }
        $this->assertContains('Noch nicht erfasst.', $response->body);
        $this->assertContains('Noch kein Bericht mit diesem Patienten verknüpft.', $response->body);
        $this->assertContains('/patients/' . $patientId . '/records/anamnesis', $response->body);
    }

    /** Bearbeitungsformular ist vorbelegt und speichert fort. */
    public function testEditAndUpdate(): void
    {
        $patientId = $this->create();

        $form = $this->patients()->editForm(new Request('GET', '/patients/' . $patientId . '/edit'), ['id' => (string) $patientId]);
        $this->assertSame(200, $form->status);
        $this->assertContains('value="Mustermann"', $form->body);
        $this->assertContains('value="21.10.1938"', $form->body);
        $this->assertContains('value="Beispielstadt"', $form->body);

        $response = $this->patients()->update(
            new Request('POST', '/patients/' . $patientId, [], $this->post(['city' => 'Anderstadt'])),
            ['id' => (string) $patientId],
        );

        $this->assertSame(303, $response->status);
        $this->assertContains('Anderstadt', $this->app->patientService()->masterData($patientId)['city']);
        $this->assertSame(1, $this->rowCount('patients'));
    }

    /** Bearbeiten eines unbekannten Patienten endet in 404. */
    public function testUpdateRejectsInvalidInput(): void
    {
        $patientId = $this->create();

        $response = $this->patients()->update(
            new Request('POST', '/patients/' . $patientId, [], $this->post(['first_name' => ''])),
            ['id' => (string) $patientId],
        );

        $this->assertSame(422, $response->status);
        $this->assertContains('Der Vorname ist erforderlich.', $response->body);
    }

    /** Baustein der Akte: Formular, Wechsel zwischen den Bausteinen und leere Historie. */
    public function testRecordFormRenders(): void
    {
        $patientId = $this->create();

        $response = $this->patients()->recordForm(
            new Request('GET', '/patients/' . $patientId . '/records/anamnesis'),
            ['id' => (string) $patientId, 'slug' => 'anamnesis'],
        );

        $this->assertSame(200, $response->status);
        $this->assertContains('<h1>Anamnese</h1>', $response->body);
        $this->assertContains('name="_csrf"', $response->body);
        $this->assertContains('name="text"', $response->body);
        $this->assertContains('name="author_name"', $response->body);
        $this->assertContains('Als neue Fassung speichern', $response->body);
        $this->assertContains('Noch keine Fassung vorhanden.', $response->body);
        $this->assertContains('/patients/' . $patientId . '/records/epicrisis', $response->body);
    }

    /** Vormedikation: Arzneimittelzeilen, Vorlage fuer neue Zeilen und Grenze der Zeilen. */
    public function testPremedicationRecordFormRendersTable(): void
    {
        $patientId = $this->create();

        $response = $this->patients()->recordForm(
            new Request('GET', '/patients/' . $patientId . '/records/premedication'),
            ['id' => (string) $patientId, 'slug' => 'premedication'],
        );

        $this->assertSame(200, $response->status);
        $this->assertContains('name="medication[0][substance]"', $response->body);
        $this->assertContains('name="medication[__INDEX__][substance]"', $response->body);
        $this->assertContains('data-repeat-add', $response->body);
        $this->assertContains('Weitere Zeile', $response->body);
        $this->assertContains('Der Wirkstoff ist je Zeile erforderlich.', $response->body);
        $this->assertContains('Ergänzungen zur Vormedikation', $response->body);
    }

    /** Baustein speichern: Weiterleitung, neue Fassung und Anzeige in Akte und Historie. */
    public function testSaveRecordCreatesVersion(): void
    {
        $patientId = $this->create();

        $response = $this->patients()->saveRecord(
            new Request('POST', '/patients/' . $patientId . '/records/anamnesis', [], [
                'text' => 'Belastungsdyspnoe seit 3 Monaten.',
                'author_name' => 'Dr. Beispiel',
            ]),
            ['id' => (string) $patientId, 'slug' => 'anamnesis'],
        );

        $this->assertSame(303, $response->status);
        $this->assertSame(1, $this->rowCount('patient_records'));
        $this->assertSame(1, $this->rowCount('patient_record_versions'));

        $form = $this->patients()->recordForm(
            new Request('GET', '/patients/' . $patientId . '/records/anamnesis'),
            ['id' => (string) $patientId, 'slug' => 'anamnesis'],
        );
        $this->assertContains('Belastungsdyspnoe seit 3 Monaten.', $form->body);
        $this->assertContains('Fassung 1, vom 07.10.2026, erfasst von Dr. Beispiel', $form->body);
        $this->assertContains('>aktuell<', $form->body);

        $show = $this->patients()->show(new Request('GET', '/patients/' . $patientId), ['id' => (string) $patientId]);
        $this->assertContains('Fassung 1, vom 07.10.2026, erfasst von Dr. Beispiel', $show->body);
        $this->assertContains('Zeichen Freitext', $show->body);
    }

    /** Leerer Baustein: 422 mit Meldung, keine Fassung. */
    public function testSaveRecordRejectsEmptyInput(): void
    {
        $patientId = $this->create();

        $response = $this->patients()->saveRecord(
            new Request('POST', '/patients/' . $patientId . '/records/epicrisis', [], ['text' => '   ']),
            ['id' => (string) $patientId, 'slug' => 'epicrisis'],
        );

        $this->assertSame(422, $response->status);
        $this->assertContains('Bitte den Inhalt des Bausteins angeben.', $response->body);
        $this->assertSame(0, $this->rowCount('patient_record_versions'));
    }

    /** Vormedikation speichern: Zeilen werden uebernommen und in der Akte gezaehlt. */
    public function testSavePremedicationStoresEntries(): void
    {
        $patientId = $this->create();

        $response = $this->patients()->saveRecord(
            new Request('POST', '/patients/' . $patientId . '/records/premedication', [], [
                'text' => '',
                'author_name' => '',
                'medication' => [
                    ['substance' => 'Bisoprolol', 'dose' => '2,5', 'unit' => 'mg', 'schedule' => '1-0-0', 'reason' => 'Bradykardie', 'from' => '01.03.2024', 'to' => ''],
                    ['substance' => '', 'dose' => '', 'unit' => '', 'schedule' => '', 'reason' => '', 'from' => '', 'to' => ''],
                ],
            ]),
            ['id' => (string) $patientId, 'slug' => 'premedication'],
        );

        $this->assertSame(303, $response->status);

        $show = $this->patients()->show(new Request('GET', '/patients/' . $patientId), ['id' => (string) $patientId]);
        $this->assertContains('Bisoprolol', $show->body);
        $this->assertContains('1 Zeile(n) Medikation', $show->body);

        $form = $this->patients()->recordForm(
            new Request('GET', '/patients/' . $patientId . '/records/premedication'),
            ['id' => (string) $patientId, 'slug' => 'premedication'],
        );
        $this->assertContains('value="01.03.2024"', $form->body, 'Datum wird im Formular als TT.MM.JJJJ vorbelegt.');
        $this->assertContains('value="2,5"', $form->body);
    }

    /** Unbekannte Kennungen und Bausteine liefern 404 statt einer Fehlerseite. */
    public function testUnknownIdsAndSlugsAreNotFound(): void
    {
        $patientId = $this->create();

        $this->assertThrows(
            HttpException::class,
            fn () => $this->patients()->show(new Request('GET', '/patients/9999'), ['id' => '9999']),
        );
        $this->assertThrows(
            HttpException::class,
            fn () => $this->patients()->recordForm(
                new Request('GET', '/patients/' . $patientId . '/records/unbekannt'),
                ['id' => (string) $patientId, 'slug' => 'unbekannt'],
            ),
        );
        $this->assertThrows(
            HttpException::class,
            fn () => $this->patients()->recordForm(
                new Request('GET', '/patients/9999/records/anamnesis'),
                ['id' => '9999', 'slug' => 'anamnesis'],
            ),
        );
    }

    /** Antworttypen sind unveraendert HTML. */
    public function testResponsesAreHtml(): void
    {
        $response = $this->patients()->index(new Request('GET', '/patients'));

        $this->assertSame('text/html; charset=UTF-8', $response->headers['Content-Type'] ?? '');
        $this->assertTrue($response instanceof Response);
    }

    /** Alle Bausteintypen sind ueber die Oberflaeche erreichbar. */
    public function testEveryRecordTypeIsReachable(): void
    {
        $patientId = $this->create();

        foreach (PatientRecordType::all() as $type) {
            $response = $this->patients()->recordForm(
                new Request('GET', '/patients/' . $patientId . '/records/' . $type->value),
                ['id' => (string) $patientId, 'slug' => $type->value],
            );
            $this->assertSame(200, $response->status, 'Baustein ' . $type->value . ' ist nicht erreichbar.');
            $this->assertContains('<h1>' . $type->label() . '</h1>', $response->body);
        }
    }
}
