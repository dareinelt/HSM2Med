<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Import\ImportOutcome;
use App\PatientCard\MeasurementTemplate;
use App\PatientCard\PatientCardException;
use App\PatientCard\PatientCardInput;
use App\PatientCard\PatientCardPdfGenerator;
use App\PatientCard\PatientCardRepository;
use App\PatientCard\PatientCardService;
use App\PatientCard\PatientCardSettingsService;
use App\PatientCard\PatientCardTemplateRepository;
use App\PatientCard\PatientCardTemplateService;
use App\Security\ImageUploadValidator;
use Tests\Support\Fixtures;
use Tests\Support\Images;
use Tests\Support\PdfText;

/**
 * Patientenausweis gegen die Testdatenbank: Identitaet, Datenuebernahme, Konflikte,
 * Bestaetigungen, Verlauf, Stammdatenfassungen und Unveraenderlichkeit der PDFs.
 */
final class PatientCardTest extends DatabaseTestCase
{
    private function repository(): PatientCardRepository
    {
        return new PatientCardRepository($this->pdo);
    }

    private function service(): PatientCardService
    {
        return new PatientCardService(
            $this->pdo,
            $this->repository(),
            $this->reportService(),
            new PatientCardPdfGenerator(),
            $this->clock,
            MeasurementTemplate::default(dirname(__DIR__, 2)),
            new PatientCardTemplateService(new PatientCardTemplateRepository($this->pdo), $this->clock),
        );
    }

    private function settingsService(): PatientCardSettingsService
    {
        return new PatientCardSettingsService($this->repository(), new ImageUploadValidator(), $this->clock);
    }

    private function importSample(?string $bytes = null, string $filename = 'MERLIN__ANN_5809481.log'): ImportOutcome
    {
        $service = $this->importService();
        $bytes ??= Fixtures::sampleFile();
        return $service->import($service->analyze($bytes, $filename), $bytes);
    }

