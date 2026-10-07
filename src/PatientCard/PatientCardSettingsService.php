<?php

declare(strict_types=1);

namespace App\PatientCard;

use App\Security\ImageUploadValidator;
use App\Security\UploadException;
use App\Support\Clock;

/**
 * Globale Patientenausweis-Stammdaten: Logo, Nachsorgezentrum und die drei Hinweistexte.
 *
 * Jede Aenderung erzeugt eine neue, unveraenderliche Fassung
 * (patient_card_settings_versions). Bereits erstellte Ausweise verweisen auf ihre Fassung und
 * bleiben deshalb unveraendert. Die Texte sind bewusst nicht im PDF-Generator hinterlegt.
 */
final class PatientCardSettingsService
{
    public const int MAX_CENTER_NAME = 255;
    public const int MAX_CENTER_ADDRESS = 400;

    public function __construct(
        private readonly PatientCardRepository $repository,
        private readonly ImageUploadValidator $validator,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{
     *     settings: array<string, mixed>,
     *     logo: array<string, mixed>|null,
     *     versions: int,
     *     limits: array{notice: int, flight: int, center_address: int, logo_bytes: int}
     * }
     */
    public function load(): array
    {
        $settings = $this->repository->settings() ?? [];
        $logoId = $settings['logo_id'] ?? null;
        $logo = $logoId === null ? null : $this->repository->logo((int) $logoId);
        if ($logo !== null) {
            unset($logo['content']);
        }

        return [
            'settings' => $settings,
            'logo' => $logo,
            'versions' => $this->repository->countSettingsVersions(),
            'limits' => [
                'notice' => PatientCardPdfGenerator::MAX_NOTICE_CHARS,
                'flight' => PatientCardPdfGenerator::MAX_FLIGHT_NOTICE_CHARS,
                'center_address' => self::MAX_CENTER_ADDRESS,
                'logo_bytes' => ImageUploadValidator::MAX_BYTES,
            ],
        ];
    }

    /**
     * Speichert die Stammdaten und legt eine neue Fassung an.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed>|null $logoFile
     * @return array{version_id: int, logo_id: int|null}
     */
    public function save(array $post, ?array $logoFile, bool $removeLogo): array
    {
        $errors = [];
        $text = static function (string $key, int $max) use ($post, &$errors): ?string {
            $value = $post[$key] ?? '';
            $value = is_string($value) ? trim(str_replace(["\r\n", "\r"], "\n", $value)) : '';
            if (mb_strlen($value) > $max) {
                $errors[$key] = sprintf('Höchstens %d Zeichen erlaubt (aktuell %d).', $max, mb_strlen($value));
            }
            return $value === '' ? null : $value;
        };

        $centerName = $text('center_name', self::MAX_CENTER_NAME);
        $centerAddress = $text('center_address', self::MAX_CENTER_ADDRESS);
        $notice = $text('notice_text', PatientCardPdfGenerator::MAX_NOTICE_CHARS);
        $flightDe = $text('flight_notice_de', PatientCardPdfGenerator::MAX_FLIGHT_NOTICE_CHARS);
        $flightEn = $text('flight_notice_en', PatientCardPdfGenerator::MAX_FLIGHT_NOTICE_CHARS);

        $settings = $this->repository->settings() ?? [];
        $logoId = $settings['logo_id'] ?? null;
        if ($removeLogo) {
            $logoId = null;
        }
        if ($logoFile !== null && ($logoFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $upload = $this->validator->validateUpload($logoFile);
                $existing = $this->repository->findLogoIdByHash(hash('sha256', $upload['bytes']));
                $logoId = $existing ?? $this->repository->insertLogo(
                    hash('sha256', $upload['bytes']),
                    $upload['mime_type'],
                    $upload['filename'],
                    $upload['width'],
                    $upload['height'],
                    $upload['bytes'],
                    $this->now(),
                );
            } catch (UploadException $e) {
                $errors['logo'] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            throw PatientCardException::validation($errors);
        }

        $versionId = $this->repository->saveSettings([
            'center_name' => $centerName,
            'center_address' => $centerAddress,
            'notice_text' => $notice,
            'flight_notice_de' => $flightDe,
            'flight_notice_en' => $flightEn,
            'logo_id' => $logoId,
        ], $this->now());

        return ['version_id' => $versionId, 'logo_id' => $logoId === null ? null : (int) $logoId];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function logo(int $logoId): ?array
    {
        return $this->repository->logo($logoId);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
