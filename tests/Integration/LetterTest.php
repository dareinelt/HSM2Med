<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application;
use App\Config\Config;
use App\Import\ImportOutcome;
use App\Letter\LetterException;
use App\Letter\LetterInput;
use App\Letter\LetterPdfGenerator;
use App\Letter\LetterService;
use App\Patient\DeviceCheckTemplate;
use App\Patient\PatientInput;
use App\Patient\PatientRecordRepository;
use App\Patient\PatientRecordService;
use App\Patient\PatientRecordType;
use App\Patient\PatientRepository;
use App\Patient\PatientService;
use App\PatientCard\MeasurementTemplate;
use App\PatientCard\PatientCardInput;
use App\PatientCard\PatientCardPdfGenerator;
use App\PatientCard\PatientCardRepository;
use App\PatientCard\PatientCardService;
use App\PatientCard\PatientCardSettingsService;
use App\Security\ImageUploadValidator;
use Tests\Support\Fixtures;
use Tests\Support\PdfText;

/**
 * Der Brief zur Schrittmacher-/ICD-Abfrage entsteht ausschliesslich aus vorhandenen Daten und
 * ist nach dem Erzeugen unveraenderlich: Snapshot, PDF und Pruefsumme werden gemeinsam
 * gespeichert; spaetere Aenderungen an Bausteinen oder Ausweisen wirken sich nicht aus.
 */
final class LetterTest extends DatabaseTestCase
{
    private Application $app;
    private LetterService $letters;
    private PatientRecordService $records;
    private PatientRepository $patients;
    private PatientCardRepository $cards;
    private int $patientId;

    public function setUp(): void
    {
        parent::setUp();
        $this->app = new Application(Config::fromEnvironment(), dirname(__DIR__, 2), $this->clock);
        $this->letters = $this->app->letterService();
        $this->patients = new PatientRepository($this->pdo);
        $this->cards = new PatientCardRepository($this->pdo);
        $this->records = new PatientRecordService(
            new PatientRecordRepository($this->pdo),
            $this->patients,
            $this->clock,
            DeviceCheckTemplate::default(dirname(__DIR__, 2)),
        );
        $this->patientId = (int) (new PatientService($this->pdo, $this->patients, $this->clock))
            ->create(PatientInput::fromPost([
                'last_name' => 'Mustermann',
                'first_name' => 'Erika',
                'date_of_birth' => '21.10.1938',
                'patient_identifier' => 'P-100',
            ]))['patient_id'];
    }

    private function importSample(): ImportOutcome
    {
        $service = $this->importService();
        $bytes = Fixtures::sampleFile();
        return $service->import($service->analyze($bytes, 'MERLIN__ANN_5809481.log'), $bytes);
    }

    /**
     * Arbeitet ab jetzt mit dem Patienten aus dem Beispielbericht. Der Bericht wird nur beim
     * ersten Aufruf importiert (derselbe Inhalt wird sonst als Dublette abgelehnt).
     *
     * @return int ID des importierten Berichts
     */
    private function importAndSelectPatient(): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM patients WHERE patient_identifier = ?');
        $stmt->execute(['10358141']);
        $patientId = (int) $stmt->fetchColumn();
        if ($patientId <= 0) {
            $this->importSample();
            $stmt->execute(['10358141']);
            $patientId = (int) $stmt->fetchColumn();
        }
        $this->patientId = $patientId;

