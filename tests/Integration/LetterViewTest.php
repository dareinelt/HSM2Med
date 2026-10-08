<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application;
use App\Config\Config;
use App\Http\Controller\LetterController;
use App\Http\Controller\LetterTemplateController;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\View;
use App\Import\ImportOutcome;
use App\Letter\LetterSalutation;
use App\Patient\DeviceCheckTemplate;
use App\Patient\PatientRecordRepository;
use App\Patient\PatientRecordService;
use App\Patient\PatientRecordType;
use App\Patient\PatientRepository;
use App\PatientCard\MeasurementTemplate;
use App\PatientCard\PatientCardInput;
use App\PatientCard\PatientCardPdfGenerator;
use App\PatientCard\PatientCardRepository;
use App\PatientCard\PatientCardService;
use App\PatientCard\PatientCardSettingsService;
use App\Security\ImageUploadValidator;
use Tests\Support\Fixtures;

/**
 * Rendert die Oberflaechen des Briefes ueber die echten Controller und Templates.
 *
 * Schuetzt vor Fehlern, die ausschliesslich in der HTML-Schicht auftreten: unbekannte
 * Klassenreferenzen in Templates, fehlende Template-Variablen, unvollstaendige Formulare
 * und Fehlerseiten, die sonst erst im Browser auffallen.
 */
final class LetterViewTest extends DatabaseTestCase
{
    private Application $app;
    private PatientRecordService $records;
    private PatientCardRepository $cards;
    private int $patientId;

    public function setUp(): void
    {
        parent::setUp();
        $this->app = new Application(Config::fromEnvironment(), dirname(__DIR__, 2), $this->clock);
        $this->cards = new PatientCardRepository($this->pdo);
        $this->records = new PatientRecordService(
            new PatientRecordRepository($this->pdo),
            new PatientRepository($this->pdo),
            $this->clock,
            DeviceCheckTemplate::default(dirname(__DIR__, 2)),
        );
        $this->patientId = 0;
    }

    /** Ist die Auswahl eines Empfaengers im Assistenten angehakt? */
    private function isRecipientChecked(string $body, string $type): bool
    {
        return preg_match('/value="' . $type . '"\\s+data-recipient-label="[^"]*"\\s+checked/u', $body) === 1;
    }

    private function letters(): LetterController
    {
        return new LetterController($this->app, new View(dirname(__DIR__, 2) . '/templates'));
    }

    private function importSample(): ImportOutcome
    {
        $service = $this->importService();
        $bytes = Fixtures::sampleFile();

        return $service->import($service->analyze($bytes, 'MERLIN__ANN_5809481.log'), $bytes);
    }

    /**
     * Arbeitet ab jetzt mit dem Patienten aus dem Beispielbericht (Import nur einmal).
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

        $stmt = $this->pdo->prepare('SELECT id FROM reports WHERE patient_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$this->patientId]);

        return (int) $stmt->fetchColumn();
    }

    /** Anamnese, Vormedikation, Epikrise und eine Abfrage zum aktuellen Patienten anlegen. */
    private function fillRecords(bool $withDeviceCheck = true): void
    {
        $this->records->save($this->patientId, PatientRecordType::Anamnesis, [
            'text' => 'Seit 2019 bekanntes Sick-Sinus-Syndrom mit Synkopen.',
            'author_name' => 'Dr. med. Beispiel',
        ]);
        $this->records->save($this->patientId, PatientRecordType::Premedication, [
            'text' => 'Antikoagulation bis 2025, danach pausiert.',
            'medication' => [[
                'substance' => 'Metoprolol',
                'dose' => '47,5',
                'unit' => 'mg',
                'schedule' => '1-0-0',
                'reason' => 'Frequenzkontrolle',
                'from' => '01.01.2024',
                'to' => '',
            ]],
            'author_name' => 'Dr. med. Beispiel',
        ]);
        $this->records->save($this->patientId, PatientRecordType::Befund, [
            'text' => 'Regelmäßiger Eigenrhythmus, keine Sondenauffälligkeit.',
            'author_name' => 'Dr. med. Beispiel',
        ]);
        $this->records->save($this->patientId, PatientRecordType::Epicrisis, [
            'text' => 'Beschwerdefreie Vorstellung zur Routinekontrolle.',
            'author_name' => 'Dr. med. Beispiel',
        ]);

        if ($withDeviceCheck) {
            $this->records->save($this->patientId, PatientRecordType::DeviceCheck, [
                'device_type' => 'pacemaker',
                'values' => [
                    'battery.status' => 'ERI',
                    'battery.longevity' => '18 Monate',
                    'battery.magnet_rate' => '96/min',
                    'device.model' => 'Beispielmodell DR',
                    'device.implant_date' => '01.02.2020',
                    'brady.mode' => 'DDD',
                    'brady.lower_rate' => '60/min',
                ],
                'leads' => [
                    ['model' => 'Beispielsonde RA', 'location' => 'RA', 'implant_date' => '01.02.2020', 'impedance' => '520', 'sensing' => '2,1', 'threshold' => '0,6'],
                    ['model' => 'Beispielsonde RV', 'location' => 'RV', 'implant_date' => '01.02.2020', 'impedance' => '610', 'sensing' => '9,4', 'threshold' => '0,8'],
                ],
                'notes' => 'Sondenmessung ohne Auffälligkeit.',
                'author_name' => 'Dr. med. Beispiel',
            ]);
        }
    }

