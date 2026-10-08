<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Patient\PatientException;
use App\Patient\PatientInput;
use App\Patient\PatientRepository;
use App\Patient\PatientService;

/**
 * Patientenakte: Anlegen ohne Import, Dublettenhinweis, Patienten-ID und Suche.
 */
final class PatientServiceTest extends DatabaseTestCase
{
    private PatientService $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = new PatientService($this->pdo, new PatientRepository($this->pdo), $this->clock);
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

    private function create(array $overrides = []): int
    {
        $result = $this->service->create(PatientInput::fromPost($this->post($overrides)));
        return (int) $result['patient_id'];
    }

    public function testPatientIsCreatedWithoutImport(): void
    {
        $patientId = $this->create();

        $this->assertSame(1, $this->rowCount('patients'));
        $this->assertSame(0, $this->rowCount('reports'), 'Der Patient entsteht ohne Bericht.');
        $this->assertSame(1, $this->rowCount('patient_card_master_data'));

        $patient = $this->service->patient($patientId);
        $this->assertSame('Mustermann, Erika', $patient['patient_name']);
        $this->assertSame('1938-10-21', $patient['date_of_birth']);
        $this->assertSame('21.10.1938', $patient['date_of_birth_raw']);
        $this->assertSame('P-100', $patient['patient_identifier']);
        $this->assertSame('2026-10-07 08:00:00', $patient['created_at']);
        $this->assertSame('mustermann|erika|1938-10-21', PatientService::identityKey($patient));
    }

    public function testMasterDataIsStored(): void
    {
        $patientId = $this->create();
        $master = $this->service->masterData($patientId);

        $this->assertSame('Musterstraße 12', $master['street']);
        $this->assertSame('12345', $master['postal_code']);
        $this->assertSame('Beispielstadt', $master['city']);
        $this->assertSame('01234/56789', $master['phone']);
        $this->assertSame('Bradykardie, geplante Schrittmacherimplantation', $master['indication']);
    }

    public function testDuplicateNeedsConfirmation(): void
    {
        $this->create();

        $e = $this->assertThrows(
            PatientException::class,
            fn () => $this->service->create(PatientInput::fromPost($this->post(['patient_identifier' => '']))),
        );

        $this->assertTrue(isset($e->fieldErrors()['confirm_duplicate']));
        $this->assertContains('gleichem Namen', $e->fieldErrors()['confirm_duplicate']);
        $this->assertSame(1, $this->rowCount('patients'), 'Ohne Bestätigung wird nicht angelegt.');
    }

    public function testDuplicateIsCreatedAfterConfirmation(): void
    {
        $this->create();
        $result = $this->service->create(PatientInput::fromPost($this->post([
            'patient_identifier' => '',
            'confirm_duplicate' => '1',
        ])));

        $this->assertSame(2, $this->rowCount('patients'));
        $this->assertCount(1, $result['duplicates']);
        $this->assertSame(2, (int) $result['patient_id']);
    }

    public function testDuplicateIsDetectedRegardlessOfCaseAndSpacing(): void
    {
        $this->create();
        $duplicates = $this->service->duplicates(PatientInput::fromPost($this->post([
            'last_name' => '  MUSTERMANN ',
            'first_name' => 'erika',
        ])));

        $this->assertCount(1, $duplicates, 'Identität ist unabhängig von Groß-/Kleinschreibung.');
    }

    public function testIdentifierMustBeUnique(): void
    {
        $this->create();

        $e = $this->assertThrows(
            PatientException::class,
            fn () => $this->service->create(PatientInput::fromPost($this->post([
                'last_name' => 'Anders',
                'first_name' => 'Anna',
                'date_of_birth' => '01.01.1970',
                'patient_identifier' => 'P-100',
            ]))),
        );

        $this->assertContains('bereits einem anderen Patienten', $e->fieldErrors()['patient_identifier']);
        $this->assertSame(1, $this->rowCount('patients'));
    }

    public function testUpdateChangesIdentityAndKeepsIdentifier(): void
    {
        $patientId = $this->create();

        $this->service->update($patientId, PatientInput::fromPost($this->post([
            'last_name' => 'Musterfrau',
            'city' => 'Anderstadt',
            'date_of_birth' => '01.02.1940',
        ])));

        $patient = $this->service->patient($patientId);
        $this->assertSame('Musterfrau, Erika', $patient['patient_name']);
        $this->assertSame('1940-02-01', $patient['date_of_birth']);
        $this->assertSame('2026-10-07 08:00:00', $patient['updated_at']);
        $this->assertSame('Anderstadt', $this->service->masterData($patientId)['city']);
        $this->assertSame(1, $this->rowCount('patients'), 'Es entsteht kein zweiter Patient.');
    }

    public function testUpdateOfUnknownPatientFails(): void
    {
        $e = $this->assertThrows(
            PatientException::class,
            fn () => $this->service->update(999, PatientInput::fromPost($this->post())),
        );

        $this->assertContains('nicht gefunden', $e->fieldErrors()['patient']);
    }

    public function testSearchFiltersAndCountsAkte(): void
    {
        $this->create();
        $this->create([
            'last_name' => 'Beispiel',
            'first_name' => 'Bernd',
            'date_of_birth' => '01.01.1970',
            'patient_identifier' => 'P-200',
        ]);

        $all = $this->service->search(['q' => '', 'identifier' => '', 'dob' => ''], 25, 0);
        $this->assertSame(2, $all['total']);
        $this->assertSame('Beispiel, Bernd', $all['rows'][0]['patient_name'], 'Sortierung nach Name.');

        $byName = $this->service->search(['q' => 'mustermann', 'identifier' => '', 'dob' => ''], 25, 0);
        $this->assertSame(1, $byName['total']);
        $this->assertSame('P-100', $byName['rows'][0]['patient_identifier']);
        $this->assertSame(0, (int) $byName['rows'][0]['report_count']);
        $this->assertSame(0, (int) $byName['rows'][0]['record_count']);

        $byIdentifier = $this->service->search(['q' => '', 'identifier' => 'P-2', 'dob' => ''], 25, 0);
        $this->assertSame(1, $byIdentifier['total']);
        $this->assertSame('Beispiel, Bernd', $byIdentifier['rows'][0]['patient_name']);

        $byBirth = $this->service->search(['q' => '', 'identifier' => '', 'dob' => '1970-01-01'], 25, 0);
        $this->assertSame(1, $byBirth['total']);
    }

    public function testPaginationLimitsRows(): void
    {
        foreach (['A', 'B', 'C'] as $index => $letter) {
            $this->create([
                'last_name' => $letter . 'name',
                'first_name' => 'Test',
                'date_of_birth' => '01.01.19' . str_pad((string) (70 + $index), 2, '0', STR_PAD_LEFT),
                'patient_identifier' => 'P-' . $index,
            ]);
        }

        $page = $this->service->search(['q' => '', 'identifier' => '', 'dob' => ''], 2, 2);
        $this->assertSame(3, $page['total']);
        $this->assertCount(1, $page['rows']);
    }

    public function testIdentityKeyIsNullWithoutCompleteIdentity(): void
    {
        $this->assertNull(PatientService::identityKey(['last_name' => 'Nur', 'first_name' => '', 'date_of_birth' => '1970-01-01']));
    }

    public function testReportsAreEmptyForNewPatient(): void
    {
        $patientId = $this->create();
        $this->assertSame([], $this->service->reports($patientId));
    }

    public function testUnknownPatientHasNoMasterData(): void
    {
        $this->assertNull($this->service->patient(999));
        $this->assertNull($this->service->masterData(999));
        $this->assertNull($this->service->identityKey([]));
    }
}