        return $this->reportIdOfPatient();
    }

    /** Neuester Bericht des aktuellen Patienten (importiert den Beispielbericht bei Bedarf). */
    private function reportIdOfPatient(): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM reports WHERE patient_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$this->patientId]);
        $reportId = (int) $stmt->fetchColumn();

        return $reportId > 0 ? $reportId : $this->importAndSelectPatient();
    }

    /** Anamnese, Vormedikation, Epikrise und eine Abfrage zum Patienten anlegen. */
    private function fillRecords(bool $withDeviceCheck = true): void
    {
        $this->records->save($this->patientId, PatientRecordType::Anamnesis, [
            'text' => 'Seit 2019 bekanntes Sick-Sinus-Syndrom mit Synkopen.',
            'author_name' => 'Dr. med. Beispiel',
        ]);
        $this->records->save($this->patientId, PatientRecordType::Premedication, [
            'medication' => [['substance' => 'Metoprolol', 'dose' => '47,5', 'unit' => 'mg', 'schedule' => '1-0-0']],
            'author_name' => 'Dr. med. Beispiel',
        ]);
        $this->records->save($this->patientId, PatientRecordType::Epicrisis, [
            'text' => 'Kontrollierte Abfrage im Rahmen der Nachsorge.',
            'author_name' => 'Dr. med. Beispiel',
        ]);
        if ($withDeviceCheck) {
            $this->records->save($this->patientId, PatientRecordType::DeviceCheck, [
                'device_type' => 'pacemaker',
                'values' => ['device.manufacturer' => 'Beispielhersteller', 'brady.mode' => 'DDD'],
                'leads' => [['model' => 'LEAD-1', 'location' => 'RA', 'impedance' => '512']],
                'notes' => 'Sondenmessung ohne Auffälligkeit.',
                'author_name' => 'Dr. med. Beispiel',
            ]);
        }
    }

    /** Ausweis mit Angabe zur MRT-Tauglichkeit (Quelle fuer den Brief). */
    private function createCard(string $mrt = 'MRT-bedingt tauglich', string $note = 'Nur mit Auflagen.'): int
    {
        (new PatientCardSettingsService($this->cards, new ImageUploadValidator(), $this->clock))->save([
            'center_name' => 'Nachsorgezentrum Beispielstadt',
            'center_address' => "Musterweg 5\n12345 Beispielstadt",
            'notice_text' => 'Hinweis.',
            'flight_notice_de' => 'Hinweis Flug.',
            'flight_notice_en' => 'Flight notice.',
        ], null, false);

        $service = new PatientCardService(
            $this->pdo,
            $this->cards,
            $this->reportService(),
            new PatientCardPdfGenerator(),
            $this->clock,
            MeasurementTemplate::default(dirname(__DIR__, 2)),
        );
        $wizard = $service->wizard($this->reportIdOfPatient());

        return (int) $service->create(PatientCardInput::fromPost([
            'last_name' => 'LASTNAME',
            'first_name' => 'FIRSTNAME',
            'date_of_birth' => '21.10.1938',
            'street' => 'Musterstraße 12',
            'postal_code' => '12345',
            'city' => 'Beispielstadt',
            'phone' => '01234/56789',
            'indication' => 'Bradykardie',
            'device_implant_location' => 'links pektoral',
            'mrt_compatibility' => $mrt,
            'mrt_compatibility_note' => $note,
            'emergency_contact_name' => '',
            'emergency_contact_phone' => '',
            'physician_name' => '',
            'physician_practice' => '',
            'physician_postal_code' => '',
            'physician_city' => '',
            'physician_phone' => '',
            'control_physician' => '',
            'next_control_date' => '',
            'confirm_patient' => '1',
            'confirm_merge' => '1',
        ]), $wizard['report'], $wizard['masterData'])['card_id'];
    }

    private function createLetter(?int $reportId = null): array
    {
        return $this->letters->create(LetterInput::fromPost([
            'patient_id' => (string) $this->patientId,
            'report_id' => $reportId === null ? '' : (string) $reportId,
            'confirm_data' => '1',
            'confirm_letter' => '1',
        ]));
    }

    /** Der Brief friert Patient, Bausteine, Anhang und PDF gemeinsam und pruefbar ein. */
    public function testCreateStoresSnapshotAndReproduciblePdf(): void
    {
        $this->importAndSelectPatient();
        $this->fillRecords();
        $this->createCard();

        $result = $this->createLetter();
        $this->assertSame(1, $result['sequence_no']);
        $this->assertTrue($result['page_count'] > 1, 'Mit Anhang ist der Brief mehrseitig.');
        $this->assertSame(1, $this->rowCount('patient_letters'));

        $letter = $this->letters->letter($result['letter_id']);
        $this->assertTrue($letter !== null);
        $this->assertSame(LetterService::LETTER_VERSION, $letter['letter_version']);
        $this->assertSame(LetterService::LETTER_TEMPLATE_VERSION, $letter['snapshot']['letter_template_version']);
        $this->assertSame('LASTNAME', $letter['last_name']);
        $this->assertSame('2026-10-07', $letter['letter_date']);
        $this->assertSame('2026-10-07 08:00:00', $letter['created_at']);
        $this->assertContains('LASTNAME_FIRSTNAME', (string) $letter['pdf_filename']);
        $this->assertContains('Nr1.pdf', (string) $letter['pdf_filename']);

        // Pruefsumme und Groesse gehoeren zum gespeicherten PDF.
        $pdf = $this->letters->pdfContent($result['letter_id']);
        $this->assertTrue($pdf !== null);
        $this->assertSame(hash('sha256', (string) $pdf), $letter['pdf_sha256']);
        $this->assertSame(strlen((string) $pdf), (int) $letter['pdf_size']);
        $this->assertSame($result['page_count'], LetterPdfGenerator::pageCount((string) $pdf));
        $this->assertSame($result['page_count'], PdfText::pageCount((string) $pdf));

        // Der Anhang ist im Brief enthalten und wird in der Liste mitgezaehlt.
        $this->assertTrue($letter['snapshot']['appendix']['present']);
        $this->assertTrue($letter['snapshot']['appendix']['filled'] > 0);
        $this->assertSame(count($letter['snapshot']['appendix']['sections']), (int) $letter['appendix_sections']);

        // Der Snapshot enthaelt die eingefrorenen Fassungen der Bausteine.
        foreach ([PatientRecordType::Anamnesis, PatientRecordType::Premedication, PatientRecordType::Epicrisis, PatientRecordType::DeviceCheck] as $type) {
            $this->assertTrue($letter['snapshot']['source']['record_versions'][$type->value]['record_id'] > 0, $type->value . ' fehlt im Snapshot.');
        }
        $this->assertSame(1, $letter['snapshot']['anamnesis']['version']);
        $this->assertSame('Seit 2019 bekanntes Sick-Sinus-Syndrom mit Synkopen.', $letter['snapshot']['anamnesis']['text']);
        $this->assertSame('Dr. med. Beispiel', $letter['snapshot']['epicrisis']['author_name']);
        $this->assertSame('LASTNAME', $letter['snapshot']['patient']['last_name']);
        $this->assertSame('10358141', $letter['snapshot']['patient']['patient_identifier']);
        $this->assertSame('Musterstraße 12', $letter['snapshot']['patient']['address']['street']);
        $this->assertSame('MRT-bedingt tauglich', $letter['snapshot']['mrt']['value']);
        $this->assertSame('aus Patientenausweis Nr. 1', $letter['snapshot']['mrt']['source_label']);
    }

    /** Spaetere Aenderungen an Bausteinen und Ausweisen lassen erzeugte Briefe unveraendert. */
    public function testSnapshotIsImmutableAgainstLaterChanges(): void
    {
        $this->importAndSelectPatient();
        $this->fillRecords();
        $this->createCard('MRT-bedingt tauglich');
        $first = $this->createLetter();

        $this->records->save($this->patientId, PatientRecordType::Anamnesis, [
            'text' => 'Geänderte Anamnese nach dem Brief.',
            'author_name' => 'Dr. med. Beispiel',
        ]);
        $this->createCard('nicht MRT-tauglich', 'Neue Angabe.');

        $letter = $this->letters->letter($first['letter_id']);
        $this->assertSame('Seit 2019 bekanntes Sick-Sinus-Syndrom mit Synkopen.', $letter['snapshot']['anamnesis']['text']);
        $this->assertSame(1, $letter['snapshot']['anamnesis']['version']);
        $this->assertSame('MRT-bedingt tauglich', $letter['snapshot']['mrt']['value']);
        $this->assertSame(1, $letter['snapshot']['mrt']['card_sequence']);
        $this->assertSame(1, $letter['snapshot']['source']['card_id']);

        // Das PDF bleibt bitgleich.
        $pdf = (string) $this->letters->pdfContent($first['letter_id']);
        $this->assertSame($letter['pdf_sha256'], hash('sha256', $pdf));
        $this->assertNotContains('Geänderte', PdfText::text($pdf));

        // Ein neuer Brief nimmt dagegen die neuen Fassungen auf.
        $second = $this->createLetter();
        $this->assertSame(2, $second['sequence_no']);
        $newLetter = $this->letters->letter($second['letter_id']);
        $this->assertSame(2, $newLetter['snapshot']['anamnesis']['version']);
        $this->assertSame('nicht MRT-tauglich', $newLetter['snapshot']['mrt']['value']);
        $this->assertSame(2, $newLetter['snapshot']['mrt']['card_sequence']);
    }

    /** Fassungszaehler laufen je Patient und je Bericht. */
    public function testLetterVersionCountsPerReport(): void
    {
        $reportId = $this->importAndSelectPatient();
        $this->fillRecords();

        $first = $this->createLetter($reportId);
        $second = $this->createLetter($reportId);
        $third = $this->createLetter(null);

        $this->assertSame(1, $this->letters->letter($first['letter_id'])['letter_version']);
        $this->assertSame(2, $this->letters->letter($second['letter_id'])['letter_version']);
        $this->assertSame(1, $this->letters->letter($third['letter_id'])['letter_version'], 'Ohne Bericht beginnt die Fassung neu.');
        $this->assertSame([1, 2, 3], [
            $this->letters->letter($first['letter_id'])['sequence_no'],
            $this->letters->letter($second['letter_id'])['sequence_no'],
            $this->letters->letter($third['letter_id'])['sequence_no'],
        ]);
    }

    /** Der Befundteil des Berichts wird mit seinen Messwerten eingefroren. */
    public function testReportPartIsFrozenIntoTheLetter(): void
    {
        $reportId = $this->importAndSelectPatient();
        $this->fillRecords();

        $result = $this->createLetter($reportId);
        $snapshot = $this->letters->letter($result['letter_id'])['snapshot'];

        $this->assertSame($reportId, $snapshot['source']['report_id']);
        $this->assertSame($reportId, $snapshot['report']['report_id']);
        $this->assertTrue($snapshot['report']['filled'] > 0);
        $this->assertTrue($snapshot['report']['rows'] !== []);
        $this->assertTrue($snapshot['report']['groups'] !== []);
        $this->assertContains('Bericht Nr. ' . $reportId, $snapshot['report']['meta']);
    }

    /** Ohne die beiden Bestaetigungen entsteht kein Brief. */
    public function testMissingConfirmationsAreRejected(): void
    {
        $this->fillRecords();

        foreach (['confirm_data', 'confirm_letter'] as $missing) {
            $post = [
                'patient_id' => (string) $this->patientId,
                'confirm_data' => '1',
                'confirm_letter' => '1',
            ];
            unset($post[$missing]);

            $exception = $this->assertThrows(LetterException::class, fn (): array => $this->letters->create(LetterInput::fromPost($post)));
            $this->assertTrue(isset($exception->fieldErrors()[$missing]), $missing . ' fehlt in den Feldfehlern.');
            $this->assertSame(0, $this->rowCount('patient_letters'));
        }

        $this->assertThrows(LetterException::class, fn (): LetterInput => LetterInput::fromPost(['patient_id' => '0']));
    }

    /** Ein Bericht eines anderen Patienten wird nicht uebernommen. */
    public function testReportOfAnotherPatientIsRejected(): void
    {
        $this->fillRecords();
        $other = (int) (new PatientService($this->pdo, $this->patients, $this->clock))
            ->create(PatientInput::fromPost([
                'last_name' => 'Andere',
                'first_name' => 'Person',
                'date_of_birth' => '01.01.1950',
                'patient_identifier' => 'P-200',
            ]))['patient_id'];
        $this->records->save($other, PatientRecordType::Anamnesis, ['text' => 'Anderer Patient.']);

        // Der importierte Bericht gehoert zum Patienten aus dem Bericht, nicht zu diesem.
        $outcome = $this->importSample();

        $exception = $this->assertThrows(LetterException::class, fn (): array => $this->createLetter($outcome->reportId));
        $this->assertTrue(isset($exception->fieldErrors()['report_id']));

        // Mit dem passenden Patienten ist derselbe Bericht zulaessig.
        $this->importAndSelectPatient();
        $result = $this->createLetter($outcome->reportId);
        $this->assertSame($this->patientId, $result['patient_id']);
    }

    /** Listen, Suche und Patientenbezug liefern nur die eigenen Briefe. */
    public function testListsSearchAndPatientScope(): void
    {
        $this->fillRecords();
        $first = $this->createLetter();
        $second = $this->createLetter();

        $rows = $this->letters->lettersForPatient($this->patientId);
        $this->assertSame(2, count($rows));
        $this->assertSame($second['letter_id'], (int) $rows[0]['id'], 'Der neueste Brief steht oben.');

        $this->assertTrue($this->letters->letterForPatient($this->patientId, $first['letter_id']) !== null);
        $this->assertNull($this->letters->letterForPatient($this->patientId + 999, $first['letter_id']));
        $this->assertNull($this->letters->letter(999999));

        $search = $this->letters->search(['q' => 'Mustermann'], 10, 0);
        $this->assertSame(2, $search['total']);
        $this->assertSame(0, $this->letters->search(['q' => 'Unbekannt'], 10, 0)['total']);
        $this->assertSame(2, $this->letters->search(['patient' => 'Mustermann'], 10, 0)['total']);
        $this->assertSame(2, $this->letters->search([], 10, 0)['total']);
    }

    /** Der Assistent warnt vor fehlenden Bausteinen, statt Angaben zu erfinden. */
    public function testPrepareWarnsAboutMissingBlocks(): void
    {
        $prepared = $this->letters->prepare($this->patientId, null);

        $this->assertSame($this->patientId, (int) $prepared['patient']['id']);
        $this->assertSame(1, $prepared['next_sequence']);
        $this->assertSame([], $prepared['appendix']['sections']);
        $this->assertFalse($prepared['appendix']['present']);
        $this->assertFalse($prepared['mrt']['available']);
        $this->assertSame(6, count($prepared['warnings']), 'Drei Textbausteine, Anhang, Bericht und MRT-Angabe fehlen.');

        $this->importAndSelectPatient();
        $this->fillRecords();
        $this->createCard();
        $prepared = $this->letters->prepare($this->patientId, null);
        $this->assertTrue($prepared['appendix']['present']);
        $this->assertTrue($prepared['mrt']['available']);
        $this->assertContains('aus Patientenausweis Nr. 1', $prepared['appendix']['mrt_label']);
        $this->assertSame(1, count($prepared['warnings']), 'Nur der Bericht fehlt noch.');
    }

    /** Die Patientensuche des Assistenten findet nach Namensteilen. */
    public function testPatientChoicesSearchByName(): void
    {
        $byName = $this->letters->patientChoices('Mustermann');
        $this->assertSame(1, count($byName));
        $this->assertSame($this->patientId, (int) $byName[0]['id']);
        $this->assertSame('Mustermann, Erika', (string) $byName[0]['patient_name']);

        $this->assertSame(1, count($this->letters->patientChoices('Erika')));
        $this->assertSame([], $this->letters->patientChoices('Niemand'));
    }
}
