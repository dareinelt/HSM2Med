<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\PatientCard\PatientCardException;
use App\PatientCard\PatientCardPdfGenerator;
use App\PatientCard\PatientCardTemplate;
use DateTimeImmutable;
use RuntimeException;
use Tests\Support\PatientCardFactory;
use Tests\Support\PdfText;
use Tests\TestCase;

/**
 * Ausweisvorlage (Vorlageneditor) und Ausgabe des Patientenausweises: Pruefung der Vorlage,
 * Platzhalter, Reihenfolge der Bausteine, bearbeitbare Texte und Weiterleitung alter Ausweise.
 */
final class PatientCardTemplateTest extends TestCase
{
    private function generatedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-07 09:15:00', new \DateTimeZone('Europe/Berlin'));
    }

    /**
     * Erzeugt den Ausweis mit der uebergebenen Vorlage als eingefrorene Fassung.
     *
     * @param array<string, mixed> $content
     * @param array<string, mixed> $overrides
     */
    private function generate(array $content, array $overrides = []): string
    {
        $snapshot = PatientCardFactory::snapshot([
            'template' => ['id' => 1, 'version_no' => 1, 'name' => (string) $content['name'], 'content' => $content],
        ] + $overrides);

        return (new PatientCardPdfGenerator())->generate($snapshot, null, $this->generatedAt());
    }

    /**
     * @param array<string, mixed> $template
     */
    private function blockIndex(array $template, string $type): int
    {
        foreach ($template['blocks'] as $index => $block) {
            if ($block['type'] === $type) {
                return $index;
            }
        }
        throw new RuntimeException('Baustein fehlt: ' . $type);
    }

    public function testDefaultTemplateIsValidAndStable(): void
    {
        $default = PatientCardTemplate::default();
        $this->assertSame($default, PatientCardTemplate::normalize($default));
        $this->assertSame(
            PatientCardTemplate::encode($default),
            PatientCardTemplate::encode(PatientCardTemplate::normalize(json_decode(PatientCardTemplate::encode($default), true))),
        );
        $this->assertSame(
            ['patient_data', 'emergency_contact', 'physician', 'center', 'implants', 'mrt', 'notice', 'summary', 'measurements'],
            array_column($default['blocks'], 'type'),
        );
        $this->assertSame(['header', 'footer', 'general'], array_keys($default['zones']));
        $this->assertSame('Standardvorlage', $default['name']);
        $this->assertSame(PatientCardTemplate::SCHEMA, $default['schema']);

        $definition = PatientCardTemplate::editorDefinition();
        $this->assertSame($default, $definition['default']);
        $this->assertSame('patient_card', $definition['kind']);
        $this->assertSame('Patientenausweis', $definition['label']);
        $this->assertSame('', $definition['type']);
        $this->assertSame([], $definition['types']);
        $this->assertSame(PatientCardTemplate::AREAS, $definition['areas']);
        $this->assertSame(PatientCardTemplate::MAX_BLOCKS, $definition['maxBlocks']);
        $this->assertTrue(isset($definition['zones']['header'], $definition['blocks']['text'], $definition['placeholders']['patient_name']));
        $this->assertFalse($definition['blocks']['text']['unique']);
        $this->assertTrue($definition['blocks']['patient_data']['unique']);
    }

    public function testNormalizeKeepsOrderAndCompletesMissingValues(): void
    {
        $template = PatientCardTemplate::default();
        $template['blocks'] = array_reverse($template['blocks']);
        unset($template['zones']['footer'], $template['blocks'][0]['texts']);
        $template['blocks'][] = ['id' => 'BAD ID', 'type' => 'text', 'texts' => ['text' => "Zeile 1\r\nZeile 2"]];
        $template['blocks'][] = ['id' => 'text', 'type' => 'text', 'enabled' => false];

        $normalized = PatientCardTemplate::normalize($template);
        $this->assertSame('measurements', $normalized['blocks'][0]['type']);
        $this->assertSame('summary', $normalized['blocks'][1]['type']);
        $this->assertSame('Seite {page} von {pages}', $normalized['zones']['footer']['texts']['page_label']);
        $this->assertSame('text', $normalized['blocks'][9]['id']);
        $this->assertSame("Zeile 1\nZeile 2", $normalized['blocks'][9]['texts']['text']);
        $this->assertSame('text-2', $normalized['blocks'][10]['id']);
        $this->assertFalse($normalized['blocks'][10]['enabled']);
    }

    public function testNormalizeRejectsInvalidTemplates(): void
    {
        $this->assertThrows(PatientCardException::class, fn () => PatientCardTemplate::normalize('kein Objekt'));

        $template = PatientCardTemplate::default();
        $template['name'] = '  ';
        $template['schema'] = 2;
        $template['blocks'][] = $template['blocks'][0];
        $template['blocks'][] = ['type' => 'unbekannt'];
        $template['zones']['footer']['texts']['disclaimer'] = 'Seite {page}';
        $template['zones']['footer']['texts']['page_label'] = 'Seite {page}/{pages} {unbekannt}';
        $template['blocks'][0]['texts']['title'] = str_repeat('x', 301);
        $template['blocks'][1]['texts']['title'] = 'Hallo {unbekannt}';

        $errors = $this->assertThrows(PatientCardException::class, fn () => PatientCardTemplate::normalize($template))->fieldErrors();
        $this->assertTrue(isset($errors['name']));
        $this->assertTrue(isset($errors['schema']));
        $this->assertTrue(isset($errors['blocks.9']), 'Doppelter fester Baustein.');
        $this->assertTrue(isset($errors['blocks.10']), 'Unbekannter Baustein.');
        $this->assertTrue(isset($errors['zones.footer.texts.disclaimer']), 'Seitenplatzhalter nur in der Seitenangabe.');
        $this->assertTrue(isset($errors['zones.footer.texts.page_label']), 'Unbekannter Platzhalter.');
        $this->assertTrue(isset($errors['blocks.0.texts.title']));
        $this->assertTrue(isset($errors['blocks.1.texts.title']));

        $tooMany = PatientCardTemplate::default();
        for ($i = 0; $i < PatientCardTemplate::MAX_BLOCKS; $i++) {
            $tooMany['blocks'][] = ['type' => 'text'];
        }
        $this->assertTrue(isset($this->assertThrows(PatientCardException::class, fn () => PatientCardTemplate::normalize($tooMany))->fieldErrors()['blocks']));
    }

    public function testAccessorsReturnCanonicalValues(): void
    {
        $template = PatientCardTemplate::default();
        $patient = PatientCardTemplate::block($template, 'patient_data');
        $this->assertSame('Patientendaten:', PatientCardTemplate::blockText($patient, 'title'));
        $this->assertSame('Ersatz', PatientCardTemplate::blockText($patient, 'fehlt', 'Ersatz'));
        $this->assertTrue(PatientCardTemplate::blockOption($patient, 'show_indication'));
        $this->assertSame('nicht angegeben', PatientCardTemplate::zoneText($template, 'general', 'empty'));
        $this->assertTrue(PatientCardTemplate::zoneOption($template, 'header', 'show_logo'));
        $this->assertNull(PatientCardTemplate::block($template, 'unbekannt'));
        $this->assertSame('Ersatz', PatientCardTemplate::blockText(null, 'title', 'Ersatz'), 'Ohne Baustein gilt der Standardwert.');
        $this->assertFalse(PatientCardTemplate::blockOption(null, 'show_indication'), 'Ohne Baustein ist der Schalter aus.');

        $disabled = $template;
        $disabled['blocks'][$this->blockIndex($disabled, 'notice')]['enabled'] = false;
        $this->assertNull(PatientCardTemplate::block($disabled, 'notice'));
        $this->assertSame(
            ['patient_data', 'emergency_contact', 'physician', 'center', 'implants', 'mrt', 'summary', 'measurements'],
            array_column(PatientCardTemplate::enabledBlocks($disabled), 'type'),
        );
    }

    public function testAreasAreFixed(): void
    {
        $this->assertSame('left', PatientCardTemplate::area('patient_data'));
        $this->assertSame('left', PatientCardTemplate::area('center'));
        $this->assertSame('right', PatientCardTemplate::area('implants'));
        $this->assertSame('bottom', PatientCardTemplate::area('summary'));
        $this->assertSame('page2', PatientCardTemplate::area('measurements'));
        $this->assertSame('page2', PatientCardTemplate::area('text'));
        $this->assertSame('page2', PatientCardTemplate::area('unbekannt'));
    }

    public function testFillReplacesKnownPlaceholdersOnly(): void
    {
        $this->assertSame('Hallo Erika {unbekannt}', PatientCardTemplate::fill('Hallo {first_name} {unbekannt}', ['first_name' => 'Erika']));
        $this->assertSame('Fassung 2 von 2', PatientCardTemplate::fill('Fassung {card_version} von {page}', ['card_version' => 2, 'page' => 2]));
    }

    /** Die Platzhalter stammen ausschliesslich aus dem Ausweis-Snapshot. */
    public function testValuesComeFromSnapshot(): void
    {
        $values = PatientCardTemplate::values(PatientCardFactory::snapshot(), $this->generatedAt());
        $this->assertSame(
            [
                'center_name', 'center_address', 'patient_name', 'first_name', 'last_name', 'date_of_birth',
                'patient_identifier', 'device_model', 'serial_number', 'report_date', 'next_control',
                'sequence_no', 'card_version', 'created_at',
            ],
            array_keys($values),
        );
        $this->assertSame('Nachsorgezentrum Beispielstadt', $values['center_name']);
        $this->assertSame('LASTNAME, FIRSTNAME', $values['patient_name']);
        $this->assertSame('21.10.1938', $values['date_of_birth']);
        $this->assertSame('Endurity Core 2152', $values['device_model']);
        $this->assertSame('5809481', $values['serial_number']);
        $this->assertSame('07.10.2026', $values['report_date']);
        $this->assertSame('07.04.2027', $values['next_control']);
        $this->assertSame('1', $values['sequence_no']);
        $this->assertSame('2', $values['card_version']);
        $this->assertSame('07.10.2026 09:15:00', $values['created_at']);
    }

    /** Die Ausgabe uebernimmt eigene Texte, ausgeblendete Bausteine und die Reihenfolge der Vorlage. */
    public function testPdfFollowsTemplateTextsAndOrder(): void
    {
        $template = PatientCardTemplate::default();
        $template['zones']['header']['texts']['title'] = 'Ausweis für {patient_name}';
        $template['zones']['footer']['texts']['page_label'] = 'Blatt {page}/{pages}';
        $template['blocks'][$this->blockIndex($template, 'notice')]['enabled'] = false;
        $template['blocks'][$this->blockIndex($template, 'measurements')]['texts']['notes_heading'] = 'Erläuterung der Messwerte';
        $template['blocks'][] = [
            'id' => 'text',
            'type' => 'text',
            'enabled' => true,
            'options' => [],
            'texts' => ['heading' => 'Rückfragen', 'text' => 'Bitte an {center_name} wenden.'],
        ];
        $template = PatientCardTemplate::normalize($template);

        $pdf = $this->generate($template);
        $text = PdfText::text($pdf);
        foreach (['Ausweis für LASTNAME, FIRSTNAME', 'Blatt 1/2', 'Blatt 2/2', 'Erläuterung der Messwerte', 'Rückfragen', 'Bitte an Nachsorgezentrum Beispielstadt wenden.'] as $needle) {
            $this->assertContains($needle, $text);
        }
        $this->assertNotContains('Schrittmacher - Patientenausweis', $text, 'Die eigene Überschrift ersetzt den Standard.');
        $this->assertNotContains('Dieser Ausweis enthält Angaben zum implantierten Schrittmachersystem', $text, 'Ausgeblendete Bausteine erscheinen nicht.');
        $this->assertNotContains('{patient_name}', $text, 'Die Platzhalter sind ersetzt.');
        $this->assertNotContains('{center_name}', $text, 'Die Platzhalter sind ersetzt.');
        $this->assertSame($pdf, $this->generate($template), 'Die Ausgabe ist deterministisch.');
    }

    /** Ohne eingefrorene Vorlage (Ausweise vor Migration 014) gilt die Standardvorlage. */
    public function testPdfFallsBackToDefaultTemplate(): void
    {
        $legacy = (new PatientCardPdfGenerator())->generate(PatientCardFactory::snapshot(), null, $this->generatedAt());
        $text = PdfText::text($legacy);
        $this->assertContains('Schrittmacher - Patientenausweis', $text);
        $this->assertContains('Seite 1 von 2', $text);
        $this->assertSame($legacy, $this->generate(PatientCardTemplate::default()));
    }

    /** Eine ungueltige eingefrorene Vorlage wird nicht stillschweigend ersetzt. */
    public function testPdfRejectsInvalidFrozenTemplate(): void
    {
        $snapshot = PatientCardFactory::snapshot(['template' => ['content' => ['name' => '']]]);
        $this->assertThrows(
            RuntimeException::class,
            fn (): string => (new PatientCardPdfGenerator())->generate($snapshot, null, $this->generatedAt()),
        );
    }
}
