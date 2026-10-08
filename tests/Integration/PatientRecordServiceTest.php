<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Patient\DeviceCheckTemplate;
use App\Patient\PatientInput;
use App\Patient\PatientRecordRepository;
use App\Patient\PatientRecordService;
use App\Patient\PatientRecordType;
use App\Patient\PatientRepository;
use App\Patient\PatientService;

/**
 * Aktenbausteine: unveraenderliche Fassungen, Historie und inhaltsgleiche Wiederholung.
 */
final class PatientRecordServiceTest extends DatabaseTestCase
{
    private PatientRecordService $service;
    private PatientService $patients;
    private int $patientId;

    public function setUp(): void
    {
        parent::setUp();
        $repository = new PatientRepository($this->pdo);
        $this->patients = new PatientService($this->pdo, $repository, $this->clock);
        $this->service = new PatientRecordService(
            new PatientRecordRepository($this->pdo),
            $repository,
            $this->clock,
            DeviceCheckTemplate::default(dirname(__DIR__, 2)),
        );
        $this->patientId = (int) $this->patients->create(PatientInput::fromPost([
            'last_name' => 'Mustermann',
            'first_name' => 'Erika',
            'date_of_birth' => '21.10.1938',
            'patient_identifier' => 'P-100',
        ]))['patient_id'];
    }

    public function testFirstVersionIsCreated(): void
    {
        $result = $this->service->save($this->patientId, PatientRecordType::Anamnesis, [
            'text' => 'Belastungsdyspnoe seit 3 Monaten. Synkope 06/2026.',
            'author_name' => 'Dr. Beispiel',
        ]);

        $this->assertSame(1, $result['version']);
        $this->assertFalse($result['unchanged']);
        $this->assertSame(1, $this->rowCount('patient_records'));
        $this->assertSame(1, $this->rowCount('patient_record_versions'));

        $current = $this->service->current($this->patientId, PatientRecordType::Anamnesis);
        $this->assertSame('Anamnese', $current['label']);
        $this->assertFalse($current['structured']);
        $this->assertSame(1, $current['version']);
        $this->assertSame(1, $current['version_count']);
        $this->assertSame('Dr. Beispiel', $current['author_name']);
        $this->assertSame(['Belastungsdyspnoe seit 3 Monaten. Synkope 06/2026.'], $current['lines']);
        $this->assertFalse($current['empty']);
    }

    public function testSecondSaveCreatesNewVersionAndKeepsFirst(): void
    {
        $this->service->save($this->patientId, PatientRecordType::Epicrisis, ['text' => 'Erste Fassung.']);
        $result = $this->service->save($this->patientId, PatientRecordType::Epicrisis, ['text' => 'Zweite Fassung.']);

        $this->assertSame(2, $result['version']);
        $this->assertFalse($result['unchanged']);
        $this->assertSame(1, $this->rowCount('patient_records'), 'Der Behaelter bleibt einer.');
        $this->assertSame(2, $this->rowCount('patient_record_versions'));

        $history = $this->service->history($this->patientId, PatientRecordType::Epicrisis);
        $this->assertCount(2, $history);
        $this->assertSame(2, $history[0]['version'], 'Neueste Fassung zuerst.');
        $this->assertSame('Zweite Fassung.', $history[0]['text']);
        $this->assertSame(1, $history[1]['version']);
        $this->assertSame('Erste Fassung.', $history[1]['text']);
        $this->assertSame('Zweite Fassung.', $this->service->text($this->patientId, PatientRecordType::Epicrisis));
    }

    public function testIdenticalContentDoesNotCreateVersion(): void
    {
        $this->service->save($this->patientId, PatientRecordType::Note, ['text' => 'Gleicher Inhalt.']);
        $result = $this->service->save($this->patientId, PatientRecordType::Note, ['text' => 'Gleicher Inhalt.']);

        $this->assertTrue($result['unchanged']);
        $this->assertSame(1, $result['version']);
        $this->assertSame(1, $this->rowCount('patient_record_versions'));
    }

    public function testVersionCarriesAuthorAndTimestamp(): void
    {
        $this->service->save($this->patientId, PatientRecordType::Anamnesis, [
            'text' => 'Inhalt.',
            'author_name' => 'Dr. Beispiel',
        ]);

        $current = $this->service->current($this->patientId, PatientRecordType::Anamnesis);
        $this->assertSame('2026-10-07 08:00:00', $current['version_created_at']);
        $this->assertSame('Fassung 1, vom 07.10.2026, erfasst von Dr. Beispiel', PatientRecordService::versionLabel($current));
        $this->assertSame('Fassung 1, vom 07.10.2026', PatientRecordService::versionLabel(['version' => 1, 'version_created_at' => '2026-10-07 08:00:00']));
    }

