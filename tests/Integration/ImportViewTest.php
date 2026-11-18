<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application;
use App\Config\Config;
use App\Http\Controller\ImportController;
use App\Http\Request;
use App\Http\View;
use App\Patient\PatientInput;
use App\Security\SessionManager;
use Tests\Support\Fixtures;

/**
 * Rendert die Importvorschau ueber den echten ImportController und prueft die
 * Anzeige und Absicherung des Zielpatienten (M2) sowie die Benutzerauswahl bei
 * Teil-Identitaeten (H1), Mehrfachtreffern (H2) und ID-Konflikten (M1).
 */
final class ImportViewTest extends DatabaseTestCase
{
    private Application $app;
    private string $dataDir;

    public function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $this->dataDir = sys_get_temp_dir() . '/hsm2med-data-' . bin2hex(random_bytes(4));
        @mkdir($this->dataDir . '/pending', 0750, true);
        @mkdir($this->dataDir . '/logs', 0750, true);
        @mkdir($this->dataDir . '/sessions', 0750, true);

        $config = Config::fromEnvironment(array_replace(getenv(), [
            'APP_DATA_DIR' => $this->dataDir,
            'IMPORT_DATA_DIR' => $this->archiveDir,
        ]));
        $this->app = new Application($config, dirname(__DIR__, 2), $this->clock);
    }

    public function tearDown(): void
    {
        $_SESSION = [];
        foreach (['pending', 'logs', 'sessions'] as $sub) {
            foreach (glob($this->dataDir . '/' . $sub . '/*') ?: [] as $file) {
                @chmod($file, 0600);
                @unlink($file);
            }
            @rmdir($this->dataDir . '/' . $sub);
        }
        @rmdir($this->dataDir);
        parent::tearDown();
    }

    private function controller(): ImportController
    {
        return new ImportController(
            $this->app,
            new View(dirname(__DIR__, 2) . '/templates', fn (): ?array => $this->app->activePatientSummary()),
        );
    }

    private function createPatient(string $lastName, string $firstName, string $dob, ?string $identifier = null): int
    {
        $post = [
            'last_name' => $lastName,
            'first_name' => $firstName,
            'date_of_birth' => $dob,
        ];
        if ($identifier !== null) {
            $post['patient_identifier'] = $identifier;
        }
        return (int) $this->app->patientService()->create(PatientInput::fromPost($post))['patient_id'];
    }

    private function stageUpload(string $bytes, string $filename): string
    {
        $token = $this->app->pendingUploads()->store($bytes, $filename);
        $_SESSION['pending_uploads'][$token] = true;
        return $token;
    }

    /** M2: Die Vorschau zeigt Zielpatient und Treffergrund und sichert die Auswahl beim Speichern ab. */
    public function testPreviewShowsTargetPatientAndGuardsAgainstActivePatientSwitch(): void
    {
        $erikaId = $this->createPatient('MUSTER', 'ERIKA', '01.01.1950');
        $maxId = $this->createPatient('BEISPIEL', 'MAX', '02.02.1960');
        $this->app->activePatient()->select($erikaId);

        $bytes = str_replace(
            ['>FIRSTNAME1</value>', '>LASTNAME1</value>', '>19400515</value>'],
            ['></value>', '></value>', '></value>'],
            Fixtures::biotronikFile(),
        );
        $token = $this->stageUpload($bytes, 'BIOIEEE_ANN.xml');

        $preview = $this->controller()->preview(new Request('GET', '/import/' . $token), ['token' => $token]);
        $this->assertSame(200, $preview->status);
        $this->assertContains('Zielpatient', $preview->body);
        $this->assertContains('Treffergrund', $preview->body);
        $this->assertContains('Patient Nr. ' . $erikaId . ' – MUSTER, ERIKA', $preview->body);
        $this->assertContains('Aktiver Patient der Sitzung (die Datei enthält keinerlei Patientendaten)', $preview->body);
        $this->assertContains('name="patient_target" value="patient:' . $erikaId . '"', $preview->body);

        // Zwischen Vorschau und Speichern wechselt der aktive Patient in einem anderen Tab auf Max.
        $this->app->activePatient()->select($maxId);
        $staleCommit = $this->controller()->commit(
            new Request('POST', '/import/' . $token . '/commit', [], ['patient_target' => 'patient:' . $erikaId]),
            ['token' => $token],
        );
        $this->assertSame(303, $staleCommit->status);
        $this->assertSame('/import/' . $token, $staleCommit->headers['Location'] ?? '');
        $this->assertSame(0, $this->rowCount('reports'), 'Kein Speichern bei geaendertem Zielpatienten.');
        $flashes = SessionManager::takeFlashes();
        $this->assertContains('seit der Vorschau geändert', $flashes[0]['message'] ?? '');

        // Nach erneuter Bestaetigung der aktualisierten Vorschau wird fuer Max gespeichert.
        $validCommit = $this->controller()->commit(
            new Request('POST', '/import/' . $token . '/commit', [], ['patient_target' => 'patient:' . $maxId]),
            ['token' => $token],
        );
        $this->assertSame(303, $validCommit->status);
        $this->assertContains('/reports/', $validCommit->headers['Location'] ?? '');
        $this->assertSame(1, $this->rowCount('reports', 'patient_id = ?', [$maxId]));
        $this->assertSame(0, $this->rowCount('reports', 'patient_id = ?', [$erikaId]));
    }

    /** H1/H2: Teil-Identitaet und Mehrfachtreffer zeigen Auswahlfelder in der Vorschau und lehnen leeres Speichern ab. */
    public function testPreviewRendersSelectionOptionsAndRequiresChoiceOnCommit(): void
    {
        $erikaId = $this->createPatient('MUSTER', 'ERIKA', '01.01.1950');
        $this->app->activePatient()->select($erikaId);

        $merlinWithoutDob = str_replace("2431\x1CPatient Date of Birth\x1C10/21/1938 00:00:00\x1C\x1C", '', Fixtures::sampleFile());
        $token = $this->stageUpload($merlinWithoutDob, 'MERLIN_ohne_dob.log');

        $preview = $this->controller()->preview(new Request('GET', '/import/' . $token), ['token' => $token]);
        $this->assertSame(200, $preview->status);
        $this->assertContains('Unvollständige Patientenidentität – Auswahl erforderlich', $preview->body);
        $this->assertContains('missing_patient_dob', $preview->body);
        $this->assertContains('incomplete_patient_identity', $preview->body);
        $this->assertContains('type="radio" name="patient_target" value="patient:' . $erikaId . '"', $preview->body);
        $this->assertContains('type="radio" name="patient_target" value="new"', $preview->body);

        // Absenden ohne Auswahl leitet mit Hinweis zurueck in die Vorschau, ohne den Upload zu verwerfen.
        $missingChoice = $this->controller()->commit(
            new Request('POST', '/import/' . $token . '/commit', [], []),
            ['token' => $token],
        );
        $this->assertSame(303, $missingChoice->status);
        $this->assertSame('/import/' . $token, $missingChoice->headers['Location'] ?? '');
        $this->assertSame(0, $this->rowCount('reports'));
        SessionManager::takeFlashes();

        // Mit ausdruecklicher Auswahl "new" entsteht ein eigener Patient statt einer Fehlzuordnung zu Erika.
        $chosenCommit = $this->controller()->commit(
            new Request('POST', '/import/' . $token . '/commit', [], ['patient_target' => 'new']),
            ['token' => $token],
        );
        $this->assertSame(303, $chosenCommit->status);
        $this->assertContains('/reports/', $chosenCommit->headers['Location'] ?? '');
        $this->assertSame(0, $this->rowCount('reports', 'patient_id = ?', [$erikaId]));
        $this->assertSame(2, $this->rowCount('patients'));
    }

    /** H2 & M1: Mehrfachtreffer und ID-Konflikte bieten Kandidaten in der Vorschau zur Auswahl an. */
    public function testPreviewRendersCandidatesForMultipleMatchesAndIdentifierConflicts(): void
    {
        $firstId = $this->createPatient('LASTNAME1', 'FIRSTNAME1', '15.05.1940');
        $this->pdo->prepare(
            'INSERT INTO patients (patient_name, last_name, first_name, date_of_birth, date_of_birth_raw, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute(['LASTNAME1, FIRSTNAME1', 'LASTNAME1', 'FIRSTNAME1', '1940-05-15', '19400515', '2026-10-07 08:00:00', '2026-10-07 08:00:00']);
        $secondId = (int) $this->pdo->lastInsertId();
        $this->app->activePatient()->select($secondId);

        $token = $this->stageUpload(Fixtures::biotronikFile(), 'BIOIEEE_ANN.xml');
        $preview = $this->controller()->preview(new Request('GET', '/import/' . $token), ['token' => $token]);
        $this->assertSame(200, $preview->status);
        $this->assertContains('Mehrere Patienten mit gleicher Identität – Auswahl erforderlich', $preview->body);
        $this->assertContains('type="radio" name="patient_target" value="patient:' . $firstId . '"', $preview->body);
        $this->assertContains('type="radio" name="patient_target" value="patient:' . $secondId . '"', $preview->body);

        $commit = $this->controller()->commit(
            new Request('POST', '/import/' . $token . '/commit', [], ['patient_target' => 'patient:' . $firstId]),
            ['token' => $token],
        );
        $this->assertSame(303, $commit->status);
        $this->assertSame(1, $this->rowCount('reports', 'patient_id = ?', [$firstId]));
        $this->assertSame(0, $this->rowCount('reports', 'patient_id = ?', [$secondId]));

        // M1: Patienten-ID gehoert anderem Patienten -> Konfliktauswahl in der Vorschau.
        $conflictOwner = $this->createPatient('MUSTER', 'ERIKA', '01.01.1950', '10358141');
        $conflictToken = $this->stageUpload(Fixtures::sampleFile(), 'MERLIN__ANN_5809481.log');
        $conflictPreview = $this->controller()->preview(new Request('GET', '/import/' . $conflictToken), ['token' => $conflictToken]);
        $this->assertSame(200, $conflictPreview->status);
        $this->assertContains('Konflikt bei der Patienten-ID – Entscheidung erforderlich', $conflictPreview->body);
        $this->assertContains('type="radio" name="patient_target" value="new"', $conflictPreview->body);
        $this->assertContains('type="radio" name="patient_target" value="patient:' . $conflictOwner . '"', $conflictPreview->body);
    }
}
