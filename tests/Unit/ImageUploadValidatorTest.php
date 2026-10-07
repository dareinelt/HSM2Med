<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Report\Pdf\ImageData;
use App\Security\ImageUploadValidator;
use App\Security\UploadException;
use Tests\Support\Images;
use Tests\TestCase;

/**
 * Logopruefung fuer die Stammdaten: nur echte PNG-/JPEG-Dateien in vertretbarer Groesse.
 */
final class ImageUploadValidatorTest extends TestCase
{
    public function testAcceptsPng(): void
    {
        $validator = new ImageUploadValidator();
        $png = Images::png(12, 9);
        $result = $validator->validateContent($png, 'logo.png');

        $this->assertSame($png, $result['bytes']);
        $this->assertSame('logo.png', $result['filename']);
        $this->assertSame('image/png', $result['mime_type']);
        $this->assertSame(12, $result['width']);
        $this->assertSame(9, $result['height']);
    }

    public function testAcceptsJpeg(): void
    {
        $validator = new ImageUploadValidator();
        $jpeg = Images::jpeg(20, 10);
        $result = $validator->validateContent($jpeg, 'logo.jpg');

        $this->assertSame(20, $result['width']);
        $this->assertSame(10, $result['height']);
        $this->assertSame('image/jpeg', $result['mime_type']);
    }

    public function testAcceptsPngWithoutAlpha(): void
    {
        $result = (new ImageUploadValidator())->validateContent(Images::png(4, 4, false), 'logo.png');
        $this->assertSame(4, $result['width']);
        $this->assertSame(4, $result['height']);
    }

    public function testRejectsEmptyContent(): void
    {
        $this->assertThrows(UploadException::class, fn () => (new ImageUploadValidator())->validateContent('', 'logo.png'));
    }

    public function testRejectsNonImageContent(): void
    {
        $validator = new ImageUploadValidator();
        foreach ([
            '<?php echo "x"; ?>',
            '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>',
            "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n%%EOF",
            'GIF89a' . str_repeat("\x00", 32),
            'einfacher text',
        ] as $content) {
            $this->assertThrows(
                UploadException::class,
                fn () => $validator->validateContent($content, 'logo.png'),
                'Nicht-Bildinhalte muessen abgelehnt werden.',
            );
        }
    }

    public function testRejectsBrokenPng(): void
    {
        // Gueltige Signatur, aber ohne IHDR/Bilddaten
        $broken = \App\Report\Pdf\PngDecoder::SIGNATURE . 'kaputt';
        $this->assertThrows(UploadException::class, fn () => (new ImageUploadValidator())->validateContent($broken, 'logo.png'));
    }

    public function testRejectsOversizedFile(): void
    {
        $validator = new ImageUploadValidator(256);
        $exception = $this->assertThrows(
            UploadException::class,
            fn () => $validator->validateContent(Images::png(64, 64), 'logo.png'),
        );
        $this->assertContains('zu groß', $exception->getMessage());
        $this->assertSame(ImageUploadValidator::MAX_BYTES, 1048576);
    }

    public function testRejectsOversizedDimensions(): void
    {
        $validator = new ImageUploadValidator();
        $exception = $this->assertThrows(
            UploadException::class,
            fn () => $validator->validateContent(Images::png(ImageUploadValidator::MAX_DIMENSION + 1, 2), 'logo.png'),
        );
        $this->assertContains('2000', $exception->getMessage());
        $this->assertSame(2000, ImageUploadValidator::MAX_DIMENSION);
    }

    public function testSanitizesFilename(): void
    {
        $validator = new ImageUploadValidator();
        $result = $validator->validateContent(Images::png(2, 2), '../../etc/passwd.png');
        $this->assertSame('passwd.png', $result['filename']);

        $result = $validator->validateContent(Images::png(2, 2), '');
        $this->assertSame('logo', $result['filename']);
    }

    public function testRejectsMissingUpload(): void
    {
        $validator = new ImageUploadValidator();
        $this->assertThrows(UploadException::class, fn () => $validator->validateUpload(null));
        $this->assertThrows(UploadException::class, fn () => $validator->validateUpload(['error' => UPLOAD_ERR_NO_FILE]));
        $this->assertThrows(UploadException::class, fn () => $validator->validateUpload(['error' => UPLOAD_ERR_INI_SIZE]));
        $this->assertThrows(
            UploadException::class,
            fn () => $validator->validateUpload(['error' => UPLOAD_ERR_OK, 'tmp_name' => __FILE__, 'name' => 'logo.png']),
            'Nicht hochgeladene Dateien werden abgelehnt.',
        );
    }

    public function testImageDataParsesPngAndJpeg(): void
    {
        $png = ImageData::fromBytes(Images::png(6, 3));
        $this->assertSame(6, $png->width);
        $this->assertSame(3, $png->height);
        $this->assertSame('DeviceRGB', $png->colorSpace);
        $this->assertSame('FlateDecode', $png->filter);
        $this->assertTrue($png->smaskData !== null, 'RGBA-PNG liefert eine Weichmaske');

        $jpeg = ImageData::fromBytes(Images::jpeg(7, 5));
        $this->assertSame(7, $jpeg->width);
        $this->assertSame(5, $jpeg->height);
        $this->assertSame('DCTDecode', $jpeg->filter);

        $this->assertThrows(\InvalidArgumentException::class, fn () => ImageData::fromBytes('kein bild'));
    }
}
