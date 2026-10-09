<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application;
use App\Config\Config;
use App\Http\Controller\PatientCardController;
use App\Http\Controller\PatientCardSettingsController;
use App\Http\Controller\ReportController;
use App\Http\View;
use App\Import\ImportOutcome;
use App\PatientCard\MeasurementTemplate;
use App\PatientCard\PatientCardInput;
use App\PatientCard\PatientCardPdfGenerator;
use App\PatientCard\PatientCardRepository;
use App\PatientCard\PatientCardService;
use App\PatientCard\PatientCardTemplateRepository;
use App\PatientCard\PatientCardTemplateService;
use Tests\Support\Fixtures;

/**
 * Gemeinsame Hilfsmittel der Oberflaechentests rund um den Patientenausweis: Anwendung,
 * Controller, Beispielimport, Stammdaten und das Anlegen eines Ausweises.
 */
abstract class PatientCardTestCase extends DatabaseTestCase
{
    protected Application $app;

    public function setUp(): void
    {
        parent::setUp();
        $this->app = new Application(Config::fromEnvironment(), dirname(__DIR__, 2), $this->clock);
    }

    protected function cards(): PatientCardController
    {
        return new PatientCardController($this->app, new View(dirname(__DIR__, 2) . '/templates'));
    }

    protected function cardSettings(): PatientCardSettingsController
    {
        return new PatientCardSettingsController($this->app, new View(dirname(__DIR__, 2) . '/templates'));
    }

    protected function reports(): ReportController
    {
        return new ReportController($this->app, new View(dirname(__DIR__, 2) . '/templates'));
    }

    protected function repository(): PatientCardRepository
    {
        return new PatientCardRepository($this->pdo);
    }

    protected function service(): PatientCardService
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

    protected function importSample(): ImportOutcome
    {
        $service = $this->importService();
        return $service->import($service->analyze(Fixtures::sampleFile(), 'MERLIN__ANN_5809481.log'), Fixtures::sampleFile());
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function post(array $overrides = []): array
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

    protected function configureSettings(?int $logoId = null): int
    {
        return $this->repository()->saveSettings([
            'center_name' => 'Nachsorgezentrum Beispielstadt',
            'center_address' => "Musterweg 5\n12345 Beispielstadt\nTelefon 01234/56789",
            'practice_phone' => '01234/56789',
            'practice_fax' => '01234/56780',
            'practice_email' => 'praxis@example.de',
            'practice_website' => 'www.example.de',
            'return_name' => 'Nachsorgezentrum Beispielstadt',
            'return_street' => 'Musterweg 5',
            'return_postal_code' => '12345',
            'return_city' => 'Beispielstadt',
            'notice_text' => 'Dieser Ausweis enthält Angaben zum implantierten Schrittmachersystem.',
            'flight_notice_de' => 'Das Gerät kann Metalldetektoren auslösen.',
            'flight_notice_en' => 'The device may trigger metal detectors.',
            'logo_id' => $logoId,
        ], '2026-10-07 08:00:00');
    }

    /**
     * @return array{card_id: int, report_id: int, patient_id: int}
     */
    protected function createCard(): array
    {
        $this->configureSettings();
        $outcome = $this->importSample();
        $service = $this->service();
        $wizard = $service->wizard($outcome->reportId);
        $result = $service->create(PatientCardInput::fromPost($this->post()), $wizard['report'], $wizard['masterData']);

        return [
            'card_id' => $result['card_id'],
            'report_id' => $outcome->reportId,
            'patient_id' => $result['patient_id'],
        ];
    }
}
