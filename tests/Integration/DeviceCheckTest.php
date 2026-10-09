<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application;
use App\Config\Config;
use App\Http\Controller\PatientController;
use App\Http\Request;
use App\Http\View;
use App\Import\ImportOutcome;
use App\Patient\DeviceCheckPrefill;
use App\Patient\DeviceCheckTemplate;
use App\Patient\PatientException;
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
use App\PatientCard\PatientCardTemplateRepository;
use App\PatientCard\PatientCardTemplateService;
use App\Security\ImageUploadValidator;
use Tests\Support\Fixtures;

/**
 * Aktenbaustein "Schrittmacher-/ICD-Abfrage": Fassungen, Vorbelegung aus dem Bericht und
 * fuehrende Angabe zur MRT-Tauglichkeit aus dem Patientenausweis.
 */
final class DeviceCheckTest extends DatabaseTestCase
{
    private DeviceCheckTemplate $template;
    private PatientRepository $patients;
    private PatientCardRepository $cards;
    private PatientRecordService $records;
    private int $patientId;

    public function setUp(): void
    {
        parent::setUp();
        $this->template = DeviceCheckTemplate::default(dirname(__DIR__, 2));
        $this->patients = new PatientRepository($this->pdo);
        $this->cards = new PatientCardRepository($this->pdo);
        $this->records = new PatientRecordService(
            new PatientRecordRepository($this->pdo),
            $this->patients,
            $this->clock,
            $this->template,
        );
        $this->patientId = (int) (new PatientService($this->pdo, $this->patients, $this->clock))
            ->create(PatientInput::fromPost([
                'last_name' => 'Mustermann',
                'first_name' => 'Erika',
                'date_of_birth' => '21.10.1938',
                'patient_identifier' => 'P-100',
            ]))['patient_id'];
    }

    private function prefill(): DeviceCheckPrefill
    {
        return new DeviceCheckPrefill($this->patients, $this->cards, $this->reportService());
    }

    private function cardService(): PatientCardService
    {
        return new PatientCardService(
            $this->pdo,
            $this->cards,
            $this->reportService(),
            new PatientCardPdfGenerator(),
            $this->clock,
            MeasurementTemplate::default(dirname(__DIR__, 2)),
            new PatientCardTemplateService(new PatientCardTemplateRepository($this->pdo), $this->clock),
        );
    }

    private function importSample(): ImportOutcome
    {
        $service = $this->importService();
        $bytes = Fixtures::sampleFile();
        return $service->import($service->analyze($bytes, 'MERLIN__ANN_5809481.log'), $bytes);
    }