    /**
     * Spaeterer Bericht desselben Patienten (anderer Auslesezeitpunkt, andere Datei).
     */
    private function importLaterSample(string $timestamp = '11/20/2026 07:03:24'): ImportOutcome
    {
        $bytes = str_replace('10/07/2026 07:03:24', $timestamp, Fixtures::sampleFile());
        return $this->importSample($bytes, 'zweite-auslesung.log');
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function post(array $overrides = []): array
    {
        return array_replace([
            'last_name' => 'LASTNAME',
            'first_name' => 'FIRSTNAME',
            'date_of_birth' => '21.10.1938',
            'street' => 'Musterstraße 12',
            'postal_code' => '12345',
            'city' => 'Beispielstadt',
            'phone' => '01234/56789',
            'indication' => 'Bradykardie',
            'device_implant_location' => 'links pektoral',
            'mrt_compatibility' => 'MRT-bedingt tauglich',
            'mrt_compatibility_note' => 'Nur mit Auflagen, jährliche Kontrolle der Sonde.',
            'emergency_contact_name' => 'Angehörige Beispielperson',
            'emergency_contact_phone' => '0170/1234567',
            'physician_name' => 'Dr. med. Hausarzt',
            'physician_practice' => 'Gemeinschaftspraxis am Markt',
            'physician_postal_code' => '12345',
            'physician_city' => 'Beispielstadt',
            'physician_phone' => '01234/11111',
            'control_physician' => 'Dr. med. Kontrolle',
            'next_control_date' => '07.04.2027',
            'confirm_patient' => '1',
            'confirm_merge' => '1',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function configureSettings(array $overrides = []): int
    {
        return $this->settingsService()->save(array_replace([
            'center_name' => 'Nachsorgezentrum Beispielstadt',
            'center_address' => "Musterweg 5\n12345 Beispielstadt\nTelefon 01234/56789",
            'notice_text' => 'Dieser Ausweis enthält Angaben zum implantierten Schrittmachersystem.',
            'flight_notice_de' => 'Das Gerät kann Metalldetektoren auslösen.',
            'flight_notice_en' => 'The device may trigger metal detectors.',
        ], $overrides), null, false)['version_id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(int $cardId): array
    {
        $card = $this->service()->card($cardId) ?? $this->fail('Ausweis nicht gefunden.');
        return json_decode((string) $card['snapshot'], true, 512, JSON_THROW_ON_ERROR);
    }

    /** Test 1: Aus dem Bericht erzeugter Ausweis enthaelt alle Pflichtangaben und ist unveraenderlich archiviert. */
    public function testCreatesCardFromReport(): void
    {
        $this->configureSettings();
        $outcome = $this->importSample();
        $service = $this->service();

        $wizard = $service->wizard($outcome->reportId);
        $this->assertSame('LASTNAME', $wizard['values']['last_name']);
        $this->assertSame('FIRSTNAME', $wizard['values']['first_name']);
        $this->assertSame('1938-10-21', $wizard['values']['date_of_birth']);
        $this->assertCount(1, $wizard['candidates'], 'Der Import hat den Patienten bereits angelegt');
        $this->assertSame('LASTNAME', $wizard['candidates'][0]['last_name']);
        $this->assertNull($wizard['existingCard']);
        $this->assertTrue($wizard['settingsConfigured']);

        $result = $service->create(PatientCardInput::fromPost($this->post()), $wizard['report'], $wizard['masterData']);
        $this->assertFalse($result['created'], 'Der vorhandene Patient wird wiederverwendet');
        $this->assertSame((int) $wizard['candidates'][0]['id'], $result['patient_id']);
        $this->assertSame(1, $result['sequence_no']);
        $this->assertSame(1, $this->rowCount('patient_cards'));
        $this->assertSame(1, $this->rowCount('patients'));
        $this->assertSame(1, $this->rowCount('patient_card_master_data'));

        $card = $service->card($result['card_id'], true);
        $this->assertTrue($card !== null);
        $this->assertSame('LASTNAME, FIRSTNAME', $card['patient_name']);
        $this->assertSame('LASTNAME', $card['last_name']);
        $this->assertSame('FIRSTNAME', $card['first_name']);
        $this->assertSame('1938-10-21', $card['date_of_birth']);
        $this->assertSame('2026-10-07', $card['follow_up_date']);
        // card_version ist der Zaehler je Bericht, nicht die Layoutfassung
        $this->assertSame(1, (int) $card['card_version']);
        $this->assertSame('Patientenausweis_LASTNAME_FIRSTNAME_2026-10-07_Nr1.pdf', $card['pdf_filename']);
        $this->assertSame(hash('sha256', $card['pdf_content']), $card['pdf_sha256']);
        $this->assertSame(strlen($card['pdf_content']), (int) $card['pdf_size']);
        $this->assertSame('2026-10-07 08:00:00', $card['created_at']);

        // PDF: zwei DIN-A4-Seiten mit allen Angaben
        $pdf = (string) $card['pdf_content'];
        $this->assertSame(2, PdfText::pageCount($pdf));
        $text = PdfText::text($pdf);
        foreach ([
            'Schrittmacher - Patientenausweis', 'Patient Identification Card',
            'LASTNAME, FIRSTNAME', '21.10.1938', 'Musterstraße 12', '12345 Beispielstadt',
            'Bradykardie', 'Endurity Core', '5809481', 'links pektoral',
            'MRT-Tauglichkeit:',
            'EEM126412', 'EEL193668',
            'Nachsorgezentrum Beispielstadt', 'Dr. med. Kontrolle', 'Dr. med. Hausarzt',
            'Angehörige Beispielperson', '07.04.2027',
            'Dieser Ausweis enthält Angaben zum implantierten Schrittmachersystem.',
            'Das Gerät kann Metalldetektoren auslösen.',
            'The device may trigger metal detectors.',
            'Bericht Nr. ' . $outcome->reportId,
            'Seite 1 von 2', 'Seite 2 von 2',
        ] as $expected) {
            $this->assertContains($expected, $text);
        }

        // MRT-Tauglichkeit: Auswahlwert und Zusatzangabe stehen auf Seite 1 und in den Stammdaten.
        $this->assertContains(
            'MRT-Tauglichkeit: MRT-bedingt tauglich (Nur mit Auflagen, jährliche Kontrolle der Sonde.)',
            str_replace("\n", ' ', $text),
        );
        $master = $this->pdo->query('SELECT mrt_compatibility, mrt_compatibility_note FROM patient_card_master_data')
            ->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('MRT-bedingt tauglich', $master['mrt_compatibility']);
        $this->assertSame('Nur mit Auflagen, jährliche Kontrolle der Sonde.', $master['mrt_compatibility_note']);

        // Snapshot
        $snapshot = $this->snapshot($result['card_id']);
        $this->assertSame('2.0', $snapshot['patient_card_version']);
        $this->assertSame(2, $snapshot['card_version']);
        $this->assertSame('Musterstraße 12', $snapshot['patient']['street']);
        $this->assertSame('10358141', $snapshot['patient']['patient_identifier']);
        $this->assertSame('Endurity Core', $snapshot['device']['model_name']);
        $this->assertSame('MRT-bedingt tauglich', $snapshot['device']['mrt_compatibility']);
        $this->assertSame('Nur mit Auflagen, jährliche Kontrolle der Sonde.', $snapshot['device']['mrt_compatibility_note']);
        $this->assertCount(2, $snapshot['leads']);
        $this->assertSame('Dr. med. Hausarzt', $snapshot['physician']['name']);
        $this->assertSame('2027-04-07', $snapshot['follow_up']['next_control_date']);
        $this->assertSame($outcome->importId, $snapshot['source']['import_id']);

        // Messwerttabelle: Vorlage 1:1, nur die aktuelle Untersuchung als Spalte
        $this->assertSame('1.0.0', $snapshot['measurements']['template_version']);
        $this->assertSame(7, $snapshot['measurements']['column_count']);
        $this->assertCount(1, $snapshot['measurements']['columns']);
        $this->assertTrue($snapshot['measurements']['columns'][0]['current']);
        $this->assertSame('07.10.2026', $snapshot['measurements']['columns'][0]['date_display']);
        $this->assertCount(2, $snapshot['measurements']['sections']);
        $this->assertSame('Messungen', $snapshot['measurements']['sections'][0]['label']);
        $this->assertSame('Programmierung', $snapshot['measurements']['sections'][1]['label']);

        $pageTwo = PdfText::pages($pdf)[1];
        $this->assertContains('Messungen', $pageTwo);
        $this->assertContains('Programmierung', $pageTwo);
        $this->assertContains('07.10.2026', $pageTwo);
        $this->assertContains('(aktuelle Untersuchung)', $pageTwo);
        $this->assertContains('Betriebsart', $pageTwo);
        $this->assertContains('VVI', $pageTwo);
        $this->assertContains('60', $pageTwo);
        $this->assertNotContains('keine Messwerte', $pageTwo);

        // Stammdaten und Verknuepfung
        $master = $this->repository()->masterData($result['patient_id']);
        $this->assertSame('Musterstraße 12', $master['street']);
        $this->assertSame('0170/1234567', $master['emergency_contact_phone']);
        $this->assertSame('2027-04-07', $master['next_control_date']);
        $this->assertSame($result['patient_id'], $this->repository()->reportPatientId($outcome->reportId));
    }

    /** Test 2: Gleiche Identitaet (Name + Geburtsdatum) wird wiederverwendet; der Verlauf waechst. */
    public function testSameIdentityReusesPatientAndListsHistory(): void
    {
        $this->configureSettings();
        $first = $this->importSample();
        $service = $this->service();
        $card1 = $service->create(PatientCardInput::fromPost($this->post()), $service->loadReport($first->reportId), null);
        $pdf1 = $service->card($card1['card_id'], true)['pdf_content'];
        $pageTwo1 = PdfText::pages((string) $pdf1)[1];
        $this->assertContains('Messungen', $pageTwo1);
        $this->assertContains('07.10.2026', $pageTwo1);
        $this->assertContains('(aktuelle Untersuchung)', $pageTwo1);
        $this->assertNotContains('Es sind keine Messwerte dieses Patienten gespeichert.', $pageTwo1);

        $second = $this->importLaterSample();
        $this->assertSame(2, $this->rowCount('reports'));
        $this->assertSame(1, $this->rowCount('patients'), 'Import legt keinen zweiten Patienten an');

        $service2 = $this->service();
        $wizard = $service2->wizard($second->reportId);
        $this->assertCount(1, $wizard['candidates'], 'Identitaet ueber Name und Geburtsdatum gefunden');
        $this->assertSame('Musterstraße 12', $wizard['values']['street'], 'Vorhandene Angaben werden vorgeschlagen');

        $card2 = $service2->create(PatientCardInput::fromPost($this->post()), $wizard['report'], $wizard['masterData']);
        $this->assertFalse($card2['created']);
        $this->assertSame($card1['patient_id'], $card2['patient_id']);
        $this->assertSame(2, $card2['sequence_no']);
        $this->assertSame(2, $this->rowCount('patient_cards'));

        $pdf2 = (string) $service2->card($card2['card_id'], true)['pdf_content'];
        $pageTwo2 = PdfText::pages($pdf2)[1];
        $this->assertContains('Messungen', $pageTwo2);
        $this->assertContains('20.11.2026', $pageTwo2, 'Spalte der aktuellen Untersuchung');
        $this->assertContains('07.10.2026', $pageTwo2, 'Spalte der frueheren Untersuchung');
        $this->assertContains('(aktuelle Untersuchung)', $pageTwo2);
        $this->assertNotContains('Es sind keine Messwerte dieses Patienten gespeichert.', $pageTwo2);

        $snapshot2 = $this->snapshot($card2['card_id']);
        $this->assertCount(2, $snapshot2['measurements']['columns']);
        $this->assertTrue($snapshot2['measurements']['columns'][0]['current']);
        $this->assertFalse($snapshot2['measurements']['columns'][1]['current']);
        $this->assertSame($second->reportId, $snapshot2['measurements']['columns'][0]['report_id']);
        $this->assertSame($first->reportId, $snapshot2['measurements']['columns'][1]['report_id']);
        $this->assertNotSame(hash('sha256', (string) $pdf1), hash('sha256', $pdf2));

        // Der erste Ausweis bleibt unveraendert erhalten
        $this->assertSame($pdf1, $service2->card($card1['card_id'], true)['pdf_content']);
        $this->assertCount(2, $service2->cardsForPatient($card1['patient_id']));
    }

    /** Test 3: Abweichendes Geburtsdatum ergibt einen anderen Patienten. */
    public function testDifferentDateOfBirthCreatesNewPatient(): void
    {
        $first = $this->importSample();
        $service = $this->service();
        $card1 = $service->create(PatientCardInput::fromPost($this->post()), $service->loadReport($first->reportId), null);

        $second = $this->importLaterSample();
        $service2 = $this->service();
        $wizard = $service2->wizard($second->reportId);
        $input = PatientCardInput::fromPost($this->post(['date_of_birth' => '22.10.1938']));
        $this->assertSame([], $service2->candidates($input), 'Geburtsdatum gehoert zur Identitaet');

        $card2 = $service2->create($input, $wizard['report'], $wizard['masterData']);
        $this->assertTrue($card2['created']);
        $this->assertNotSame($card1['patient_id'], $card2['patient_id']);
        $this->assertSame(2, $this->rowCount('patients'));
        $this->assertNull(
            $this->repository()->patient($card2['patient_id'])['patient_identifier'],
            'Die Patienten-ID ist bereits vergeben und wird nicht doppelt gespeichert',
        );
    }

    /** Test 4: Mehrere Patienten mit gleicher Identitaet erzwingen eine Auswahl. */
    public function testMultipleCandidatesRequireExplicitSelection(): void
    {
        $outcome = $this->importSample();
        $this->repository()->createPatient(
            '99999999',
            'LASTNAME, FIRSTNAME',
            'LASTNAME',
            'FIRSTNAME',
            '1938-10-21',
            '10/21/1938 00:00:00',
            '2026-10-07 08:00:00',
        );

        $service = $this->service();
        $wizard = $service->wizard($outcome->reportId);
        $this->assertCount(2, $wizard['candidates']);

        $exception = $this->assertThrows(
            PatientCardException::class,
            fn () => $service->create(PatientCardInput::fromPost($this->post()), $wizard['report'], $wizard['masterData']),
        );
        $this->assertTrue(isset($exception->fieldErrors()['patient_id']), 'Auswahl wird verlangt');
        $this->assertSame(0, $this->rowCount('patient_cards'), 'Ohne Auswahl entsteht kein Ausweis');

        $selected = (int) $wizard['candidates'][1]['id'];
        $card = $service->create(
            PatientCardInput::fromPost($this->post(['patient_id' => (string) $selected])),
            $wizard['report'],
            $wizard['masterData'],
            $selected,
        );
        $this->assertSame($selected, $card['patient_id']);
        $this->assertFalse($card['created']);
        $this->assertSame(2, $this->rowCount('patients'));
    }

    /** Test 5: Abweichungen werden angezeigt und nicht still ueberschrieben. */
    public function testConflictsAreShownAndDecidedPerField(): void
    {
        $this->configureSettings();
        $first = $this->importSample();
        $service = $this->service();
        $card1 = $service->create(PatientCardInput::fromPost($this->post()), $service->loadReport($first->reportId), null);

        $second = $this->importLaterSample();
        $service2 = $this->service();
        $wizard = $service2->wizard($second->reportId);
        $changed = $this->post([
            'street' => 'Neue Straße 9',
            'physician_name' => 'Dr. med. Neu',
            'emergency_contact_phone' => '0170/9999999',
        ]);
        $input = PatientCardInput::fromPost($changed);

        $conflicts = $service2->conflicts($input, $wizard['masterData']);
        $this->assertSame(['street', 'emergency_contact_phone', 'physician_name'], array_column($conflicts, 'field'));
        $this->assertSame('Musterstraße 12', $conflicts[0]['stored']);
        $this->assertSame('Neue Straße 9', $conflicts[0]['new']);
        $this->assertSame('Straße', $conflicts[0]['label']);
        $this->assertSame('0170/1234567', $conflicts[1]['stored']);

        // Unveraenderte Felder erzeugen keinen Konflikt
        $this->assertSame([], $service2->conflicts(PatientCardInput::fromPost($this->post()), $wizard['masterData']));

        // Entscheidung je Feld
        $stored = PatientCardInput::fromPost($changed + ['conflict' => ['street' => 'stored']]);
        $merged = $service2->mergedValues($stored, $wizard['masterData']);
        $this->assertSame('Musterstraße 12', $merged['street'], 'Entscheidung "gespeichert" gewinnt');
        $this->assertSame('Dr. med. Neu', $merged['physician_name'], 'Ohne Entscheidung gilt der neue Wert');
        $this->assertSame(
            'Neue Straße 9',
            $service2->mergedValues($input, $wizard['masterData'])['street'],
        );

        $card2 = $service2->create($stored, $wizard['report'], $wizard['masterData']);
        $snapshot = $this->snapshot($card2['card_id']);
        $this->assertSame('Musterstraße 12', $snapshot['patient']['street']);
        $this->assertSame('Dr. med. Neu', $snapshot['physician']['name']);
        $this->assertSame('0170/9999999', $snapshot['emergency_contact']['phone']);

        // Der alte Ausweis behaelt seinen eigenen Datenstand
        $old = $this->snapshot($card1['card_id']);
        $this->assertSame('Dr. med. Hausarzt', $old['physician']['name']);
        $this->assertSame('0170/1234567', $old['emergency_contact']['phone']);
    }

    /** Test 6: Ohne beide Bestaetigungen entsteht weder Ausweis noch PDF. */
    public function testRequiresBothConfirmations(): void
    {
        $outcome = $this->importSample();
        $service = $this->service();
        $report = $service->loadReport($outcome->reportId);

        foreach ([
            ['confirm_patient' => '0'],
            ['confirm_merge' => '0'],
            ['confirm_patient' => '0', 'confirm_merge' => '0'],
        ] as $overrides) {
            $exception = $this->assertThrows(
                PatientCardException::class,
                fn () => $service->create(PatientCardInput::fromPost($this->post($overrides)), $report, null),
            );
            $this->assertTrue($exception->fieldErrors() !== []);
            $this->assertSame('Bitte die markierten Angaben pruefen.', $exception->getMessage());
            $this->assertNotContains('LASTNAME', $exception->getMessage());
            $this->assertSame(0, $this->rowCount('patient_cards'));
            $this->assertSame(0, $this->rowCount('patient_card_master_data'));
            $this->assertSame(0, $this->rowCount('patient_card_settings_versions'));
        }
    }

    /** Test 7: Aenderungen an Stammdaten und Logo veraendern bestehende Ausweise nicht. */
    public function testMasterDataAndLogoChangesDoNotAffectExistingCards(): void
    {
        $this->configureSettings(['notice_text' => 'Erster Hinweistext']);
        $first = $this->importSample();
        $service = $this->service();
        $card1 = $service->create(PatientCardInput::fromPost($this->post()), $service->loadReport($first->reportId), null);
        $pdf1 = (string) $service->card($card1['card_id'], true)['pdf_content'];
        $version1 = (int) $service->card($card1['card_id'])['settings_version_id'];

        // Neue Fassung der Stammdaten inklusive Logo
        $logoBytes = Images::png(40, 20);
        $logoId = $this->repository()->insertLogo(
            hash('sha256', $logoBytes),
            'image/png',
            'logo.png',
            40,
            20,
            $logoBytes,
            '2026-10-07 08:00:00',
        );
        $this->repository()->saveSettings([
            'center_name' => 'Nachsorgezentrum Neu',
            'center_address' => 'Neue Allee 1',
            'notice_text' => 'Zweiter Hinweistext',
            'flight_notice_de' => 'Neuer deutscher Hinweis',
            'flight_notice_en' => 'New English notice',
            'logo_id' => $logoId,
        ], '2026-10-07 08:00:00');
        $version2 = (int) $this->repository()->currentSettingsVersionId();
        $this->assertNotSame($version1, $version2);
        $this->assertSame(2, $this->repository()->countSettingsVersions());
        $this->assertSame('Erster Hinweistext', $this->repository()->settingsVersion($version1)['notice_text']);

        // Neuer Ausweis nutzt die neue Fassung und das Logo
        $second = $this->importLaterSample();
        $service2 = $this->service();
        $wizard = $service2->wizard($second->reportId);
        $card2 = $service2->create(PatientCardInput::fromPost($this->post()), $wizard['report'], $wizard['masterData']);
        $pdf2 = (string) $service2->card($card2['card_id'], true)['pdf_content'];
        $this->assertSame($version2, (int) $service2->card($card2['card_id'])['settings_version_id']);
        $this->assertContains('Zweiter Hinweistext', PdfText::text($pdf2));
        $this->assertContains('Nachsorgezentrum Neu', PdfText::text($pdf2));
        $this->assertContains('/Subtype /Image', $pdf2);
        $this->assertContains(hash('sha256', $logoBytes), (string) $service2->card($card2['card_id'])['snapshot']);

        // Der bestehende Ausweis bleibt unveraendert (PDF und Snapshot)
        $this->assertSame($pdf1, $service2->card($card1['card_id'], true)['pdf_content']);
        $this->assertSame($version1, (int) $service2->card($card1['card_id'])['settings_version_id']);
        $text1 = PdfText::text($pdf1);
        $this->assertContains('Erster Hinweistext', $text1);
        $this->assertNotContains('Zweiter Hinweistext', $text1);
        $this->assertNotContains('Nachsorgezentrum Neu', $text1);
        $this->assertNotContains('/Subtype /Image', $pdf1);
        $this->assertSame('Erster Hinweistext', $this->snapshot($card1['card_id'])['settings']['notice_text']);
    }

    /** Test 8: Ein korrigierter Ausweis ersetzt den alten nicht (eigene Fassung je Bericht). */
    public function testCorrectionCreatesNewCardVersionAndKeepsOldCard(): void
    {
        $outcome = $this->importSample();
        $service = $this->service();
        $report = $service->loadReport($outcome->reportId);

        $first = $service->create(PatientCardInput::fromPost($this->post()), $report, null);
        $pdfFirst = (string) $service->card($first['card_id'], true)['pdf_content'];
        $second = $service->create(
            PatientCardInput::fromPost($this->post(['indication' => 'Korrigierte Indikation'])),
            $report,
            null,
        );

        $this->assertSame(2, $this->rowCount('patient_cards'));
        $this->assertSame(1, (int) $service->card($first['card_id'])['card_version']);
        $this->assertSame(2, (int) $service->card($second['card_id'])['card_version']);
        $this->assertSame(1, $first['sequence_no']);
        $this->assertSame(2, $second['sequence_no']);
        $this->assertSame('Bradykardie', $this->snapshot($first['card_id'])['patient']['indication']);
        $this->assertSame('Korrigierte Indikation', $this->snapshot($second['card_id'])['patient']['indication']);
        $this->assertSame($pdfFirst, $service->card($first['card_id'], true)['pdf_content']);

        $latest = $service->latestCardForReport($outcome->reportId);
        $this->assertSame($second['card_id'], (int) $latest['id']);
        $this->assertSame(2, (int) $latest['card_version']);

        $byReport = $this->repository()->latestCardsForReports([$outcome->reportId, 999]);
        $this->assertSame($second['card_id'], (int) $byReport[$outcome->reportId]['id']);
        $this->assertCount(1, $byReport);
    }

    /** Test 9: Suche, Historie je Patient und Stammdatenfassungen. */
    public function testSearchHistoryAndSettingsVersions(): void
    {
        $version1 = $this->configureSettings(['notice_text' => 'Erster Hinweistext']);
        $outcome = $this->importSample();
        $service = $this->service();
        $card = $service->create(PatientCardInput::fromPost($this->post()), $service->loadReport($outcome->reportId), null);

        $found = $service->search(['q' => 'LASTNAME'], 10, 0);
        $this->assertSame(1, $found['total']);
        $this->assertSame($card['card_id'], (int) $found['rows'][0]['id']);
        $this->assertSame(0, $service->search(['q' => 'unbekannt'], 10, 0)['total']);
        $this->assertSame(1, $service->search(['patient' => 'FIRSTNAME'], 10, 0)['total']);
        $this->assertSame(1, $service->search(['serial' => '5809481'], 10, 0)['total']);
        $this->assertSame(0, $service->search(['serial' => '0000000'], 10, 0)['total']);
        $this->assertSame(1, $this->repository()->countCards());

        $cards = $service->cardsForPatient($card['patient_id']);
        $this->assertCount(1, $cards);
        $this->assertSame($card['card_id'], (int) $cards[0]['id']);
        $this->assertSame([], $this->repository()->previousCards($card['patient_id'], $card['card_id']));

        $settings = $this->settingsService();
        $loaded = $settings->load();
        $this->assertSame(1, $loaded['versions']);
        $this->assertSame(PatientCardPdfGenerator::MAX_NOTICE_CHARS, $loaded['limits']['notice']);
        $this->assertSame(PatientCardPdfGenerator::MAX_FLIGHT_NOTICE_CHARS, $loaded['limits']['flight']);
        $this->assertSame(PatientCardSettingsService::MAX_CENTER_ADDRESS, $loaded['limits']['center_address']);
        $this->assertSame(ImageUploadValidator::MAX_BYTES, $loaded['limits']['logo_bytes']);
        $this->assertNull($loaded['logo']);
        $this->assertSame('Erster Hinweistext', $loaded['settings']['notice_text']);

        // Zweite Fassung, erste bleibt unveraendert
        $version2 = $settings->save([
            'center_name' => 'Zentrum Zwei',
            'center_address' => 'Adresse Zwei',
            'notice_text' => 'Zweiter Hinweistext',
            'flight_notice_de' => 'DE Zwei',
            'flight_notice_en' => 'EN Two',
        ], null, false)['version_id'];
        $this->assertSame($version1 + 1, $version2);
        $this->assertSame(2, $settings->load()['versions']);
        $this->assertSame(1, $this->rowCount('patient_card_settings'), 'Es bleibt genau eine aktuelle Zeile');
        $this->assertSame('Erster Hinweistext', $this->repository()->settingsVersion($version1)['notice_text']);
        $this->assertSame('Zweiter Hinweistext', $this->repository()->settingsVersion($version2)['notice_text']);

        // Ungueltige Eingaben erzeugen keine neue Fassung
        $exception = $this->assertThrows(
            PatientCardException::class,
            fn () => $settings->save([
                'notice_text' => str_repeat('x', PatientCardPdfGenerator::MAX_NOTICE_CHARS + 1),
                'center_name' => str_repeat('y', PatientCardSettingsService::MAX_CENTER_NAME + 1),
            ], null, false),
        );
        $this->assertTrue(isset($exception->fieldErrors()['notice_text']));
        $this->assertTrue(isset($exception->fieldErrors()['center_name']));
        $this->assertSame(2, $settings->load()['versions']);

        // Logo bleibt ohne neue Datei erhalten und kann gezielt entfernt werden
        $logoBytes = Images::png(10, 10);
        $logoId = $this->repository()->insertLogo(
            hash('sha256', $logoBytes),
            'image/png',
            'logo.png',
            10,
            10,
            $logoBytes,
            '2026-10-07 08:00:00',
        );
        $this->assertSame($logoId, $this->repository()->findLogoIdByHash(hash('sha256', $logoBytes)));
        $this->repository()->saveSettings([
            'center_name' => 'Zentrum Zwei',
            'notice_text' => 'Zweiter Hinweistext',
            'logo_id' => $logoId,
        ], '2026-10-07 08:00:00');
        $kept = $settings->save(['center_name' => 'Zentrum Zwei'], null, false);
        $this->assertSame($logoId, $kept['logo_id'], 'Ohne neue Datei bleibt das Logo erhalten');
        $this->assertSame($logoId, $this->settingsService()->load()['logo']['id']);
        $removed = $settings->save(['center_name' => 'Zentrum Zwei'], null, true);
        $this->assertNull($removed['logo_id']);
        $this->assertNull($this->settingsService()->load()['logo']);
        $this->assertSame(1, $this->rowCount('patient_card_logos'), 'Logos werden nie geloescht');
    }

    /** Test 10: Ein Ausweis ohne Stammdaten (keine Texte, kein Zentrum) bleibt druckbar. */
    public function testCardWithoutMasterDataStillRendersTwoPages(): void
    {
        $outcome = $this->importSample();
        $service = $this->service();
        $wizard = $service->wizard($outcome->reportId);
        $this->assertFalse($wizard['settingsConfigured']);

        $card = $service->create(
            PatientCardInput::fromPost($this->post([
                'street' => '', 'postal_code' => '', 'city' => '', 'phone' => '',
                'indication' => '', 'device_implant_location' => '',
                'emergency_contact_name' => '', 'emergency_contact_phone' => '',
                'physician_name' => '', 'physician_practice' => '', 'physician_postal_code' => '',
                'physician_city' => '', 'physician_phone' => '', 'control_physician' => '',
                'next_control_date' => '',
            ])),
            $wizard['report'],
            null,
        );

        $row = $service->card($card['card_id'], true);
        $pdf = (string) $row['pdf_content'];
        $this->assertSame(2, PdfText::pageCount($pdf));
        $text = PdfText::text($pdf);
        $this->assertContains('nicht angegeben', $text);
        $this->assertContains('Logo nicht hinterlegt', $text);
        $this->assertContains('LASTNAME, FIRSTNAME', $text, 'Angaben aus dem Bericht bleiben erhalten');
        $this->assertSame(1, $this->rowCount('patient_card_settings_versions'), 'Fassung wird beim Erstellen angelegt');
    }

    /** Test 11: Praxis-Informationen und Rücksendeangaben werden geprueft und versioniert. */
    public function testPracticeAndReturnAddressSettingsAreVersioned(): void
    {
        $settings = $this->settingsService();
        $version1 = $settings->save([
            'center_name' => 'Praxis am Markt',
            'center_address' => "Marktplatz 3\n54321 Musterstadt",
            'practice_phone' => '05432/112233',
            'practice_fax' => '05432/112244',
            'practice_email' => 'praxis@example.de',
            'practice_website' => 'www.praxis-am-markt.de',
            'return_name' => 'Praxis am Markt',
            'return_street' => 'Postfach 12',
            'return_postal_code' => '54320',
            'return_city' => 'Musterstadt',
            'notice_text' => 'Hinweis.',
            'flight_notice_de' => 'Hinweis Flug.',
            'flight_notice_en' => 'Flight notice.',
        ], null, false)['version_id'];

        $loaded = $settings->load()['settings'];
        $this->assertSame('05432/112233', $loaded['practice_phone']);
        $this->assertSame('praxis@example.de', $loaded['practice_email']);
        $this->assertSame('Postfach 12', $loaded['return_street']);
        $this->assertSame('54320', $loaded['return_postal_code']);
        $this->assertSame('Musterstadt', $loaded['return_city']);

        // Eine ungueltige E-Mail-Adresse verhindert die neue Fassung.
        $exception = $this->assertThrows(
            PatientCardException::class,
            fn () => $settings->save([
                'practice_email' => 'keine-adresse',
                'return_name' => str_repeat('x', PatientCardSettingsService::MAX_RETURN_NAME + 1),
            ], null, false),
        );
        $this->assertTrue(isset($exception->fieldErrors()['practice_email']));
        $this->assertTrue(isset($exception->fieldErrors()['return_name']));
        $this->assertSame(1, $settings->load()['versions']);

        // Die Fassung bleibt unveraendert lesbar, auch wenn nur die Hinweistexte neu gespeichert
        // werden (die Praxis-Informationen werden dann unveraendert uebernommen).
        $version2 = $settings->save([
            'center_name' => 'Praxis am Markt',
            'center_address' => "Marktplatz 3\n54321 Musterstadt",
            'practice_phone' => '05432/112233',
            'practice_fax' => '05432/112244',
            'practice_email' => 'praxis@example.de',
            'practice_website' => 'www.praxis-am-markt.de',
            'return_name' => 'Praxis am Markt',
            'return_street' => 'Postfach 12',
            'return_postal_code' => '54320',
            'return_city' => 'Musterstadt',
            'notice_text' => 'Neuer Hinweis.',
        ], null, false)['version_id'];

        $this->assertSame($version1 + 1, $version2);
        $this->assertSame('05432/112233', $this->repository()->settingsVersion($version2)['practice_phone']);
        $this->assertSame('Postfach 12', $this->repository()->settingsVersion($version2)['return_street']);
        $this->assertSame('Hinweis.', $this->repository()->settingsVersion($version1)['notice_text']);
    }
}
