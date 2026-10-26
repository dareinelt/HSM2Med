<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application;
use App\Config\Config;
use App\Letter\LetterInput;
use App\Letter\LetterService;
use App\Patient\PatientInput;
use App\Patient\PatientRepository;
use App\Patient\PatientService;
use App\Repository\GroupRepository;
use App\Repository\SignatureRepository;
use App\Repository\UserRepository;
use App\User\User;
use App\User\UserInput;
use App\User\UserService;
use Tests\Support\Images;
use Tests\Support\PdfText;

/**
 * Unterschriften der Benutzer gegen die echte Datenbank.
 *
 * Schwerpunkte: gleiche Datei wird nur einmal gespeichert, die Zuordnung zur Person laesst sich
 * entfernen, der Name unter der Unterschrift wird aus Titel, Vorname und Name gebildet, und ein
 * erzeugter Brief friert Unterschrift und Namen ein (spaetere Aenderungen wirken nicht nach).
 * Die Pruefung der hochgeladenen Datei selbst deckt Tests\Unit\ImageUploadValidatorTest ab.
 */
final class SignatureTest extends DatabaseTestCase
{
    private UserRepository $users;
    private SignatureRepository $signatures;
    private UserService $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->pdo);
        $this->signatures = new SignatureRepository($this->pdo);
        $this->service = new UserService($this->pdo, $this->users, new GroupRepository($this->pdo), $this->clock);
    }

    private function createUser(
        string $username = 'e.mustermann',
        string $displayName = 'Erika Mustermann',
        string $title = 'Dr. med.',
        string $firstName = 'Erika',
        string $lastName = 'Mustermann',
    ): int {
        $groupIds = array_map(static fn (array $group): int => (int) $group['id'], $this->service->groups());
        return $this->service->create(UserInput::fromPost([
            'username' => $username,
            'display_name' => $displayName,
            'title' => $title,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'is_active' => '1',
            'groups' => $groupIds,
        ]), 'Geheim12345!');
    }

    /** Eine Unterschrift ablegen (die Bildpruefung selbst ist Gegenstand der Unit-Tests). */
    private function storeSignature(string $bytes = '', string $mimeType = 'image/png'): int
    {
        $bytes = $bytes === '' ? Images::png(120, 40) : $bytes;
        return $this->signatures->store(
            $bytes,
            hash('sha256', $bytes),
            $mimeType,
            'unterschrift.png',
            120,
            40,
            '2026-10-07 08:00:00',
        );
    }

    /** Dieselbe Datei wird nur einmal gespeichert und dann wiederverwendet. */
    public function testIdenticalFilesAreStoredOnce(): void
    {
        $first = $this->storeSignature();
        $second = $this->storeSignature();

        $this->assertSame($first, $second, 'Gleiche Pruefsumme muss dieselbe Unterschrift liefern.');
        $this->assertSame(1, $this->rowCount('user_signatures'));
        $stored = $this->signatures->find($first) ?? [];
        $this->assertSame('image/png', $stored['mime_type'] ?? null);
        $this->assertSame(120, $stored['width'] ?? null);
        $this->assertSame(40, $stored['height'] ?? null);
        $this->assertTrue(
            ($this->signatures->findWithContent($first)['content'] ?? '') !== '',
            'Die Bilddaten muessen gespeichert sein.',
        );
    }

    /** Die Zuordnung zur Person laesst sich setzen und wieder entfernen; das Bild bleibt erhalten. */
    public function testSignatureCanBeAssignedAndRemoved(): void
    {
        $userId = $this->createUser();
        $signatureId = $this->storeSignature();

        $this->users->setSignature($userId, $signatureId, '2026-10-07 08:00:00');
        $user = $this->users->find($userId);
        $this->assertTrue($user->hasSignature());
        $this->assertSame($signatureId, $user->signatureId);
        $this->assertSame($signatureId, $this->service->user($userId)->signatureId);

        $this->service->removeSignature($userId);
        $user = $this->users->find($userId);
        $this->assertFalse($user->hasSignature());
        $this->assertNull($user->signatureId);
        $this->assertSame(1, $this->rowCount('user_signatures'), 'Das Bild bleibt fuer alte Briefe erhalten.');
    }

    /** Unter der Unterschrift steht immer Titel, Vorname und Name. */
    public function testSignerNameJoinsTitleFirstNameAndLastName(): void
    {
        $userId = $this->createUser();
        $this->assertSame('Dr. med. Erika Mustermann', $this->users->find($userId)->signerName());

        $withoutTitle = $this->createUser('o.beispiel', 'Otto Beispiel', '', 'Otto', 'Beispiel');
        $this->assertSame('Otto Beispiel', $this->users->find($withoutTitle)->signerName());

        $onlyLastName = $this->createUser('n.name', 'Nur Name', '  ', '', 'Name');
        $this->assertSame('Name', $this->users->find($onlyLastName)->signerName());

        // Ohne Namensbestandteile bleibt der Anzeigename als Rueckfall.
        $fallback = $this->createUser('r.fall', 'Rueckfall Anzeige', '', '', '');
        $this->assertSame('Rueckfall Anzeige', $this->users->find($fallback)->signerName());
    }

    /** Der Brief friert Unterschrift und Namen ein; ein Wechsel wirkt nicht auf ihn zurueck. */
    public function testLetterFreezesSignatureAndSignerName(): void
    {
        $userId = $this->createUser();
        $signatureId = $this->storeSignature();
        $this->users->setSignature($userId, $signatureId, '2026-10-07 08:00:00');
        $author = $this->users->find($userId);

        $app = new Application(Config::fromEnvironment(), dirname(__DIR__, 2), $this->clock);
        $letters = $app->letterService();
        $letter = $this->createLetter($letters, $author);

        $snapshot = $letters->letter($letter['letter_id'], true)['snapshot'];
        $this->assertSame($signatureId, $snapshot['signature']['id']);
        $this->assertSame($this->signatures->find($signatureId)['sha256'], $snapshot['signature']['sha256']);
        $this->assertSame('Dr. med. Erika Mustermann', $snapshot['signature']['name']);

        $pdf = (string) $letters->pdfContent($letter['letter_id']);
        $this->assertContains('Dr. med. Erika Mustermann', PdfText::text($pdf));
        $this->assertContains('/Subtype /Image', $pdf, 'Die Unterschrift muss eingebettet sein.');

        // Spaetere Aenderungen an Person und Unterschrift veraendern den Brief nicht.
        $this->users->setSignature($userId, null, '2026-10-08 08:00:00');
        $this->users->update(
            $userId,
            'e.mustermann',
            'Erika Neuname',
            true,
            '2026-10-08 08:00:00',
            'Prof.',
            'Erika',
            'Neuname',
        );

        $reproduced = (string) $letters->reproducePdf($letter['letter_id'])['content'];
        $this->assertSame($pdf, $reproduced, 'Der Brief muss unveraendert reproduzierbar sein.');
        $this->assertFalse(str_contains(PdfText::text($reproduced), 'Neuname'));
    }

    /** Ohne hinterlegte Unterschrift bleibt der Platz frei, der Name steht trotzdem im Brief. */
    public function testLetterWithoutSignatureStillPrintsTheName(): void
    {
        $userId = $this->createUser('a.ohne', 'Anna Ohne', '', 'Anna', 'Ohne');
        $author = $this->users->find($userId);

        $app = new Application(Config::fromEnvironment(), dirname(__DIR__, 2), $this->clock);
        $letters = $app->letterService();
        $letter = $this->createLetter($letters, $author);

        $snapshot = $letters->letter($letter['letter_id'], true)['snapshot'];
        $this->assertNull($snapshot['signature']['id']);
        $this->assertSame('Anna Ohne', $snapshot['signature']['name']);

        $text = PdfText::text((string) $letters->pdfContent($letter['letter_id']));
        $this->assertContains('Anna Ohne', $text);
    }

    /** Ein Brief ohne unterzeichnende Person laesst den Platz unter der Grussformel leer. */
    public function testLetterWithoutAuthorFreezesAnEmptySignaturePart(): void
    {
        $app = new Application(Config::fromEnvironment(), dirname(__DIR__, 2), $this->clock);
        $letters = $app->letterService();
        $letter = $this->createLetter($letters, null);

        $snapshot = $letters->letter($letter['letter_id'], true)['snapshot'];
        $part = $snapshot['signature'];
        $this->assertNull($part['id']);
        $this->assertSame('', $part['sha256']);
        $this->assertSame('', $part['filename']);
        $this->assertSame('', $part['name']);
    }

    /** @return array{letter_id: int, page_count: int} */
    private function createLetter(LetterService $letters, ?User $author): array
    {
        $patientId = (int) (new PatientService($this->pdo, new PatientRepository($this->pdo), $this->clock))
            ->create(PatientInput::fromPost([
                'last_name' => 'Mustermann',
                'first_name' => 'Erika',
                'date_of_birth' => '21.10.1938',
                'patient_identifier' => 'P-100',
            ]))['patient_id'];
        (new PatientRepository($this->pdo))->saveMasterData($patientId, [
            'physician_name' => 'Dr. med. Anna Weber',
            'physician_practice' => 'Hausarztpraxis am Markt',
            'physician_street' => 'Marktplatz 3',
            'physician_postal_code' => '54321',
            'physician_city' => 'Hausarztstadt',
        ], '2026-01-01 00:00:00');

        return $letters->create(LetterInput::fromPost([
            'patient_id' => (string) $patientId,
            'report_id' => '',
            'confirm_data' => '1',
            'confirm_letter' => '1',
            'recipients' => ['family_doctor'],
            'include_appendix' => '1',
            'include_measurements' => '1',
        ]), $author);
    }
}
