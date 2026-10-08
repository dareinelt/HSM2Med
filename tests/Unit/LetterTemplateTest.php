<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Letter\LetterException;
use App\Letter\LetterPdfGenerator;
use App\Letter\LetterTemplate;
use DateTimeImmutable;
use RuntimeException;
use Tests\Support\LetterFactory;
use Tests\Support\PdfText;
use Tests\TestCase;

/**
 * Briefvorlage (Vorlageneditor) und DIN-5008-Ausgabe: Pruefung der Vorlage, Platzhalter,
 * Reihenfolge der Bausteine, bearbeitbare Texte und Weiterleitung alter Brief-Fassungen.
 */
final class LetterTemplateTest extends TestCase
{
    private function generatedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-07 09:15:00', new \DateTimeZone('Europe/Berlin'));
    }

    /**
     * @param array<string, mixed> $content
     */
    private function generate(array $content): string
    {
        $snapshot = LetterFactory::snapshot(['letter_version' => 2, 'letter_template_version' => '3']);
        $snapshot['template'] = ['version_id' => 3, 'version_no' => 3, 'name' => $content['name'], 'content_sha256' => '', 'content' => $content];
        return (new LetterPdfGenerator())->generate($snapshot, null, $this->generatedAt());
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
        $default = LetterTemplate::default();
        $this->assertSame($default, LetterTemplate::normalize($default));
        $this->assertSame(LetterTemplate::encode($default), LetterTemplate::encode(LetterTemplate::normalize(json_decode(LetterTemplate::encode($default), true))));
        $this->assertSame(
            ['subject', 'salutation', 'patient', 'anamnesis', 'premedication', 'report', 'epicrisis', 'closing'],
            array_column($default['blocks'], 'type'),
        );

        $definition = LetterTemplate::editorDefinition();
        $this->assertSame($default, $definition['default']);
        $this->assertTrue(isset($definition['zones']['recipient'], $definition['blocks']['text'], $definition['placeholders']['patient_name']));
        $this->assertFalse($definition['blocks']['text']['unique']);
    }

    public function testNormalizeKeepsOrderAndCompletesMissingValues(): void
    {
        $template = LetterTemplate::default();
        $template['blocks'] = array_reverse($template['blocks']);
        unset($template['zones']['footer'], $template['blocks'][0]['texts']);
        $template['blocks'][] = ['id' => 'BAD ID', 'type' => 'text', 'texts' => ['text' => "Zeile 1\r\nZeile 2"]];
        $template['blocks'][] = ['id' => 'text', 'type' => 'text', 'enabled' => false];

        $normalized = LetterTemplate::normalize($template);
        $this->assertSame('closing', $normalized['blocks'][0]['type']);
        $this->assertSame('Mit freundlichen Grüßen', $normalized['blocks'][0]['texts']['text']);
        $this->assertSame('Seite {page} von {pages}', $normalized['zones']['footer']['texts']['page_label']);
        $this->assertSame('text', $normalized['blocks'][8]['id']);
        $this->assertSame("Zeile 1\nZeile 2", $normalized['blocks'][8]['texts']['text']);
        $this->assertSame('text-2', $normalized['blocks'][9]['id']);
        $this->assertFalse($normalized['blocks'][9]['enabled']);
    }

    public function testNormalizeRejectsInvalidTemplates(): void
    {
        $this->assertThrows(LetterException::class, fn () => LetterTemplate::normalize('kein Objekt'));

        $template = LetterTemplate::default();
        $template['name'] = '  ';
        $template['blocks'][] = $template['blocks'][0];
        $template['blocks'][] = ['type' => 'unbekannt'];
        $template['zones']['recipient']['options']['source'] = 'irgendwer';
        $template['zones']['footer']['texts']['disclaimer'] = 'Seite {page}';
        $template['zones']['footer']['texts']['page_label'] = '{page}/{pages} {patient_name}';
        $template['blocks'][1]['texts']['text'] = str_repeat('x', 301);

        $error = $this->assertThrows(LetterException::class, fn () => LetterTemplate::normalize($template));
        $errors = $error->fieldErrors();
        $this->assertTrue(isset($errors['name']));
        $this->assertTrue(isset($errors['blocks.8']), 'Doppelter fester Baustein.');
        $this->assertTrue(isset($errors['blocks.9']), 'Unbekannter Baustein.');
        $this->assertTrue(isset($errors['zones.recipient.options.source']));
        $this->assertTrue(isset($errors['zones.footer.texts.disclaimer']), 'Seitenplatzhalter nur in der Seitenangabe.');
        $this->assertFalse(isset($errors['zones.footer.texts.page_label']));
        $this->assertTrue(isset($errors['blocks.1.texts.text']));

        $tooMany = LetterTemplate::default();
        for ($i = 0; $i < LetterTemplate::MAX_BLOCKS; $i++) {
            $tooMany['blocks'][] = ['type' => 'text'];
        }
        $this->assertTrue(isset($this->assertThrows(LetterException::class, fn () => LetterTemplate::normalize($tooMany))->fieldErrors()['blocks']));
    }

    public function testFillReplacesKnownPlaceholdersOnly(): void
    {
        $this->assertSame('Hallo Erika {unbekannt}', LetterTemplate::fill('Hallo {first_name} {unbekannt}', ['first_name' => 'Erika']));
    }

    /** Die DIN-Ausgabe uebernimmt Reihenfolge, eigene Texte und ausgeblendete Bausteine der Vorlage. */
    public function testPdfFollowsTemplateOrderAndTexts(): void
    {
        $template = LetterTemplate::default();
        $epicrisis = $template['blocks'][$this->blockIndex($template, 'epicrisis')];
        $template['blocks'] = array_values(array_filter($template['blocks'], static fn (array $block): bool => $block['type'] !== 'epicrisis'));
        array_splice($template['blocks'], $this->blockIndex($template, 'anamnesis'), 0, [$epicrisis]);
        $template['blocks'][$this->blockIndex($template, 'salutation')]['texts']['text'] = 'Liebe Kolleginnen und Kollegen,';
        $template['blocks'][$this->blockIndex($template, 'premedication')]['enabled'] = false;
        $template['blocks'][] = ['id' => 'text', 'type' => 'text', 'enabled' => true, 'options' => [], 'texts' => ['heading' => 'Hinweis', 'text' => 'Rückfragen an {center_name}.']];
        $template['zones']['info_block']['texts']['label_reference'] = 'Ihr Zeichen';
        $template['zones']['recipient']['texts']['text'] = "Praxis Dr. Muster\nHauptstraße 1\n12345 Beispielstadt";
        $template['zones']['footer']['texts']['page_label'] = 'Blatt {page}/{pages}';
        $template = LetterTemplate::normalize($template);

        $pdf = $this->generate($template);
        $text = PdfText::text($pdf);
        foreach (['Liebe Kolleginnen und Kollegen,', 'Ihr Zeichen', 'Praxis Dr. Muster', 'Hauptstraße 1', 'Hinweis', 'Rückfragen an Nachsorgezentrum Beispielstadt.', 'Blatt 1/'] as $needle) {
            $this->assertContains($needle, $text);
        }
        $this->assertNotContains('Metoprolol', $text, 'Ausgeblendete Bausteine erscheinen nicht.');
        $this->assertNotContains('Unser Zeichen', $text);
        $this->assertTrue(strpos($text, 'Epikrise') < strpos($text, 'Anamnese'), 'Die Reihenfolge der Vorlage gilt.');
        $this->assertTrue(strpos($text, 'Mit freundlichen Grüßen') < strpos($text, 'Rückfragen an'));
        $this->assertSame($pdf, $this->generate($template), 'Die Ausgabe ist deterministisch.');
    }

    public function testFoldMarksAndLegacyDispatch(): void
    {
        $template = LetterTemplate::default();
        $withMarks = PdfText::pages($this->generate($template))[0];
        $template['zones']['footer']['options']['fold_marks'] = false;
        $this->assertNotSame($this->generate($template), $this->generate(LetterTemplate::default()));
        $this->assertContains('Vorlage Fassung 3', $withMarks);

        // Briefe der Fassung 1 werden weiterhin mit dem damaligen Aufbau erzeugt.
        $legacy = (new LetterPdfGenerator())->generate(LetterFactory::snapshot(), null, $this->generatedAt());
        $this->assertContains('Brief zur Schrittmacher-/ICD-Abfrage', PdfText::text($legacy));
        $this->assertNotContains('Vorlage Fassung', PdfText::text($legacy));

        $snapshot = LetterFactory::snapshot(['letter_version' => 2]);
        $snapshot['template'] = ['content' => ['name' => '']];
        $this->assertThrows(RuntimeException::class, fn () => (new LetterPdfGenerator())->generate($snapshot, null, $this->generatedAt()));
    }
}
