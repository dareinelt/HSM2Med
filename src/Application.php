<?php

declare(strict_types=1);

namespace App;

use App\Config\Config;
use App\Database\Database;
use App\Database\Migrator;
use App\Import\ImportArchive;
use App\Import\ImportService;
use App\Import\ImportValidator;
use App\Import\MerlinParser;
use App\Import\PendingUploadStore;
use App\Mapping\ParameterMapping;
use App\Patient\DeviceCheckPrefill;
use App\Patient\DeviceCheckTemplate;
use App\Patient\PatientRecordRepository;
use App\Patient\PatientRecordService;
use App\Patient\PatientRepository;
use App\Patient\PatientService;
use App\PatientCard\MeasurementTemplate;
use App\PatientCard\PatientCardPdfGenerator;
use App\PatientCard\PatientCardRepository;
use App\PatientCard\PatientCardService;
use App\PatientCard\PatientCardSettingsService;
use App\Report\ReportService;
use App\Report\ReportSummaryBuilder;
use App\Repository\ImportRepository;
use App\Repository\ReportRepository;
use App\Security\ImageUploadValidator;
use App\Support\Clock;
use App\Support\Logger;
use App\Support\SystemClock;
use PDO;

/**
 * Einfacher Service-Container (lazy).
 */
final class Application
{
    private ?PDO $pdo = null;
    private ?Logger $logger = null;
    private ?ParameterMapping $mapping = null;
    private ?MeasurementTemplate $measurementTemplate = null;
    private ?DeviceCheckTemplate $deviceCheckTemplate = null;
    private ?PatientRepository $patientRepository = null;
    private ?PatientRecordRepository $patientRecordRepository = null;
    private ?PatientService $patientService = null;
    private ?PatientRecordService $patientRecordService = null;

    public function __construct(
        public readonly Config $config,
        public readonly string $rootDir,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= Database::connect($this->config);
    }

    public function clock(): Clock
    {
        return $this->clock;
    }

    public function logger(): Logger
    {
        return $this->logger ??= new Logger(
            is_dir($this->config->dataDir . '/logs') ? $this->config->dataDir . '/logs/app.log' : null,
        );
    }

    public function mapping(): ParameterMapping
    {
        return $this->mapping ??= new ParameterMapping(require $this->rootDir . '/config/parameter_mapping.php');
    }

    public function measurementTemplate(): MeasurementTemplate
    {
        return $this->measurementTemplate ??= MeasurementTemplate::default($this->rootDir);
    }

    public function deviceCheckTemplate(): DeviceCheckTemplate
    {
        return $this->deviceCheckTemplate ??= DeviceCheckTemplate::default($this->rootDir);
    }

    public function deviceCheckPrefill(): DeviceCheckPrefill
    {
        return new DeviceCheckPrefill(
            $this->patientRepository(),
            $this->patientCardRepository(),
            $this->reportService(),
        );
    }

    public function importService(): ImportService
    {
        return new ImportService(
            $this->pdo(),
            new MerlinParser(),
            new ImportValidator(),
            $this->mapping(),
            new ReportSummaryBuilder($this->mapping()),
            $this->clock,
            new ImportArchive($this->config->importDir),
            $this->logger(),
        );
    }

    public function reportRepository(): ReportRepository
    {
        return new ReportRepository($this->pdo());
    }

    public function importRepository(): ImportRepository
    {
        return new ImportRepository($this->pdo());
    }

    public function reportService(): ReportService
    {
        return new ReportService($this->reportRepository(), $this->importRepository());
    }

    public function patientCardRepository(): PatientCardRepository
    {
        return new PatientCardRepository($this->pdo());
    }

    public function patientRepository(): PatientRepository
    {
        return $this->patientRepository ??= new PatientRepository($this->pdo());
    }

    public function patientRecordRepository(): PatientRecordRepository
    {
        return $this->patientRecordRepository ??= new PatientRecordRepository($this->pdo());
    }

    public function patientService(): PatientService
    {
        return $this->patientService ??= new PatientService($this->pdo(), $this->patientRepository(), $this->clock);
    }

    public function patientRecordService(): PatientRecordService
    {
        return $this->patientRecordService ??= new PatientRecordService(
            $this->patientRecordRepository(),
            $this->patientRepository(),
            $this->clock,
            $this->deviceCheckTemplate(),
        );
    }

    public function patientCardService(): PatientCardService
    {
        return new PatientCardService(
            $this->pdo(),
            $this->patientCardRepository(),
            $this->reportService(),
            new PatientCardPdfGenerator(),
            $this->clock,
            $this->measurementTemplate(),
        );
    }

    public function patientCardSettingsService(): PatientCardSettingsService
    {
        return new PatientCardSettingsService(
            $this->patientCardRepository(),
            new ImageUploadValidator(),
            $this->clock,
        );
    }

    public function pendingUploads(): PendingUploadStore
    {
        return new PendingUploadStore($this->config->dataDir . '/pending', $this->clock);
    }

    public function migrator(): Migrator
    {
        return new Migrator($this->pdo(), $this->rootDir . '/database/migrations');
    }
}