    /** Ausweis mit Angabe zur MRT-Tauglichkeit (Quelle fuer den Brief). */
    private function createCard(): int
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
        $stmt = $this->pdo->prepare('SELECT id FROM reports WHERE patient_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$this->patientId]);
        $wizard = $service->wizard((int) $stmt->fetchColumn());

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
            'mrt_compatibility' => 'MRT-bedingt tauglich',
            'mrt_compatibility_note' => 'Nur mit Auflagen.',
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

    /** Hausarzt mit vollstaendiger Anschrift (Standardempfaenger der Tests). */
    private function saveFamilyDoctor(): void
    {
        (new PatientRepository($this->pdo))->saveMasterData($this->patientId, [
            'physician_name' => 'Dr. med. Anna Weber',
            'physician_practice' => 'Hausarztpraxis am Markt',
            'physician_street' => 'Marktplatz 3',
            'physician_postal_code' => '54321',
            'physician_city' => 'Hausarztstadt',
        ], '2026-01-01 00:00:00');
    }

    /**
     * @param array<string, string|list<string>> $post
     */
    private function createLetter(array $post): Response
    {
        $this->saveFamilyDoctor();
        return $this->letters()->create(new Request('POST', '/letters', [], $post + [
            'patient_id' => (string) $this->patientId,
            'report_id' => '',
            'confirm_data' => '1',
            'confirm_letter' => '1',
            'recipients' => ['family_doctor'],
        ]));
    }

    /** Uebersicht: leerer Zustand, Treffer der Suche und der Filter ohne Treffer. */
    public function testLetterListRendersEmptyAndFilled(): void
    {
        $empty = $this->letters()->index(new Request('GET', '/letters'));
        $this->assertSame(200, $empty->status);
        $this->assertContains('Briefe zur Schrittmacher-/ICD-Abfrage', $empty->body);
        $this->assertContains('Noch kein Brief erstellt', $empty->body);
        $this->assertContains('0 Brief(e) gefunden', $empty->body);

        $reportId = $this->importAndSelectPatient();
        $this->fillRecords();
        $this->createCard();
        $created = $this->createLetter(['report_id' => (string) $reportId]);
        $this->assertSame(303, $created->status);

        $filled = $this->letters()->index(new Request('GET', '/letters', ['q' => 'LASTNAME']));
        $this->assertSame(200, $filled->status);
        $this->assertContains('1 Brief(e) gefunden', $filled->body);
        $this->assertContains('LASTNAME, FIRSTNAME', $filled->body);
        $this->assertContains('/letters/1', $filled->body);
        $this->assertContains('Nr. ' . $reportId, $filled->body);
        $this->assertContains('/letters/1/pdf?download=1', $filled->body);

        $noMatch = $this->letters()->index(new Request('GET', '/letters', ['q' => 'gibtesnicht']));
        $this->assertContains('0 Brief(e) gefunden', $noMatch->body);
        $this->assertContains('Noch kein Brief erstellt', $noMatch->body);
    }

    /** Schritt 1: Patientenauswahl mit und ohne Treffer. */
    public function testPatientSelectionRenders(): void
    {
        $empty = $this->letters()->newLetter(new Request('GET', '/letters/new'));
        $this->assertSame(200, $empty->status);
        $this->assertContains('Patient wählen', $empty->body);
        $this->assertContains('Kein Patient gefunden', $empty->body);

        $this->importAndSelectPatient();
        $found = $this->letters()->newLetter(new Request('GET', '/letters/new', ['q' => 'LASTNAME']));
        $this->assertContains('/letters/new?patient=' . $this->patientId, $found->body);
        $this->assertContains('LASTNAME, FIRSTNAME', $found->body);
    }

    /** Schritte 2 bis 6: Assistent mit Formular, Empfaengern, Bestaetigungen und Navigation. */
    public function testWizardRendersAllSteps(): void
    {
        $reportId = $this->importAndSelectPatient();
        $this->fillRecords();
        $this->createCard();

        $response = $this->letters()->newLetter(
            new Request('GET', '/letters/new', ['patient' => (string) $this->patientId, 'report' => (string) $reportId]),
        );

        $this->assertSame(200, $response->status);
        $this->assertContains('action="/letters"', $response->body);
        $this->assertContains('data-step="2"', $response->body);
        $this->assertContains('name="patient_id" value="' . $this->patientId . '"', $response->body);
        $this->assertContains('name="report_id" value="' . $reportId . '"', $response->body);
        // Schritt 1 (Patientenauswahl) ist eine eigene Seite; im Formular gibt es die Schritte 2 bis 6.
        foreach (array_slice(LetterController::WIZARD_STEPS, 1, null, true) as $number => $label) {
            $this->assertContains('data-wizard-goto="' . $number . '"', $response->body);
            $this->assertContains($label, $response->body);
        }
        $this->assertNotContains('data-wizard-goto="1"', $response->body);
        $this->assertContains('2 · Bericht zuordnen (optional)', $response->body);
        $this->assertContains('name="confirm_data"', $response->body);
        $this->assertContains('name="confirm_letter"', $response->body);
        $this->assertContains('Anhang: vollständige Abfrage', $response->body);
        $this->assertContains('MRT-bedingt tauglich', $response->body);
        $this->assertContains('ab Nr. 1 für diesen Patienten', $response->body);
        $this->assertNotContains('Noch nicht erfasst', $response->body);
        // Empfaenger: Patient mit Anschrift aus dem Ausweis waehlbar, Aerzte ohne Anschrift gesperrt.
        $this->assertContains('4 · Empfänger wählen', $response->body);
        foreach (['patient', 'family_doctor', 'referring_physician'] as $type) {
            $this->assertContains('id="recipient-' . $type . '" name="recipients[]" value="' . $type . '"', $response->body);
        }
        $this->assertContains('Überweisender Arzt', $response->body);
        $this->assertContains('Nicht wählbar – in den Stammdaten fehlt:', $response->body);
        $this->assertContains('Stammdaten ergänzen', $response->body);
    }

    /** Je ausgewaehltem Empfaenger entsteht ein Brief; die Uebersicht zeigt den Empfaenger. */
    public function testCreateForSeveralRecipients(): void
    {
        $this->importAndSelectPatient();
        $this->fillRecords();
        $this->createCard();
        (new PatientRepository($this->pdo))->saveMasterData($this->patientId, [
            'referrer_name' => 'Dr. med. Jonas Klein',
            'referrer_practice' => 'Kardiologie am Dom',
            'referrer_street' => 'Domplatz 1',
            'referrer_postal_code' => '50667',
            'referrer_city' => 'Köln',
        ], '2026-01-01 00:00:00');

        $this->saveFamilyDoctor();
        $wizard = $this->letters()->newLetter(new Request('GET', '/letters/new', ['patient' => (string) $this->patientId]));
        $this->assertTrue($this->isRecipientChecked($wizard->body, 'family_doctor') && $this->isRecipientChecked($wizard->body, 'referring_physician'), 'Aerzte mit Anschrift sind vorausgewaehlt.');
        $this->assertFalse($this->isRecipientChecked($wizard->body, 'patient'), 'Der Patient wird nur auf Wunsch angeschrieben.');
        $this->assertNotContains('Arztbrief generisch erstellen', $wizard->body, 'Mit Arztanschrift gibt es den generischen Arztbrief nicht.');
        $this->assertContains('Kardiologie am Dom', $wizard->body);

        $none = $this->createLetter(['recipients' => []]);
        $this->assertSame(422, $none->status);
        $this->assertContains('Bitte mindestens einen Empfänger auswählen', $none->body);
        $this->assertContains('data-step="4"', $none->body, 'Der Assistent oeffnet den Schritt mit dem Fehler.');
        $this->assertSame(0, $this->rowCount('patient_letters'));

        $created = $this->createLetter(['recipients' => ['patient', 'family_doctor', 'referring_physician']]);
        $this->assertSame(303, $created->status);
        $this->assertSame('/letters/patients/' . $this->patientId, $created->headers['Location'] ?? '');
        $this->assertSame(3, $this->rowCount('patient_letters'));
        $this->assertContains('3 Briefe wurden erstellt', implode(' ', array_column($_SESSION['_flash'] ?? [], 'message')));

        $list = $this->letters()->patient(new Request('GET', '/letters/patients/' . $this->patientId), ['patient' => (string) $this->patientId]);
        foreach (['Patient: FIRSTNAME LASTNAME', 'Hausarzt: Dr. med. Anna Weber, Hausarztpraxis am Markt', 'Überweisender Arzt: Dr. med. Jonas Klein, Kardiologie am Dom'] as $needle) {
            $this->assertContains($needle, $list->body);
        }
        $show = $this->letters()->show(new Request('GET', '/letters/3'), ['id' => '3']);
        $this->assertContains('Domplatz 1', $show->body);
        $this->assertContains('an Überweisender Arzt', $show->body);
    }

    /**
     * Ohne Anschrift von Hausarzt und ueberweisendem Arzt bietet der Assistent den generischen
     * Arztbrief an und waehlt ihn vor; der Brief geht an die weiterbehandelnden Aerztinnen und
     * Aerzte und wird fest angeredet.
     */
    public function testGenericLetterWithoutDoctorAddress(): void
    {
        $this->importAndSelectPatient();
        $this->fillRecords();
        $this->createCard();

        $wizard = $this->letters()->newLetter(new Request('GET', '/letters/new', ['patient' => (string) $this->patientId]));
        $this->assertContains('Arztbrief generisch erstellen', $wizard->body);
        $this->assertContains('An die weiterbehandelnden', $wizard->body);
        $this->assertContains('Ärztinnen und Ärzte', $wizard->body);
        $this->assertContains(LetterSalutation::GENERIC, $wizard->body);
        $this->assertTrue($this->isRecipientChecked($wizard->body, 'generic'), 'Ohne Arztanschrift ist der generische Arztbrief vorausgewaehlt.');
        $this->assertFalse($this->isRecipientChecked($wizard->body, 'family_doctor'), 'Ohne Anschrift ist der Hausarzt nicht vorausgewaehlt.');
        $this->assertFalse($this->isRecipientChecked($wizard->body, 'referring_physician'));
        $this->assertFalse($this->isRecipientChecked($wizard->body, 'patient'), 'Der Patient wird nur auf Wunsch angeschrieben.');
        // Die Aerzte bleiben gesperrt, weil ihre Anschrift fehlt.
        $this->assertContains('Nicht wählbar – in den Stammdaten fehlt:', $wizard->body);

        $created = $this->letters()->create(new Request('POST', '/letters', [], [
            'patient_id' => (string) $this->patientId,
            'report_id' => '',
            'confirm_data' => '1',
            'confirm_letter' => '1',
            'recipients' => ['generic'],
        ]));
        $this->assertSame(303, $created->status);
        $this->assertSame(1, $this->rowCount('patient_letters'));

        $row = $this->pdo->query('SELECT recipient_type, recipient_name, pdf_filename, snapshot FROM patient_letters')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('generic', $row['recipient_type']);
        $this->assertNull($row['recipient_name'], 'Der generische Arztbrief hat keinen Namen fuer die Liste.');
        $this->assertContains('an-Arztbrief-generisch', (string) $row['pdf_filename']);
        $snapshot = json_decode((string) $row['snapshot'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(LetterSalutation::GENERIC, $snapshot['recipient']['salutation']);
        $this->assertSame(['An die weiterbehandelnden', 'Ärztinnen und Ärzte'], $snapshot['recipient']['lines']);

        $list = $this->letters()->patient(new Request('GET', '/letters/patients/' . $this->patientId), ['patient' => (string) $this->patientId]);
        $this->assertContains('Arztbrief generisch', $list->body);
        $show = $this->letters()->show(new Request('GET', '/letters/1'), ['id' => '1']);
        $this->assertContains('Arztbrief generisch', $show->body);
        $this->assertContains('An die weiterbehandelnden', $show->body);
        $this->assertContains('Ärztinnen und Ärzte', $show->body);
    }

    /** Mit Arztanschrift ist der generische Arztbrief nicht waehlbar - auch nicht ueber das Formular. */
    public function testGenericLetterIsRejectedWhenADoctorAddressExists(): void
    {
        $this->importAndSelectPatient();
        $this->fillRecords();
        $this->createCard();

        $wizard = $this->letters()->newLetter(new Request('GET', '/letters/new', ['patient' => (string) $this->patientId]));
        $this->assertContains('Arztbrief generisch erstellen', $wizard->body);

        // Hausarzt anschliessend pflegen: der generische Arztbrief verschwindet aus dem Assistenten.
        $this->saveFamilyDoctor();
        $withDoctor = $this->letters()->newLetter(new Request('GET', '/letters/new', ['patient' => (string) $this->patientId]));
        $this->assertNotContains('Arztbrief generisch erstellen', $withDoctor->body);
        $this->assertTrue($this->isRecipientChecked($withDoctor->body, 'family_doctor'), 'Der Hausarzt ist nun vorausgewaehlt.');
        $this->assertFalse($this->isRecipientChecked($withDoctor->body, 'generic'));

        // Auch eine nachtraeglich gesendete Auswahl wird abgewiesen.
        $rejected = $this->createLetter(['recipients' => ['generic']]);
        $this->assertSame(422, $rejected->status);
        $this->assertContains('Für den Empfänger „Arztbrief generisch“ fehlt in den Stammdaten: Anschrift von Hausarzt oder Überweisendem Arzt.', $rejected->body);
        $this->assertSame(0, $this->rowCount('patient_letters'));
    }

    /** Ohne Bausteine, Abfrage und Ausweis benennt der Assistent jeden fehlenden Teil. */
    public function testWizardNamesMissingBlocks(): void
    {
        $this->importAndSelectPatient();

        $response = $this->letters()->newLetter(
            new Request('GET', '/letters/new', ['patient' => (string) $this->patientId]),
        );

        $this->assertSame(200, $response->status);
        $this->assertContains('Noch nicht erfasst', $response->body);
        $this->assertContains('Es liegt keine Schrittmacher-/ICD-Abfrage vor', $response->body);
        $this->assertContains('kein Anhang (keine Abfrage vorhanden)', $response->body);
        $this->assertContains('Im neuesten Patientenausweis ist keine MRT-Tauglichkeit angegeben', $response->body);
        $this->assertContains('Kein Bericht ausgewählt – der Baustein „Berichte" entfällt.', $response->body);
    }

    /** Unbekannte Patienten und fremde Berichte werden als Fehler behandelt, nicht gerendert. */
    public function testWizardRejectsUnknownPatientAndForeignReport(): void
    {
        $this->assertThrows(
            HttpException::class,
            fn (): Response => $this->letters()->newLetter(new Request('GET', '/letters/new', ['patient' => '9999'])),
        );

        $reportId = $this->importAndSelectPatient();
        $response = $this->letters()->newLetter(new Request('GET', '/letters/new', [
            'patient' => (string) $this->patientId,
            'report' => '4242',
        ]));

        $this->assertSame(422, $response->status);
        $this->assertContains('Der ausgewählte Bericht wurde nicht gefunden.', $response->body);
        $this->assertContains('2 · Bericht zuordnen (optional)', $response->body);
        $this->assertTrue($reportId > 0, 'Der Beispielbericht wurde importiert.');
    }

    /** Fehlende Bestaetigungen und unbrauchbare Eingaben fuehren zurueck ins Formular. */
    public function testCreateRendersErrorsInsteadOfLetter(): void
    {
        $this->importAndSelectPatient();

        $unconfirmed = $this->createLetter(['confirm_data' => '0', 'confirm_letter' => '0']);
        $this->assertSame(422, $unconfirmed->status);
        $this->assertContains('Die Bestätigung „Ja, die Angaben sind geprüft und vollständig.', $unconfirmed->body);
        $this->assertContains('Die Bestätigung, dass der Brief erzeugt werden darf, ist erforderlich.', $unconfirmed->body);
        $this->assertSame(0, $this->rowCount('patient_letters'));

        $invalid = $this->letters()->create(new Request('POST', '/letters', [], ['patient_id' => '0']));
        $this->assertSame(422, $invalid->status);
        $this->assertContains('Brief erstellen', $invalid->body);
        $this->assertContains('Patient wählen', $invalid->body);
        $this->assertSame(0, $this->rowCount('patient_letters'));
    }

    /** Nach dem Erzeugen zeigt die Detailseite Inhalt, Anhang, Nachweise und weitere Briefe. */
    public function testShowRendersLetterAndPdf(): void
    {
        $reportId = $this->importAndSelectPatient();
        $this->fillRecords();
        $this->createCard();

        $created = $this->createLetter(['report_id' => (string) $reportId]);
        $this->assertSame(303, $created->status);
        $this->assertSame('/letters/1', $created->headers['Location'] ?? '');

        $second = $this->createLetter(['report_id' => (string) $reportId]);
        $this->assertSame(303, $second->status);

        $show = $this->letters()->show(new Request('GET', '/letters/1'), ['id' => '1']);
        $this->assertSame(200, $show->status);
        $this->assertContains('Brief Nr. 1', $show->body);
        $this->assertContains('Patientendaten (eingefroren)', $show->body);
        $this->assertContains('LASTNAME, FIRSTNAME', $show->body);
        $this->assertContains('Brieftext', $show->body);
        $this->assertContains('Anamnese', $show->body);
        $this->assertContains('Vormedikation', $show->body);
        $this->assertContains('Befund', $show->body);
        $this->assertContains('Regelmäßiger Eigenrhythmus, keine Sondenauffälligkeit.', $show->body);
        $this->assertContains('Epikrise', $show->body);
        $this->assertContains('Berichte (Befundteil des Berichts)', $show->body);
        $this->assertContains('Anhang: vollständige Schrittmacher-/ICD-Abfrage', $show->body);
        $this->assertContains('Programmierung Bradykardie', $show->body);
        $this->assertContains('Weitere Briefe dieses Patienten', $show->body);
        $this->assertContains('/letters/patients/' . $this->patientId, $show->body);

        $pdf = $this->letters()->pdf(new Request('GET', '/letters/1/pdf'), ['id' => '1']);
        $this->assertSame(200, $pdf->status);
        $this->assertSame('application/pdf', $pdf->headers['Content-Type'] ?? '');
        $this->assertContains('inline; filename="', $pdf->headers['Content-Disposition'] ?? '');
        $this->assertContains('%PDF', substr($pdf->body, 0, 8));

        $download = $this->letters()->pdf(new Request('GET', '/letters/1/pdf', ['download' => '1']), ['id' => '1']);
        $this->assertContains('attachment; filename="', $download->headers['Content-Disposition'] ?? '');

        $this->assertThrows(
            HttpException::class,
            fn (): Response => $this->letters()->show(new Request('GET', '/letters/999'), ['id' => '999']),
        );
        $this->assertThrows(
            HttpException::class,
            fn (): Response => $this->letters()->pdf(new Request('GET', '/letters/999/pdf'), ['id' => '999']),
        );
    }

    /** Neuausfertigung und Reproduktion ueber die Briefansicht; die aktuelle Vorlage nur per Opt-in. */
    public function testShowOffersReissueAndReproduction(): void
    {
        $reportId = $this->importAndSelectPatient();
        $this->fillRecords();
        $this->createCard();
        $this->createLetter(['report_id' => (string) $reportId]);

        $show = $this->letters()->show(new Request('GET', '/letters/1'), ['id' => '1']);
        $this->assertContains('id="neuausfertigung"', $show->body);
        $this->assertContains('action="/letters/1/regenerate"', $show->body);
        $this->assertContains('name="confirm_current_template"', $show->body);
        $this->assertContains('/letters/1/reproduce', $show->body);
        $this->assertContains('Standardvorlage', $show->body);

        $reproduced = $this->letters()->reproduce(new Request('GET', '/letters/1/reproduce'), ['id' => '1']);
        $this->assertSame(200, $reproduced->status);
        $this->assertSame('application/pdf', $reproduced->headers['Content-Type'] ?? '');

        $_SESSION = [];
        $refused = $this->letters()->regenerate(new Request('POST', '/letters/1/regenerate', [], ['template' => 'current']), ['id' => '1']);
        $this->assertSame(303, $refused->status);
        $this->assertSame('/letters/1#neuausfertigung', $refused->headers['Location'] ?? '');
        $this->assertSame('error', $_SESSION['_flash'][0]['type'] ?? '');
        $this->assertSame(1, $this->rowCount('patient_letters'));

        $_SESSION = [];
        $done = $this->letters()->regenerate(new Request('POST', '/letters/1/regenerate', [], ['template' => 'original']), ['id' => '1']);
        $this->assertSame(303, $done->status);
        $this->assertSame('/letters/2', $done->headers['Location'] ?? '');
        $this->assertSame('success', $_SESSION['_flash'][0]['type'] ?? '');

        $copy = $this->letters()->show(new Request('GET', '/letters/2'), ['id' => '2']);
        $this->assertContains('Neuausfertigung von', $copy->body);
        $this->assertContains('/letters/1', $copy->body);
        $_SESSION = [];
    }

    /** Der Vorlageneditor rendert als eigenstaendige Seite; Speichern, Fassungen und Vorschau per JSON/PDF. */
    public function testTemplateEditorEndpoints(): void
    {
        $controller = new LetterTemplateController($this->app, new View(dirname(__DIR__, 2) . '/templates'));

        $page = $controller->editor(new Request('GET', '/system/letter-templates'));
        $this->assertSame(200, $page->status);
        $this->assertContains('/assets/js/template-editor.js', $page->body);
        $this->assertContains('/assets/css/template-editor.css', $page->body);
        $this->assertContains('id="template-editor-data"', $page->body);
        $this->assertContains('data-te-blocks', $page->body);
        $this->assertNotContains('<script>', $page->body);
        $this->assertNotContains('style="', $page->body);
        preg_match('#<script type="application/json" id="template-editor-data">(.*?)</script>#s', $page->body, $match);
        $data = json_decode($match[1] ?? '', true);
        $this->assertSame(1, $data['current']['version_no']);
        $this->assertSame('subject', $data['definition']['default']['blocks'][0]['type']);
        $this->assertSame('/system/letter-templates', $data['urls']['save']);

        $content = $data['current']['content'];
        $content['blocks'] = array_reverse($content['blocks']);
        $content['blocks'][] = ['id' => 'text-2', 'type' => 'text', 'enabled' => true, 'texts' => ['heading' => 'Hinweis', 'text' => 'Bitte {unbekannt} beachten.']];
        $invalid = $controller->save(new Request('POST', '/system/letter-templates', [], [
            'content' => json_encode($content),
            'base_version_id' => (string) $data['current']['id'],
        ]));
        $this->assertSame(422, $invalid->status);
        $errors = json_decode($invalid->body, true)['errors'];
        $this->assertTrue(isset($errors['blocks.' . (count($content['blocks']) - 1) . '.texts.text']));

        $content['blocks'][count($content['blocks']) - 1]['texts']['text'] = 'Bitte beachten, {patient_name}.';
        $saved = $controller->save(new Request('POST', '/system/letter-templates', [], [
            'content' => json_encode($content),
            'comment' => 'Reihenfolge umgekehrt',
            'base_version_id' => (string) $data['current']['id'],
        ]));
        $this->assertSame(200, $saved->status);
        $payload = json_decode($saved->body, true);
        $this->assertTrue($payload['ok']);
        $this->assertSame(2, $payload['current']['version_no']);
        $this->assertSame('reports', $payload['current']['content']['blocks'][0]['type']);
        $this->assertCount(2, $payload['versions']);
        $this->assertSame('Reihenfolge umgekehrt', $payload['versions'][0]['comment']);

        // Veraltete Grundlage wird abgelehnt (kein stilles Ueberschreiben).
        $conflict = $controller->save(new Request('POST', '/system/letter-templates', [], [
            'content' => json_encode($data['current']['content']),
            'base_version_id' => (string) $data['current']['id'],
        ]));
        $this->assertSame(422, $conflict->status);
        $this->assertTrue(isset(json_decode($conflict->body, true)['errors']['base_version']));

        $broken = $controller->save(new Request('POST', '/system/letter-templates', [], ['content' => '{kaputt']));
        $this->assertSame(422, $broken->status);

        $version = $controller->version(new Request('GET', '/system/letter-templates/versions/1'), ['id' => (string) $data['current']['id']]);
        $this->assertSame(1, json_decode($version->body, true)['version']['version_no']);
        $this->assertThrows(HttpException::class, fn (): Response => $controller->version(new Request('GET', '/x'), ['id' => '999']));

        $preview = $controller->preview(new Request('POST', '/system/letter-templates/preview', [], ['content' => json_encode($content)]));
        $this->assertSame(200, $preview->status);
        $this->assertSame('application/pdf', $preview->headers['Content-Type'] ?? '');
        $badPreview = $controller->preview(new Request('POST', '/system/letter-templates/preview', [], ['content' => '[]']));
        $this->assertSame(422, $badPreview->status);
        $this->assertContains('Die Vorschau ist nicht möglich', $badPreview->body);
    }

    /** Vorlagen werden je Empfaengerart getrennt gefasst; die Anrede steht in den Stammdaten. */
    /** Die Empfaengerart des Editors (Dropdown) trennt die Fassungsverlaeufe. */
    public function testTemplateEditorSeparatesRecipientTypes(): void
    {
        $controller = new LetterTemplateController($this->app, new View(dirname(__DIR__, 2) . '/templates'));

        $physician = $controller->editor(new Request('GET', '/system/letter-templates', ['type' => 'family_doctor']));
        $this->assertSame(200, $physician->status);
        $this->assertContains('Art der Vorlage', $physician->body);
        $data = $this->editorData($physician->body);
        $this->assertSame('family_doctor', $data['type']);
        $this->assertSame('family_doctor', $data['definition']['type']);
        $this->assertSame(
            ['patient' => 'Patient', 'family_doctor' => 'Hausarzt', 'referring_physician' => 'Überweisender Arzt', 'generic' => 'Arztbrief generisch'],
            $data['definition']['types'],
        );
        $this->assertSame('Standardvorlage Hausarzt', $data['current']['name']);
        $this->assertSame(1, $data['current']['version_no']);
        $this->assertSame('/system/letter-templates/source', $data['urls']['source']);
        $this->assertSame('Anrede des Empfängers (aus den Stammdaten)', $data['definition']['placeholders']['salutation']);
        $this->assertSame('{salutation}', $data['current']['content']['blocks'][1]['texts']['text']);

        // Unbekannte Art faellt auf die Patientenvorlage zurueck.
        $fallback = $controller->editor(new Request('GET', '/system/letter-templates', ['type' => 'praxis']));
        $this->assertSame('patient', $this->editorData($fallback->body)['type']);

        // Fassungen laufen je Empfaengerart eigenstaendig.
        $content = $data['current']['content'];
        $content['blocks'][7]['texts']['text'] = 'Mit kollegialen Grüßen, wir berichten über {patient_name}.';
        $saved = $controller->save(new Request('POST', '/system/letter-templates', [], [
            'content' => json_encode($content),
            'comment' => 'Hausarzt angepasst',
            'type' => 'family_doctor',
            'base_version_id' => (string) $data['current']['id'],
        ]));
        $this->assertSame(200, $saved->status, $saved->body);
        $payload = json_decode($saved->body, true);
        $this->assertTrue($payload['ok']);
        $this->assertSame(2, $payload['current']['version_no']);
        $this->assertSame('family_doctor', $payload['current']['type']);
        $this->assertContains('Hausarzt', $payload['message']);

        $patient = $controller->editor(new Request('GET', '/system/letter-templates'));
        $this->assertSame(1, $this->editorData($patient->body)['current']['version_no'], 'Die Patientenvorlage bleibt unberuehrt.');

        // Die andere Art wird fuer "Uebernehmen aus Vorlage" als JSON geliefert.
        $source = $controller->source(new Request('GET', '/system/letter-templates/source', ['type' => 'family_doctor']));
        $this->assertSame(200, $source->status);
        $this->assertContains('application/json', $source->headers['Content-Type'] ?? '');
        $sourcePayload = json_decode($source->body, true);
        $this->assertSame('family_doctor', $sourcePayload['type']);
        $this->assertSame('Hausarzt', $sourcePayload['label']);
        $this->assertSame('Standardvorlage Hausarzt', $sourcePayload['default_name']);
        $this->assertSame(2, $sourcePayload['template']['version_no']);
        $this->assertCount(2, $sourcePayload['versions']);

        // Der generische Arztbrief ist eine eigene Art mit eigenem Fassungsverlauf.
        $generic = $controller->editor(new Request('GET', '/system/letter-templates', ['type' => 'generic']));
        $this->assertSame(200, $generic->status);
        $genericData = $this->editorData($generic->body);
        $this->assertSame('generic', $genericData['type']);
        $this->assertSame('Standardvorlage Arztbrief generisch', $genericData['current']['name']);
        $this->assertSame(1, $genericData['current']['version_no']);
        $this->assertSame("An die weiterbehandelnden\nÄrztinnen und Ärzte", $genericData['current']['content']['zones']['recipient']['texts']['text']);

        $genericContent = $genericData['current']['content'];
        $genericContent['blocks'][7]['texts']['text'] = 'Mit kollegialen Grüßen, wir bitten um Weiterbehandlung.';
        $genericSaved = $controller->save(new Request('POST', '/system/letter-templates', [], [
            'content' => json_encode($genericContent),
            'comment' => 'Generisch angepasst',
            'type' => 'generic',
            'base_version_id' => (string) $genericData['current']['id'],
        ]));
        $this->assertSame(200, $genericSaved->status, $genericSaved->body);
        $savedPayload = json_decode($genericSaved->body, true);
        $this->assertSame('generic', $savedPayload['current']['type']);
        $this->assertSame(2, $savedPayload['current']['version_no']);
        $this->assertSame(
            1,
            $this->editorData($controller->editor(new Request('GET', '/system/letter-templates'))->body)['current']['version_no'],
            'Die Patientenvorlage bleibt unberuehrt.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function editorData(string $body): array
    {
        preg_match('#<script type="application/json" id="template-editor-data">(.*?)</script>#s', $body, $match);
        return (array) json_decode($match[1] ?? '', true);
    }

    /** Die Patientenseite listet die Briefe eines Patienten und lehnt unbekannte ab. */
    public function testPatientPageRendersLetters(): void
    {
        $this->importAndSelectPatient();
        $this->fillRecords();
        $this->createCard();
        $this->createLetter([]);

        $response = $this->letters()->patient(
            new Request('GET', '/letters/patients/' . $this->patientId),
            ['patient' => (string) $this->patientId],
        );

        $this->assertSame(200, $response->status);
        $this->assertContains('Briefe – LASTNAME, FIRSTNAME', $response->body);
        $this->assertContains('Briefnummer', $response->body);
        $this->assertContains('/letters/1', $response->body);
        $this->assertContains('<th>Anhang</th>', $response->body);
        $this->assertContains('/letters/1/pdf?download=1', $response->body);

        $this->assertThrows(
            HttpException::class,
            fn (): Response => $this->letters()->patient(new Request('GET', '/letters/patients/999'), ['patient' => '999']),
        );
    }

    /**
     * Patienten aus Importen vor Migration 002 haben nur patient_name; last_name/first_name
     * sind NULL. Der Brief leitet die Namen daraus ab, statt mit HTTP 500 abzubrechen.
     */
    public function testCreateDerivesNamesForLegacyImportedPatient(): void
    {
        $this->importAndSelectPatient();
        $this->pdo->prepare('UPDATE patients SET last_name = NULL, first_name = NULL WHERE id = ?')
            ->execute([$this->patientId]);

        $response = $this->createLetter([]);

        $this->assertSame(303, $response->status);
        $stmt = $this->pdo->prepare('SELECT last_name, first_name, patient_name FROM patient_letters WHERE patient_id = ?');
        $stmt->execute([$this->patientId]);
        $this->assertSame(
            ['last_name' => 'LASTNAME', 'first_name' => 'FIRSTNAME', 'patient_name' => 'LASTNAME, FIRSTNAME'],
            $stmt->fetch(\PDO::FETCH_ASSOC),
        );
    }

    /** Fehlt das Geburtsdatum, erscheint ein Hinweis im Assistenten statt HTTP 500. */
    public function testCreateRejectsPatientWithoutDateOfBirth(): void
    {
        $this->importAndSelectPatient();
        $this->pdo->prepare('UPDATE patients SET date_of_birth = NULL WHERE id = ?')->execute([$this->patientId]);

        $response = $this->createLetter([]);

        $this->assertSame(422, $response->status);
        $this->assertContains('In den Stammdaten des Patienten fehlt: Geburtsdatum.', $response->body);
        $this->assertSame(0, $this->rowCount('patient_letters'));
    }
}
