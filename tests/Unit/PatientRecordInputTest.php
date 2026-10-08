<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Patient\PatientException;
use App\Patient\PatientRecordInput;
use App\Patient\PatientRecordType;
use Tests\TestCase;

/**
 * Validierung der Aktenbausteine: Freitext und Arzneimitteltabelle (Vormedikation).
 */
final class PatientRecordInputTest extends TestCase
{
    public function testFreitextIsTrimmedAndKept(): void
    {
        $input = PatientRecordInput::fromPost(PatientRecordType::Anamnesis, [
            'text' => "  Belastungsdyspnoe seit 3 Monaten.\nSynkope 06/2026.  ",
            'author_name' => '  Dr. Beispiel  ',
        ]);

        $this->assertSame("Belastungsdyspnoe seit 3 Monaten.\nSynkope 06/2026.", $input->text);
        $this->assertSame('Dr. Beispiel', $input->authorName());
        $this->assertSame(['text' => "Belastungsdyspnoe seit 3 Monaten.\nSynkope 06/2026."], $input->content());
        $this->assertSame("Belastungsdyspnoe seit 3 Monaten.\nSynkope 06/2026.", $input->contentText());
    }

    public function testEmptyFreitextIsRejected(): void
    {
        $e = $this->assertThrows(
            PatientException::class,
            fn () => PatientRecordInput::fromPost(PatientRecordType::Epicrisis, ['text' => "   \n  "]),
        );

        $this->assertTrue(isset($e->fieldErrors()['text']));
    }

    public function testStructuredTypeRequiresAtLeastOneEntry(): void
    {
        $e = $this->assertThrows(
            PatientException::class,
            fn () => PatientRecordInput::fromPost(PatientRecordType::Premedication, ['text' => '', 'medication' => []]),
        );

        $this->assertContains('Arzneimittel', (string) $e->fieldErrors()['text']);
    }

    public function testMedicationEntriesAreNormalized(): void
    {
        $input = PatientRecordInput::fromPost(PatientRecordType::Premedication, [
            'text' => 'Zusätzlich Vitamin D.',
            'medication' => [
                ['substance' => 'Bisoprolol', 'dose' => '2,5', 'unit' => 'mg', 'schedule' => '1-0-0', 'reason' => 'Bradykardie', 'from' => '01.03.2024', 'to' => ''],
                ['substance' => 'Ramipril', 'dose' => '5', 'unit' => 'mg', 'schedule' => '1-0-0', 'reason' => '', 'from' => '', 'to' => ''],
                ['substance' => '', 'dose' => '', 'unit' => '', 'schedule' => '', 'reason' => '', 'from' => '', 'to' => ''],
            ],
        ]);

        $this->assertCount(2, $input->entries, 'Vollständig leere Zeilen werden verworfen.');
        $this->assertSame('2024-03-01', $input->entries[0]['from'], 'Datum wird als ISO gespeichert.');
        $this->assertSame('', $input->entries[0]['to']);
        $this->assertSame('Zusätzlich Vitamin D.', $input->text);
    }

    public function testEntryWithoutSubstanceIsRejected(): void
    {
        $e = $this->assertThrows(
            PatientException::class,
            fn () => PatientRecordInput::fromPost(PatientRecordType::Premedication, [
                'medication' => [['substance' => '', 'dose' => '5', 'unit' => 'mg']],
            ]),
        );

        $this->assertContains('Wirkstoff', (string) $e->fieldErrors()['medication']);
    }

    public function testInvalidDateInEntryIsRejected(): void
    {
        $e = $this->assertThrows(
            PatientException::class,
            fn () => PatientRecordInput::fromPost(PatientRecordType::Premedication, [
                'medication' => [['substance' => 'Bisoprolol', 'from' => '31.02.2024']],
            ]),
        );

        $this->assertContains('kein gültiges Datum', (string) $e->fieldErrors()['medication']);
    }

