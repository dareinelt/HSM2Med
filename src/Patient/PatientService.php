<?php

declare(strict_types=1);

namespace App\Patient;

use App\PatientCard\PatientName;
use App\Support\Clock;
use PDO;
use Throwable;

/**
 * Ablauf der Patientenakte: Patienten vor dem Import anlegen und fortschreiben.
 *
 * Regeln:
 *  * Ein Patient kann ohne Import angelegt werden. Anamnese und Vormedikation sind danach
 *    sofort erfassbar; der Import ordnet spaetere Berichte ueber die Identitaet zu.
 *  * Die Identitaet ist Nachname + Vorname + Geburtsdatum. Treffer werden nur als Hinweis
 *    gezeigt; ohne ausdrueckliche Bestaetigung wird nicht angelegt.
 *  * Die Patienten-ID ist eine Zusatzangabe und muss je Patient eindeutig sein.
 *  * Patienten werden nie geloescht.
 */
final class PatientService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PatientRepository $repository,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Sucht Patienten mit gleicher Identitaet (Hinweis fuer die Bestaetigung).
     *
     * @return list<array<string, mixed>>
     */
    public function duplicates(PatientInput $input, ?int $excludePatientId = null): array
    {
        $candidates = [];
        foreach ($this->repository->findPatientsByIdentity($input->identityKey()) as $row) {
            if ($excludePatientId !== null && (int) $row['id'] === $excludePatientId) {
                continue;
            }
            $candidates[] = $row;
        }
        return $candidates;
    }

    /**
     * Legt einen Patienten an. Sind Treffer vorhanden, ist die Bestaetigung erforderlich.
     *
     * @return array{patient_id: int, duplicates: list<array<string, mixed>>}
     */
    public function create(PatientInput $input): array
    {
        $duplicates = $this->duplicates($input);
        $this->guardIdentifier($input, null);
        if ($duplicates !== [] && !$input->confirmDuplicate) {
            throw PatientException::rule(
                'confirm_duplicate',
                sprintf(
                    'Es existiert bereits %d Patient mit gleichem Namen und Geburtsdatum. Bitte die Dublette bestätigen.',
                    count($duplicates),
                ),
            );
        }

        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $now = $this->now();
            $patientId = $this->repository->createPatient(
                $input->patientIdentifier,
                $input->displayName(),
                $input->lastName,
                $input->firstName,
                $input->dateOfBirth,
                $input->dateOfBirthRaw,
                $now,
            );
            $this->repository->saveMasterData($patientId, $input->masterValues(), $now);
            if ($own) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return ['patient_id' => $patientId, 'duplicates' => $duplicates];
    }

    /**
     * Schreibt die Stammdaten eines vorhandenen Patienten fort.
     *
     * @return list<array<string, mixed>> uebersprungene Treffer (leer, wenn keine)
     */
    public function update(int $patientId, PatientInput $input): array
    {
        if ($this->repository->patient($patientId) === null) {
            throw PatientException::rule('patient', 'Der Patient wurde nicht gefunden.');
        }
        $duplicates = $this->duplicates($input, $patientId);
        $this->guardIdentifier($input, $patientId);
        if ($duplicates !== [] && !$input->confirmDuplicate) {
            throw PatientException::rule(
                'confirm_duplicate',
                sprintf('Ein anderer Patient trägt bereits denselben Namen mit demselben Geburtsdatum (%d).', count($duplicates)),
            );
        }

        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $now = $this->now();
            $this->repository->updatePatient(
                $patientId,
                $input->patientIdentifier,
                $input->displayName(),
                $input->lastName,
                $input->firstName,
                $input->dateOfBirth,
                $input->dateOfBirthRaw,
                $now,
            );
            $this->repository->saveMasterData($patientId, $input->masterValues(), $now);
            if ($own) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $duplicates;
    }

    /**
     * Patientenuebersicht mit Suche und Seitenangabe.
     *
     * @param array<string, string> $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function search(array $filters, int $limit, int $offset): array
    {
        return $this->repository->searchPatients($filters, $limit, $offset);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function patient(int $patientId): ?array
    {
        return $this->repository->patient($patientId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function masterData(int $patientId): ?array
    {
        return $this->repository->masterData($patientId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function reports(int $patientId): array
    {
        return $this->repository->reportsByPatient($patientId);
    }

    /**
     * Identitaet eines Patienten als Schluessel (Abgleich mit patients.identity_key).
     *
     * @param array<string, mixed> $patient
     */
    public static function identityKey(array $patient): ?string
    {
        $lastName = (string) ($patient['last_name'] ?? '');
        $firstName = (string) ($patient['first_name'] ?? '');
        $dateOfBirth = (string) ($patient['date_of_birth'] ?? '');
        if ($lastName === '' || $firstName === '' || $dateOfBirth === '') {
            return null;
        }
        return PatientName::identityKey($lastName, $firstName, $dateOfBirth);
    }

    private function guardIdentifier(PatientInput $input, ?int $patientId): void
    {
        $identifier = $input->patientIdentifier;
        if ($identifier === null || $identifier === '') {
            return;
        }
        if ($this->repository->patientIdentifierInUse($identifier, $patientId)) {
            throw PatientException::rule(
                'patient_identifier',
                'Die Patienten-ID ist bereits einem anderen Patienten zugeordnet.',
            );
        }
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