    /**
     * Patienten-ID, die der Import anhand der Patienten-ID des Berichts angelegt hat.
     */
    private function importedPatientId(): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM patients WHERE patient_identifier = ?');
        $stmt->execute(['10358141']);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Ausweis mit Angaben zur MRT-Tauglichkeit aus dem importierten Bericht.
     */
    private function createCard(string $mrt = 'MRT-bedingt tauglich', string $note = 'Nur mit Auflagen.'): int
    {
        $settings = new PatientCardSettingsService($this->cards, new ImageUploadValidator(), $this->clock);
        $settings->save([
            'center_name' => 'Nachsorgezentrum Beispielstadt',
            'center_address' => "Musterweg 5\n12345 Beispielstadt",
            'notice_text' => 'Hinweis.',
            'flight_notice_de' => 'Hinweis Flug.',
            'flight_notice_en' => 'Flight notice.',
        ], null, false);

        $outcome = $this->importSample();
        $service = $this->cardService();
        $wizard = $service->wizard($outcome->reportId);

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

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function post(array $overrides = []): array
    {
        return array_replace([
            'device_type' => 'pacemaker',
            'values' => [
                'device.manufacturer' => 'Medtronic',
                'device.mrt_compatibility' => 'MRT-tauglich',
                'brady.mode' => 'DDD',
                'ra.output' => '1,5/0,4',
            ],
            'leads' => [
                ['model' => '5076-52', 'location' => 'RA', 'implant_date' => '15.01.2020', 'impedance' => '620'],
            ],
            'notes' => 'Kontrolle ohne Auffälligkeit.',
            'author_name' => 'Dr. Beispiel',
        ], $overrides);
    }

    /** Eine Fassung speichert Vorlagenfassung, Geraeteart, Werte und Sonden. */
    public function testSaveStoresVersionWithTemplateVersion(): void
    {
        $result = $this->records->save($this->patientId, PatientRecordType::DeviceCheck, $this->post());

        $this->assertSame(1, $result['version']);
        $this->assertFalse($result['unchanged']);
        $this->assertSame(1, $this->rowCount('patient_record_versions'));

        $row = $this->pdo->query('SELECT content, content_text FROM patient_record_versions')->fetch(\PDO::FETCH_ASSOC);
        $content = json_decode((string) $row['content'], true);
        $this->assertSame('1.0.0', $content['template'], 'Die Fassung merkt sich die Vorlagenfassung.');
        $this->assertSame('pacemaker', $content['device_type']);
        $this->assertSame('DDD', $content['values']['brady.mode']);
        $this->assertSame('2020-01-15', $content['leads'][0]['implant_date']);

        $current = $this->records->current($this->patientId, PatientRecordType::DeviceCheck);
        $this->assertSame('Schrittmacher', $current['device_check']['device_type_label']);
        $this->assertSame(1, count($current['device_check']['leads']));
        // vier Feldwerte und vier Angaben der Sondenzeile
        $this->assertSame(8, $current['device_check']['filled']);
        $this->assertFalse($current['empty']);
        $this->assertContains('Gerät · Hersteller: Medtronic', implode("\n", $current['lines']));
        $this->assertContains('Sonden (Elektroden) · Sonde 1 · Lokalisation: RA', implode("\n", $current['lines']));
        $this->assertContains('Programmierung Bradykardie · Betriebsart: DDD', implode("\n", $current['lines']));
    }

    /** Inhaltsgleiche Wiederholung erzeugt keine neue Fassung. */
    public function testIdenticalContentDoesNotCreateVersion(): void
    {
        $this->records->save($this->patientId, PatientRecordType::DeviceCheck, $this->post());
        $result = $this->records->save($this->patientId, PatientRecordType::DeviceCheck, $this->post());

        $this->assertTrue($result['unchanged']);
        $this->assertSame(1, $result['version']);
        $this->assertSame(1, $this->rowCount('patient_record_versions'));
    }

    /** Angaben der falschen Geraeteart werden benannt und nicht gespeichert. */
    public function testForeignFieldsAreRejected(): void
    {
        $exception = $this->assertThrows(
            PatientException::class,
            fn () => $this->records->save($this->patientId, PatientRecordType::DeviceCheck, $this->post([
                'values' => ['brady.mode' => 'DDD', 'tachy.vt1.rate' => '180'],
            ])),
        );

        $errors = $exception->fieldErrors();
        $this->assertContains('nicht vorgesehen', $errors['device_type']);
        $this->assertContains('Erkennung: Frequenz (1/min)', $errors['device_type']);
        $this->assertSame(0, $this->rowCount('patient_record_versions'));
    }

    /** Der Baustein erscheint mit Geraeteart und Umfang in der Aktenuebersicht. */
    public function testOverviewShowsDeviceCheck(): void
    {
        $this->records->save($this->patientId, PatientRecordType::DeviceCheck, $this->post());

        $overview = $this->records->overview($this->patientId);
        $this->assertTrue(isset($overview['device_check']));
        $this->assertSame('Schrittmacher-/ICD-Abfrage', $overview['device_check']['label']);
        $this->assertSame('Schrittmacher', $overview['device_check']['device_check']['device_type_label']);
        $this->assertSame(8, $overview['device_check']['device_check']['filled']);
    }

    /** Die Vorbelegung uebernimmt Werte und Sonden des letzten Berichts. */
    public function testPrefillFromLastReport(): void
    {
        $outcome = $this->importSample();
        $patientId = $this->importedPatientId();
        $this->assertTrue($patientId > 0, 'Der Import hat den Patienten angelegt.');

        $prefill = $this->prefill()->fromLastReport($patientId, 'pacemaker', $this->template);

        $this->assertSame($outcome->reportId, $prefill['report_id']);
        $this->assertContains('Bericht Nr. ' . $outcome->reportId, $prefill['report_label']);
        $this->assertSame('VVI', $prefill['values']['brady.mode'], 'Betriebsart aus Parameter 301.');
        $this->assertSame('60', $prefill['values']['brady.lower_rate']);
        $this->assertSame('Off', $prefill['values']['brady.hysteresis_rate']);
        $this->assertSame('130', $prefill['values']['brady.max_sync_rate']);
        $this->assertSame('130', $prefill['values']['brady.max_sensor_rate']);
        $this->assertSame('Off', $prefill['values']['av.search_hysteresis']);
        $this->assertSame('100.0', $prefill['values']['battery.magnet_rate']);
        $this->assertSame('8.9', $prefill['values']['battery.longevity']);
        $this->assertSame('2.5/0.4', $prefill['values']['rv.output'], 'Amplitude und Pulsbreite werden verbunden.');
        $this->assertSame('2.0', $prefill['values']['rv.sensitivity']);
        $this->assertSame('Bipolar', $prefill['values']['rv.pace_polarity']);
        $this->assertSame('Bipolar', $prefill['values']['ra.sense_polarity']);
        $this->assertSame('175', $prefill['values']['ra.pvarp']);

        // Angaben, die der Bericht nicht enthaelt, bleiben leer - es wird nichts erfunden.
        $this->assertTrue(!isset($prefill['values']['battery.status']));
        $this->assertTrue(!isset($prefill['values']['ra.output']));
        $this->assertTrue(!isset($prefill['values']['device.mrt_compatibility']));

        $this->assertCount(2, $prefill['leads']);
        $this->assertSame('RA', $prefill['leads'][0]['location']);
        $this->assertSame('2088TC Tendril STS SJM Atrial Lead', $prefill['leads'][0]['model']);
        $this->assertSame('18.06.2024', $prefill['leads'][0]['implant_date']);
        $this->assertSame('RV', $prefill['leads'][1]['location']);
        $this->assertSame('18.06.2024', $prefill['leads'][1]['implant_date']);
    }

    /** Beim ICD kommen Tachykardie und Schockimpedanz hinzu; Werte bleiben leer, wenn sie fehlen. */
    public function testPrefillForIcdKeepsMissingValuesEmpty(): void
    {
        $this->importSample();
        $patientId = $this->importedPatientId();

        $prefill = $this->prefill()->fromLastReport($patientId, 'icd', $this->template);

        $this->assertSame('VVI', $prefill['values']['brady.mode']);
        $this->assertTrue(!isset($prefill['values']['tachy.vt1.rate']), 'Der Bericht enthaelt keine Tachykardieprogrammierung.');
        $this->assertTrue(!isset($prefill['values']['tachy.vf.therapy']));
        $this->assertTrue(!isset($prefill['leads'][0]['shock_impedance']));
        $this->assertSame(
            count($prefill['values']) + 3 * count($prefill['leads']),
            $prefill['filled'],
            'Gefuellt wird nur, was der Bericht hergibt.',
        );
    }

    /** Ohne Bericht bleibt die Vorbelegung leer; die Meldung stuetzt sich darauf. */
    public function testPrefillWithoutReport(): void
    {
        $prefill = $this->prefill()->fromLastReport($this->patientId, 'pacemaker', $this->template);

        $this->assertNull($prefill['report_id']);
        $this->assertSame('', $prefill['report_label']);
        $this->assertSame([], $prefill['values']);
        $this->assertSame([], $prefill['leads']);
        $this->assertSame(0, $prefill['filled']);
    }

    /** Unbekannte Geraeteart: keine Vorbelegung. */
    public function testPrefillWithoutValidDeviceType(): void
    {
        $this->importSample();
        $prefill = $this->prefill()->fromLastReport($this->importedPatientId(), 'sonstiges', $this->template);

        $this->assertNull($prefill['report_id']);
        $this->assertSame([], $prefill['values']);
    }

    /** Die Angabe zur MRT-Tauglichkeit stammt aus dem Ausweis und ist dort fuehrend. */
    public function testCardIsLeadingSourceForMrt(): void
    {
        $this->createCard('MRT-bedingt tauglich', 'Nur mit Auflagen.');
        $patientId = $this->importedPatientId();
        $prefill = $this->prefill();

        $card = $prefill->cardMrt($patientId, $this->template);
        $this->assertTrue($card['locked']);
        $this->assertSame('MRT-bedingt tauglich', $card['values']['device.mrt_compatibility']);
        $this->assertSame('Nur mit Auflagen.', $card['values']['device.mrt_compatibility_note']);

        $values = $prefill->fromLastReport($patientId, 'pacemaker', $this->template)['values'];
        $this->assertSame('MRT-bedingt tauglich', $values['device.mrt_compatibility'], 'Der Ausweis hat Vorrang vor dem Bericht.');
        $this->assertSame('Nur mit Auflagen.', $values['device.mrt_compatibility_note']);
        $this->assertSame('VVI', $values['brady.mode'], 'Die uebrigen Werte stammen weiterhin aus dem Bericht.');
    }

    /** Ohne Ausweis bleibt die MRT-Tauglichkeit in der Abfrage erfassbar. */
    public function testWithoutCardMrtStaysEditable(): void
    {
        $card = $this->prefill()->cardMrt($this->patientId, $this->template);

        $this->assertFalse($card['locked']);
        $this->assertSame([], $card['values']);
    }

    /** Ein Ausweis ohne Angabe zur MRT-Tauglichkeit sperrt das Feld nicht. */
    public function testCardWithoutMrtDoesNotLockTheField(): void
    {
        $this->createCard('', '');
        $patientId = $this->importedPatientId();

        $card = $this->prefill()->cardMrt($patientId, $this->template);
        $this->assertFalse($card['locked']);
        $this->assertSame([], $card['values']);
    }

    /** Der Ausweis sperrt das Feld im Formular; eine abweichende Eingabe wird nicht gespeichert. */
    public function testCardLocksMrtFieldAndWinsOverInput(): void
    {
        $this->createCard('MRT-bedingt tauglich', 'Nur mit Auflagen.');
        $patientId = $this->importedPatientId();

        $form = $this->controller()->recordForm(
            new Request('GET', '/patients/' . $patientId . '/records/device_check'),
            ['id' => (string) $patientId, 'slug' => 'device_check'],
        );
        $this->assertContains('readonly aria-readonly="true"', $form->body, 'Die Zusatzangabe ist schreibgeschuetzt.');
        $this->assertContains('data-locked', $form->body);
        $this->assertContains(
            '<select id="f-values-device-mrt_compatibility-" name="values[device.mrt_compatibility]" disabled>',
            $form->body,
            'Das Auswahlfeld ist gesperrt.',
        );
        $this->assertContains(
            '<input type="hidden" name="values[device.mrt_compatibility]" value="MRT-bedingt tauglich">',
            $form->body,
            'Der Wert des Ausweises wird mitgesendet.',
        );
        $this->assertContains('Wird aus dem Patientenausweis übernommen und dort gepflegt.', $form->body);
        $this->assertContains('value="MRT-bedingt tauglich"', $form->body);
        $this->assertContains('value="Nur mit Auflagen."', $form->body);

        $this->controller()->saveRecord(
            new Request('POST', '/patients/' . $patientId . '/records/device_check', [], $this->post([
                'values' => ['device.manufacturer' => 'Medtronic', 'device.mrt_compatibility' => 'MRT-tauglich'],
            ])),
            ['id' => (string) $patientId, 'slug' => 'device_check'],
        );

        $current = $this->records->current($patientId, PatientRecordType::DeviceCheck);
        $this->assertSame(
            'MRT-bedingt tauglich',
            $current['device_check']['values']['device.mrt_compatibility'],
            'Die Angabe des Ausweises hat Vorrang vor der Eingabe.',
        );
        $this->assertSame('Nur mit Auflagen.', $current['device_check']['values']['device.mrt_compatibility_note']);
    }

    /** Der Knopf "Werte uebernehmen" fuellt das Formular aus dem letzten Bericht. */
    public function testPrefillEndpointFillsTheForm(): void
    {
        $outcome = $this->importSample();
        $patientId = $this->importedPatientId();

        $response = $this->controller()->prefillRecord(
            new Request('POST', '/patients/' . $patientId . '/records/device_check/prefill', [], [
                'device_type' => 'pacemaker',
                'values' => ['device.manufacturer' => 'Eigene Angabe'],
            ]),
            ['id' => (string) $patientId, 'slug' => 'device_check'],
        );

        $this->assertSame(200, $response->status);
        $this->assertContains('Werte aus Bericht Nr. ' . $outcome->reportId . ' übernommen', $response->body);
        $this->assertContains('Vorhandene Eingaben wurden nicht überschrieben.', $response->body);
        $this->assertContains('value="Eigene Angabe"', $response->body, 'Vorhandene Eingabe bleibt erhalten.');
        $this->assertContains('value="VVI"', $response->body, 'Betriebsart aus dem Bericht.');
        $this->assertContains('value="60"', $response->body);
        $this->assertContains('value="18.06.2024"', $response->body);
        $this->assertContains('value="RA" selected', $response->body);
        $this->assertSame(0, $this->rowCount('patient_record_versions'), 'Die Vorbelegung speichert nichts.');
    }

    private function controller(): PatientController
    {
        return new PatientController(
            new Application(Config::fromEnvironment(), dirname(__DIR__, 2), $this->clock),
            new View(dirname(__DIR__, 2) . '/templates'),
        );
    }
}
