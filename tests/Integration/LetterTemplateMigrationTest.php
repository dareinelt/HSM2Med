<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Database\Migrator;
use App\Letter\LetterTemplate;
use PDO;

/**
 * Prueft das Aufwerten bestehender Installationen: Migration 009 ergaenzt die Vorlagenart und
 * ersetzt die fest hinterlegte Anrede bereits gespeicherter Vorlagen durch den Platzhalter;
 * Migration 010 ergaenzt die Praxis-Informationen und Rücksendeangaben und stellt die
 * Rücksendeangabe gespeicherter Vorlagen auf den Platzhalter {return_address_line} um.
 */
final class LetterTemplateMigrationTest extends DatabaseTestCase
{
    public function testMigrationAddsTemplateTypeAndReplacesStoredSalutation(): void
    {
        $root = dirname(__DIR__, 2);
        $legacyDir = sys_get_temp_dir() . '/hsm2med-migrations-' . bin2hex(random_bytes(4));
        mkdir($legacyDir);
        try {
            $files = Migrator::listMigrationFiles($root . '/database/migrations');
            foreach ($files as $version => $file) {
                if (str_starts_with($version, '009')) {
                    break;
                }
                copy($file, $legacyDir . '/' . basename($file));
            }

            // Stand vor Migration 009 herstellen und eine Vorlage im alten Aufbau anlegen.
            self::resetSchema($this->pdo);
            (new Migrator($this->pdo, $legacyDir))->migrate();

            $legacy = LetterTemplate::default();
            foreach ($legacy['blocks'] as $index => $block) {
                if ($block['type'] === 'salutation') {
                    $legacy['blocks'][$index]['texts']['text'] = 'Sehr geehrte Damen und Herren,';
                }
            }
            // Vor Migration 010 stand der feste Text der Ruecksendeangabe in der Vorlage.
            $legacy['zones']['return_address']['texts']['text'] = '{center_name} · {center_address_line}';
            unset($legacy['zones']['letterhead']['options']['show_contact']);
            $content = LetterTemplate::encode($legacy);
            $stmt = $this->pdo->prepare(
                'INSERT INTO letter_template_versions (version_no, name, comment, schema_version, content, content_sha256, created_at)'
                . ' VALUES (1, ?, NULL, ?, ?, ?, NOW())'
            );
            $stmt->execute([$legacy['name'], LetterTemplate::SCHEMA, $content, hash('sha256', $content)]);
            $id = (int) $this->pdo->lastInsertId();

            $applied = (new Migrator($this->pdo, $root . '/database/migrations'))->migrate();
            $this->assertSame(['009_letter_template_types', '010_practice_settings', '011_patient_record_befund', '012_generic_letter_template'], $applied);

            $row = $this->pdo->query('SELECT template_type, content FROM letter_template_versions WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
            $this->assertSame('patient', $row['template_type']);

            $upgraded = json_decode((string) $row['content'], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('{salutation}', $upgraded['blocks'][1]['texts']['text']);
            $this->assertSame('salutation', $upgraded['blocks'][1]['type']);

            // Die Stammdaten erhalten je Empfaengerart ein eigenes Anredefeld (zunaechst leer).
            $columns = $this->pdo->query('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = \'patient_card_master_data\'')->fetchAll(PDO::FETCH_COLUMN);
            foreach (['salutation', 'physician_salutation', 'referrer_salutation'] as $column) {
                $this->assertTrue(in_array($column, $columns, true), 'Spalte ' . $column . ' fehlt in patient_card_master_data.');
            }

            // Migration 010: Rücksendeangabe und Kontaktangaben kommen aus den Stammdaten.
            $this->assertSame('{return_address_line}', $upgraded['zones']['return_address']['texts']['text']);
            $this->assertTrue($upgraded['zones']['letterhead']['options']['show_contact']);
            $settingsColumns = $this->pdo->query('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = \'patient_card_settings\'')->fetchAll(PDO::FETCH_COLUMN);
            foreach (['practice_phone', 'practice_fax', 'practice_email', 'practice_website', 'return_name', 'return_street', 'return_postal_code', 'return_city'] as $column) {
                $this->assertTrue(in_array($column, $settingsColumns, true), 'Spalte ' . $column . ' fehlt in patient_card_settings.');
            }
        } finally {
            foreach (glob($legacyDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($legacyDir);
        }
    }
}