    public function testEndBeforeStartIsRejected(): void
    {
        $e = $this->assertThrows(
            PatientException::class,
            fn () => PatientRecordInput::fromPost(PatientRecordType::Premedication, [
                'medication' => [['substance' => 'Bisoprolol', 'from' => '01.06.2024', 'to' => '01.03.2024']],
            ]),
        );

        $this->assertContains('darf nicht vor', (string) $e->fieldErrors()['medication']);
    }

    public function testTooManyEntriesAreRejected(): void
    {
        $rows = [];
        for ($i = 0; $i <= PatientRecordInput::MAX_ENTRIES; $i++) {
            $rows[] = ['substance' => 'Wirkstoff ' . $i];
        }
        $e = $this->assertThrows(
            PatientException::class,
            fn () => PatientRecordInput::fromPost(PatientRecordType::Premedication, ['medication' => $rows]),
        );

        $this->assertTrue(isset($e->fieldErrors()['medication']));
    }

    public function testTooLongTextIsRejected(): void
    {
        $e = $this->assertThrows(
            PatientException::class,
            fn () => PatientRecordInput::fromPost(PatientRecordType::Note, ['text' => str_repeat('a', PatientRecordInput::MAX_TEXT + 1)]),
        );

        $this->assertTrue(isset($e->fieldErrors()['text']));
    }

    public function testEntryLineIsRendered(): void
    {
        $line = PatientRecordInput::entryLine([
            'substance' => 'Bisoprolol',
            'dose' => '2,5',
            'unit' => 'mg',
            'schedule' => '1-0-0',
            'reason' => 'Bradykardie',
            'from' => '2024-03-01',
            'to' => '2026-06-30',
        ]);

        $this->assertContains('Bisoprolol', $line);
        $this->assertContains('2,5 mg', $line);
        $this->assertContains('1-0-0', $line);
        $this->assertContains('von 01.03.2024 bis 30.06.2026', $line);
        $this->assertContains('Grund: Bradykardie', $line);
    }

    public function testOpenEndedEntryLineUsesFortlaufend(): void
    {
        $line = PatientRecordInput::entryLine(['substance' => 'Ramipril', 'from' => '2024-03-01']);

        $this->assertContains('bis fortlaufend', $line);
    }

    public function testRenderTextRebuildsStoredContent(): void
    {
        $text = PatientRecordInput::renderText([
            'entries' => [['substance' => 'Bisoprolol', 'dose' => '2,5', 'unit' => 'mg']],
            'text' => 'Ergänzung',
        ]);

        $this->assertSame("Bisoprolol · 2,5 mg\nErgänzung", $text);
        $this->assertSame('', PatientRecordInput::renderText([]));
    }

    public function testRecordTypeCatalog(): void
    {
        $this->assertCount(6, PatientRecordType::all());
        $this->assertSame('Vormedikation', PatientRecordType::Premedication->label());
        $this->assertTrue(PatientRecordType::Premedication->isStructured());
        $this->assertFalse(PatientRecordType::Anamnesis->isStructured());
        $this->assertSame(PatientRecordType::Note, PatientRecordType::fromValue('note'));
        $this->assertSame(PatientRecordType::DeviceCheck, PatientRecordType::fromValue('device_check'));
        $this->assertTrue(PatientRecordType::DeviceCheck->isDeviceCheck());
        $this->assertFalse(PatientRecordType::DeviceCheck->isStructured());
        $this->assertSame(PatientRecordType::Befund, PatientRecordType::fromValue('befund'));
        $this->assertSame('Befund', PatientRecordType::Befund->label());
        $this->assertFalse(PatientRecordType::Befund->isStructured());
        $this->assertFalse(PatientRecordType::Befund->isDeviceCheck());
        $this->assertNull(PatientRecordType::fromValue('unbekannt'));
        $this->assertNull(PatientRecordType::fromValue(null));
    }
}
