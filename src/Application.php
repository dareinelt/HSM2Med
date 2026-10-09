<?php

declare(strict_types=1);

namespace App;

use App\Config\Config;
use App\Database\Database;
use App\Database\Migrator;
use App\Import\ImportArchive;
use App\Import\ImportService;
use App\Import\ImportValidator;
use App\Import\ParserChain;
use App\Import\PendingUploadStore;
use App\Letter\DeviceCheckAppendix;
use App\Letter\LetterPdfGenerator;
use App\Letter\LetterRepository;
use App\Letter\LetterService;
use App\Letter\LetterTemplateRepository;
use App\Letter\LetterTemplateService;
use App\Mapping\ParameterMapping;
use App\Patient\ActivePatient;
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
use App\PatientCard\PatientCardTemplateRepository;
use App\PatientCard\PatientCardTemplateService;
use App\Report\ReportService;
use App\Report\ReportSummaryBuilder;
use App\Repository\GroupRepository;
use App\Repository\ImportRepository;
use App\Repository\ReportRepository;
use App\Repository\UserRepository;
use App\Security\Auth;
use App\Security\ImageUploadValidator;
use App\Support\Clock;
use App\Support\LogReader;
use App\Support\Logger;
use App\Support\SystemClock;
use App\User\User;
use App\User\UserService;
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
    private ?ActivePatient $activePatient = null;
    private ?UserRepository $userRepository = null;
    private ?GroupRepository $groupRepository = null;
    private ?UserService $userService = null;
    private ?Auth $auth = null;

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
        return $this->logger ??= new Logger($this->logFile());
    }

    public function logReader(): LogReader
    {
        return new LogReader($this->logFile());
    }

    private function logFile(): ?string
    {
        return is_dir($this->config->dataDir . '/logs') ? $this->config->dataDir . '/logs/app.log' : null;
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
            ParserChain::default(),
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

    /**
     * Der je Sitzung gewaehlte Patient (Patientenvorgang als fuehrender Kontext).
     */
    public function activePatient(): ActivePatient
    {
        return $this->activePatient ??= new ActivePatient();
    }

    /**
     * Kurzangaben zum aktiven Patienten fuer die Oberflaeche (null, wenn keiner gewaehlt ist).
     *
     * @return array{id:int,name:string,birth:string,identifier:string}|null
     */
    public function activePatientSummary(): ?array
    {
        return $this->activePatient()->summary($this->patientService());
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
            $this->patientCardTemplateService(),
        );
    }

    public function patientCardTemplateRepository(): PatientCardTemplateRepository
    {
        return new PatientCardTemplateRepository($this->pdo());
    }

    public function patientCardTemplateService(): PatientCardTemplateService
    {
        return new PatientCardTemplateService($this->patientCardTemplateRepository(), $this->clock);
    }

    public function patientCardSettingsService(): PatientCardSettingsService
    {
        return new PatientCardSettingsService(
            $this->patientCardRepository(),
            new ImageUploadValidator(),
            $this->clock,
        );
    }

    public function letterRepository(): LetterRepository
    {
        return new LetterRepository($this->pdo());
    }

    public function letterService(): LetterService
    {
        return new LetterService(
            $this->pdo(),
            $this->letterRepository(),
            $this->patientCardRepository(),
            $this->patientRecordService(),
            $this->reportService(),
            new DeviceCheckAppendix($this->deviceCheckTemplate()),
            new LetterPdfGenerator(),
            $this->clock,
            $this->letterTemplateService(),
        );
    }

    public function letterTemplateService(): LetterTemplateService
    {
        return new LetterTemplateService(new LetterTemplateRepository($this->pdo()), $this->clock);
    }

    public function pendingUploads(): PendingUploadStore
    {
        return new PendingUploadStore($this->config->dataDir . '/pending', $this->clock);
    }

    public function userRepository(): UserRepository
    {
        return $this->userRepository ??= new UserRepository($this->pdo());
    }

    public function groupRepository(): GroupRepository
    {
        return $this->groupRepository ??= new GroupRepository($this->pdo());
    }

    public function userService(): UserService
    {
        return $this->userService ??= new UserService(
            $this->pdo(),
            $this->userRepository(),
            $this->groupRepository(),
            $this->clock,
        );
    }

    /**
     * Anmeldestatus der Sitzung (Benutzer, Gruppen, Rechte).
     */
    public function auth(): Auth
    {
        return $this->auth ??= new Auth(
            $this->userRepository(),
            $this->clock,
            $this->config->authIdleSeconds(),
        );
    }

    /**
     * Angemeldete Person fuer die Oberflaeche (null, wenn keine Anmeldung vorliegt).
     */
    public function currentUser(): ?User
    {
        return $this->auth()->user();
    }

    public function migrator(): Migrator
    {
        return new Migrator($this->pdo(), $this->rootDir . '/database/migrations');
    }
}