    public function testPremedicationIsStructured(): void
    {
        $this->service->save($this->patientId, PatientRecordType::Premedication, [
            'text' => 'Zusätzlich Vitamin D.',
            'medication' => [
                ['substance' => 'Bisoprolol', 'dose' => '2,5', 'unit' => 'mg', 'schedule' => '1-0-0', 'reason' => 'Bradykardie', 'from' => '01.03.2024', 'to' => ''],
                ['substance' => 'Ramipril', 'dose' => '5', 'unit' => 'mg', 'schedule' => '1-0-0', 'reason' => '', 'from' => '', 'to' => ''],
            ],
        ]);

        $current = $this->service->current($this->patientId, PatientRecordType::Premedication);
        $this->assertTrue($current['structured']);
        $this->assertCount(2, $current['entries']);
        $this->assertSame('2024-03-01', $current['entries'][0]['from']);
        $this->assertSame('', $current['entries'][1]['from']);
        $this->assertCount(3, $current['lines'], 'Zwei Arzneimittelzeilen plus Ergaenzungstext.');
        $this->assertSame('Bisoprolol · 2,5 mg · 1-0-0 · von 01.03.2024 bis fortlaufend · Grund: Bradykardie', $current['lines'][0]);
    }

    public function testOverviewListsOnlySavedTypes(): void
    {
        $this->service->save($this->patientId, PatientRecordType::Anamnesis, ['text' => 'Anamnese.']);
        $this->service->save($this->patientId, PatientRecordType::Premedication, ['medication' => [['substance' => 'Bisoprolol']]]);

        $overview = $this->service->overview($this->patientId);
        $this->assertCount(2, $overview);
        $this->assertTrue(isset($overview['anamnesis'], $overview['premedication']));
        $this->assertFalse(isset($overview['epicrisis']));
        $this->assertSame('Vormedikation', $overview['premedication']['label']);
    }

    public function testTypesAreIndependent(): void
    {
        $this->service->save($this->patientId, PatientRecordType::Anamnesis, ['text' => 'Anamnese.']);
        $this->service->save($this->patientId, PatientRecordType::Epicrisis, ['text' => 'Epikrise.']);

        $this->assertSame(2, $this->rowCount('patient_records'));
        $this->assertSame('Anamnese.', $this->service->text($this->patientId, PatientRecordType::Anamnesis));
        $this->assertSame('Epikrise.', $this->service->text($this->patientId, PatientRecordType::Epicrisis));
    }

    public function testEmptyStateIsEmpty(): void
    {
        $this->assertSame([], $this->service->overview($this->patientId));
        $this->assertNull($this->service->current($this->patientId, PatientRecordType::Anamnesis));
        $this->assertSame([], $this->service->history($this->patientId, PatientRecordType::Anamnesis));
        $this->assertSame('', $this->service->text($this->patientId, PatientRecordType::Anamnesis));
    }

    public function testVersionsBelongToTheirPatient(): void
    {
        $other = (int) $this->patients->create(PatientInput::fromPost([
            'last_name' => 'Beispiel',
            'first_name' => 'Bernd',
            'date_of_birth' => '01.01.1970',
        ]))['patient_id'];

        $this->service->save($this->patientId, PatientRecordType::Anamnesis, ['text' => 'Patient eins.']);
        $this->service->save($other, PatientRecordType::Anamnesis, ['text' => 'Patient zwei.']);

        $this->assertSame(2, $this->rowCount('patient_records'));
        $this->assertSame('Patient eins.', $this->service->text($this->patientId, PatientRecordType::Anamnesis));
        $this->assertSame('Patient zwei.', $this->service->text($other, PatientRecordType::Anamnesis));
    }

    public function testRecordCountIsVisibleInSearch(): void
    {
        $this->service->save($this->patientId, PatientRecordType::Anamnesis, ['text' => 'Anamnese.']);

        $rows = $this->patients->search(['q' => 'Mustermann', 'identifier' => '', 'dob' => ''], 25, 0)['rows'];
        $this->assertSame(1, (int) $rows[0]['record_count']);
        $this->assertSame('2026-10-07 08:00:00', $rows[0]['records_updated_at']);
    }
}
