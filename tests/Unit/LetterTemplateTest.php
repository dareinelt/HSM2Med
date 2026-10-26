<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Letter\LetterException;
use App\Letter\LetterMeasurements;
use App\Letter\LetterPdfGenerator;
use App\Letter\LetterRecipient;
use App\Letter\LetterSalutation;
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
     * @param array<string, mixed> $overrides
     */
    private function generate(array $content, array $overrides = []): string
    {
        $snapshot = LetterFactory::snapshot(['letter_version' => 2, 'letter_template_version' => '3'] + $overrides);
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
            ['subject', 'salutation', 'patient', 'aggregat', 'anamnesis', 'premedication', 'befund', 'epicrisis', 'closing', 'reports'],
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
        $this->assertSame('reports', $normalized['blocks'][0]['type']);
        $this->assertSame('closing', $normalized['blocks'][1]['type']);
        $this->assertSame('Mit freundlichen Grüßen', $normalized['blocks'][1]['texts']['text']);
        $this->assertSame('Seite {page} von {pages}', $normalized['zones']['footer']['texts']['page_label']);
        $this->assertSame('text', $normalized['blocks'][10]['id']);
        $this->assertSame("Zeile 1\nZeile 2", $normalized['blocks'][10]['texts']['text']);
        $this->assertSame('text-2', $normalized['blocks'][11]['id']);
        $this->assertFalse($normalized['blocks'][11]['enabled']);
    }

    /** Die Messwerttabelle ist eine eigene Zone: Anzahl frueherer Untersuchungen und Texte. */
    public function testMeasurementTableZoneDefaultsAndChoices(): void
    {
        $default = LetterTemplate::default();
        $this->assertTrue(isset($default['zones']['measurement_table']));
        $zone = $default['zones']['measurement_table'];
        $this->assertTrue($zone['options']['show']);
        $this->assertSame((string) LetterMeasurements::DEFAULT_PREVIOUS, $zone['options']['previous']);
        $this->assertSame('Messwerte im Verlauf', $zone['texts']['heading']);
        $this->assertSame('Messwert', $zone['texts']['column_parameter']);
        $this->assertSame('aktuelle Untersuchung', $zone['texts']['current_label']);

        // Die Auswahl reicht von "keine fruehere Untersuchung" bis zur Obergrenze.
        $choices = LetterTemplate::editorDefinition()['zones']['measurement_table']['options']['previous']['choices'];
        $this->assertSame(range(0, LetterMeasurements::MAX_PREVIOUS), array_keys($choices));
        $this->assertSame('keine frühere Untersuchung', $choices['0']);
        $this->assertSame('1 frühere Untersuchung', $choices['1']);
        $this->assertSame('4 frühere Untersuchungen', $choices['4']);
    }

    /** Die eingestellte Anzahl frueherer Untersuchungen wird geprueft und begrenzt. */
    public function testMeasurementTablePreviousIsValidated(): void
    {
        $template = LetterTemplate::default();
        $template['zones']['measurement_table']['options']['previous'] = '7';
        $normalized = LetterTemplate::normalize($template);
        $this->assertSame('7', $normalized['zones']['measurement_table']['options']['previous']);
        $this->assertSame(7, LetterMeasurements::previousCount($normalized));

        $template['zones']['measurement_table']['options']['previous'] = 0;
        $normalized = LetterTemplate::normalize($template);
        $this->assertSame('0', $normalized['zones']['measurement_table']['options']['previous']);
        $this->assertSame(0, LetterMeasurements::previousCount($normalized));

        $template['zones']['measurement_table']['options']['previous'] = '11';
        $error = $this->assertThrows(LetterException::class, fn () => LetterTemplate::normalize($template));
        $this->assertTrue(isset($error->fieldErrors()['zones.measurement_table.options.previous']));
    }

    /** Der fruehere Baustein "report" wird als Aktenbaustein "befund" weitergefuehrt. */
    public function testNormalizeConvertsLegacyReportBlock(): void
    {
        $template = LetterTemplate::default();
        $template['blocks'][6] = [
            'id' => 'report',
            'type' => 'report',
            'enabled' => true,
            'options' => ['show_meta' => true],
            'texts' => ['heading' => 'Befund: Schrittmacher-/ICD-Abfrage'],
        ];

        $normalized = LetterTemplate::normalize($template);
        $this->assertSame('befund', $normalized['blocks'][6]['type']);
        $this->assertSame('report', $normalized['blocks'][6]['id']);
        $this->assertSame('Befund: Schrittmacher-/ICD-Abfrage', $normalized['blocks'][6]['texts']['heading']);
        $this->assertSame(['font_size', 'show_meta'], array_keys($normalized['blocks'][6]['options']));
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
        $this->assertTrue(isset($errors['blocks.10']), 'Doppelter fester Baustein.');
        $this->assertTrue(isset($errors['blocks.11']), 'Unbekannter Baustein.');
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

    /** Die Vorlagenarten werden getrennt gepflegt: eigene Fassungen, gleicher Aufbau. */
    public function testTemplateTypesHaveOwnDefaults(): void
    {
        $this->assertSame(
            ['patient' => 'Patient', 'family_doctor' => 'Hausarzt', 'referring_physician' => 'Überweisender Arzt', 'generic' => 'Arztbrief generisch'],
            LetterTemplate::types(),
        );
        $this->assertSame('Standardvorlage', LetterTemplate::defaultName(LetterRecipient::PATIENT));
        $this->assertSame('Standardvorlage Hausarzt', LetterTemplate::defaultName(LetterRecipient::FAMILY_DOCTOR));
        $this->assertSame('Standardvorlage Überweisender Arzt', LetterTemplate::defaultName(LetterRecipient::REFERRING_PHYSICIAN));
        $this->assertSame('Standardvorlage Arztbrief generisch', LetterTemplate::defaultName(LetterRecipient::GENERIC));
        $this->assertSame(LetterTemplate::default(), LetterTemplate::default(LetterRecipient::PATIENT));

        $definition = LetterTemplate::editorDefinition(LetterRecipient::REFERRING_PHYSICIAN);
        $this->assertSame('referring_physician', $definition['type']);
        $this->assertSame(LetterTemplate::types(), $definition['types']);
        $this->assertSame('Standardvorlage Überweisender Arzt', $definition['default']['name']);

        $physician = LetterTemplate::default(LetterRecipient::FAMILY_DOCTOR);
        $this->assertSame($physician, LetterTemplate::normalize($physician), 'Auch die Aerztevorlage ist gueltig.');
        $this->assertSame(array_column(LetterTemplate::default()['blocks'], 'type'), array_column($physician['blocks'], 'type'));
        $this->assertTrue(LetterTemplate::isType(LetterRecipient::FAMILY_DOCTOR));
        $this->assertFalse(LetterTemplate::isType('praxis'));
    }

    /** Die Anrede stammt aus dem Empfaenger-Snapshot, nicht aus der Vorlage. */
    public function testSalutationComesFromRecipientSnapshot(): void
    {
        $template = LetterTemplate::default();
        $this->assertSame('{salutation}', $template['blocks'][$this->blockIndex($template, 'salutation')]['texts']['text']);

        $physician = $this->generate($template, [
            'recipient' => [
                'type' => LetterRecipient::REFERRING_PHYSICIAN,
                'salutation' => 'Sehr geehrte Frau Kollegin,',
                'salutation_value' => LetterSalutation::KOLLEGIN,
            ],
        ]);
        $text = PdfText::text($physician);
        $this->assertContains('Sehr geehrte Frau Kollegin,', $text);
        $this->assertNotContains('{salutation}', $text, 'Der Platzhalter wird ersetzt.');

        // Ohne eingefrorenen Text wird die Anrede aus dem Wert der Stammdaten berechnet.
        $patient = $this->generate($template, [
            'recipient' => ['type' => LetterRecipient::PATIENT, 'salutation' => '', 'salutation_value' => LetterSalutation::FRAU],
        ]);
        $this->assertContains('Sehr geehrte Frau LASTNAME,', PdfText::text($patient));

        // Ohne Empfaengerangabe (Altbestand) erscheint die unpersoenliche Anrede.
        $this->assertContains('Sehr geehrte Damen und Herren,', PdfText::text($this->generate($template)));
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

    /** Jede Zeile des Informationsblocks laesst sich per Haekchen ein- und ausblenden. */
    public function testInfoBlockRowsFollowOptions(): void
    {
        $default = LetterTemplate::default();
        $text = PdfText::text($this->generate($default));
        foreach (['Unser Zeichen', 'Brief-Nr.', 'Stammdatenfassung'] as $needle) {
            $this->assertContains($needle, $text);
        }

        // Nur die Dokumentnummer abwaehlen: alle uebrigen Zeilen bleiben stehen.
        $template = LetterTemplate::default();
        $template['zones']['info_block']['options']['show_reference'] = false;
        $template = LetterTemplate::normalize($template);
        $this->assertFalse($template['zones']['info_block']['options']['show_reference']);
        $text = PdfText::text($this->generate($template));
        $this->assertNotContains('Unser Zeichen', $text);
        $this->assertContains('Brief-Nr.', $text);
        $this->assertContains('Stammdatenfassung', $text);

        // Alle Zeilen bis auf das Datum abwaehlen; der Baustein Patientendaten wird ausgeblendet,
        // damit die Beschriftungen eindeutig aus dem Informationsblock stammen.
        $template = LetterTemplate::default();
        foreach (array_keys(LetterTemplate::zoneDefinitions()['info_block']['options']) as $option) {
            $template['zones']['info_block']['options'][$option] = $option === 'show_date';
        }
        $template['blocks'][$this->blockIndex($template, 'patient')]['enabled'] = false;
        $text = PdfText::text($this->generate(LetterTemplate::normalize($template)));
        foreach (['Unser Zeichen', 'Brief-Nr.', 'Stammdatenfassung', 'Geburtsdatum', 'Patienten-ID'] as $needle) {
            $this->assertNotContains($needle, $text);
        }
        $this->assertContains('Datum', $text);
    }

    /** Jeder Baustein bietet eine Schriftgroesse; die Vorgabe bleibt unveraendert. */
    public function testEveryBlockOffersAFontSize(): void
    {
        $blocks = LetterTemplate::editorDefinition()['blocks'];
        foreach ($blocks as $type => $definition) {
            $this->assertSame('font_size', array_key_first($definition['options']), 'Baustein ' . $type);
            $fontSize = $definition['options']['font_size'];
            $this->assertSame('select', $fontSize['type']);
            $this->assertSame('normal', $fontSize['default']);
            $this->assertSame(['small', 'normal', 'large', 'xlarge'], array_keys($fontSize['choices']));
        }

        $default = LetterTemplate::default();
        foreach ($default['blocks'] as $block) {
            $this->assertSame('normal', $block['options']['font_size']);
        }

        // Die Vorgabe der Patienten- und Aggregatzeilen zeigt den Wert fett.
        $patient = $default['blocks'][$this->blockIndex($default, 'patient')]['options'];
        foreach (['bold_name', 'bold_birth', 'bold_identifier', 'bold_address'] as $option) {
            $this->assertSame('value', $patient[$option]);
        }
        $aggregat = $default['blocks'][$this->blockIndex($default, 'aggregat')]['options'];
        foreach (['bold_manufacturer', 'bold_implant_date', 'bold_model', 'bold_implant_location', 'bold_serial', 'bold_mode', 'bold_status', 'bold_leads'] as $option) {
            $this->assertSame('value', $aggregat[$option]);
        }
    }

    /** Schriftgroesse und Fettdruck werden wie jede Auswahl geprueft. */
    public function testFontSizeAndBoldChoicesAreValidated(): void
    {
        $template = LetterTemplate::default();
        $anamnesis = $this->blockIndex($template, 'anamnesis');
        $patient = $this->blockIndex($template, 'patient');
        $template['blocks'][$anamnesis]['options']['font_size'] = 'riesig';
        $template['blocks'][$patient]['options']['bold_name'] = 'beides';
        $error = $this->assertThrows(LetterException::class, fn () => LetterTemplate::normalize($template));
        $errors = $error->fieldErrors();
        $this->assertTrue(isset($errors['blocks.' . $anamnesis . '.options.font_size']));
        $this->assertTrue(isset($errors['blocks.' . $patient . '.options.bold_name']));

        // Fehlende Angaben (aeltere Fassungen) werden mit der Vorgabe ergaenzt.
        $template = LetterTemplate::default();
        unset($template['blocks'][$anamnesis]['options']['font_size']);
        unset($template['blocks'][$patient]['options']['bold_birth']);
        $normalized = LetterTemplate::normalize($template);
        $this->assertSame('normal', $normalized['blocks'][$anamnesis]['options']['font_size']);
        $this->assertSame('value', $normalized['blocks'][$patient]['options']['bold_birth']);
    }

    /** Die Schriftgroesse skaliert den ganzen Baustein, die Ueberschrift bleibt fett. */
    public function testFontSizeScalesBlockContent(): void
    {
        $template = LetterTemplate::default();
        $template['blocks'][$this->blockIndex($template, 'anamnesis')]['options']['font_size'] = 'xlarge';
        $template = LetterTemplate::normalize($template);

        $normal = $this->runsByText($this->generate(LetterTemplate::default()));
        $scaled = $this->runsByText($this->generate($template));

        $this->assertSame([10.5], array_column($normal['Anamnese'], 'size'));
        $this->assertSame(['bold'], array_column($normal['Anamnese'], 'font'), 'Ueberschriften bleiben fett.');
        $this->assertSame([13.65], array_column($scaled['Anamnese'], 'size'));
        $this->assertSame(['bold'], array_column($scaled['Anamnese'], 'font'));
        // Andere Bausteine behalten ihre Groesse.
        $this->assertSame(array_column($normal['Befund'], 'size'), array_column($scaled['Befund'], 'size'));
    }

    /** Je Zeile der Patientendaten waehlbar, ob Beschriftung oder Wert fett ist. */
    public function testPatientBlockBoldChoices(): void
    {
        $template = LetterTemplate::default();
        $patient = $this->blockIndex($template, 'patient');
        $template['blocks'][$patient]['options']['bold_name'] = 'label';
        $template['blocks'][$patient]['options']['bold_birth'] = 'both';
        $template['blocks'][$patient]['options']['bold_identifier'] = 'none';
        $runs = $this->runsByText($this->generate(LetterTemplate::normalize($template)));

        $this->assertSame('bold', $this->fontAt($runs, 'Name'), 'Beschriftung fett.');
        $this->assertSame('regular', $this->fontAt($runs, 'LASTNAME, FIRSTNAME'), 'Wert normal.');
        $this->assertSame('bold', $this->fontAt($runs, 'Geburtsdatum'));
        $this->assertSame('bold', $this->fontAt($runs, '21.10.1938'), 'Beschriftung und Wert fett.');
        $this->assertSame('regular', $this->fontAt($runs, 'Patienten-ID'));
        $this->assertSame('regular', $this->fontAt($runs, '10358141'), 'Nichts fett.');
        // Ohne eigene Angabe bleibt der Wert fett.
        $this->assertSame('regular', $this->fontAt($runs, 'Anschrift'));
        $this->assertSame('bold', $this->fontAt($runs, 'Musterstraße 12'));
    }

    /** Auch im Aggregat ist je Zeile waehlbar, was fett erscheint. */
    public function testAggregatBoldChoices(): void
    {
        $template = LetterTemplate::default();
        $aggregat = $this->blockIndex($template, 'aggregat');
        $template['blocks'][$aggregat]['options']['bold_manufacturer'] = 'label';
        $template['blocks'][$aggregat]['options']['bold_model'] = 'none';
        $runs = $this->runsByText($this->generate(LetterTemplate::normalize($template)));

        $this->assertSame('bold', $this->fontAt($runs, 'Hersteller'));
        $this->assertSame('regular', $this->fontAt($runs, 'Beispielhersteller'));
        $this->assertSame('regular', $this->fontAt($runs, 'Modell'));
        $this->assertSame('regular', $this->fontAt($runs, 'PM-2000 407155'));
        // Unveraenderte Zeilen zeigen weiterhin den Wert fett.
        $this->assertSame('regular', $this->fontAt($runs, 'Seriennummer'));
        $this->assertSame('bold', $this->fontAt($runs, '1000997498'));
    }

    /**
     * @return array<string, list<array{font: string, size: float, text: string}>>
     */
    private function runsByText(string $pdf): array
    {
        $byText = [];
        foreach (PdfText::runs($pdf) as $run) {
            $byText[$run['text']][] = $run;
        }
        return $byText;
    }

    /**
     * Schrift eines Textes in der Groesse der Bausteinzeilen (9.5 pt).
     *
     * @param array<string, list<array{font: string, size: float, text: string}>> $runs
     */
    private function fontAt(array $runs, string $text, float $size = 9.5): string
    {
        foreach ($runs[$text] ?? [] as $run) {
            if (abs($run['size'] - $size) < 0.001) {
                return $run['font'];
            }
        }
        throw new RuntimeException('Text nicht in der erwarteten Groesse gefunden: ' . $text);
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
