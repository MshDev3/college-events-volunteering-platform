<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\Exceptions\ValidationException;
use App\Services\UploadService;
use PHPUnit\Framework\TestCase;

/** Upload validation: only real images/PDFs pass, names and extensions are never user-controlled. */
final class UploadServiceTest extends TestCase
{
    private UploadService $uploads;

    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        $this->uploads = new UploadService(app(Config::class));
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            @unlink($path);
        }
    }

    /** @return array{name:string,type:string,tmp_name:string,error:int,size:int} */
    private function file(string $contents, string $clientName, string $clientType = 'image/jpeg'): array
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, $contents);
        $this->cleanup[] = $tmp;

        return ['name' => $clientName, 'type' => $clientType, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($contents)];
    }

    private function png(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/img/feedback.png');
    }

    private function assertRejected(callable $fn): void
    {
        try {
            $fn();
            self::fail('Upload should have been rejected.');
        } catch (ValidationException $e) {
            self::assertNotEmpty($e->errors);
        }
    }

    public function testRealImageIsStoredUnderARandomNameWithMimeDerivedExtension(): void
    {
        $path = $this->uploads->storePublicImage($this->file($this->png(), '../../evil.php.jpg'), 'image', 'events');
        $this->cleanup[] = dirname(__DIR__, 2) . '/public/' . $path;

        self::assertMatchesRegularExpression('#^uploads/events/[a-f0-9]{32}\.png$#', $path, 'name/extension ignore the client filename');
        self::assertFileExists(dirname(__DIR__, 2) . '/public/' . $path);
    }

    public function testPhpScriptsAndPolyglotsAreRejected(): void
    {
        // Plain PHP claiming to be a JPEG.
        $this->assertRejected(fn () => $this->uploads->storePublicImage($this->file('<?php system($_GET["c"]); ?>', 'photo.jpg'), 'image', 'events'));
        // GIF magic bytes + PHP payload (finfo says image/gif, which is not allowed, and it does not decode as PNG/JPEG/WEBP).
        $this->assertRejected(fn () => $this->uploads->storePublicImage($this->file("GIF89a<?php echo 1; ?>", 'x.gif', 'image/gif'), 'image', 'events'));
        // JPEG signature followed by script (not a decodable image).
        $this->assertRejected(fn () => $this->uploads->storePublicImage($this->file("\xFF\xD8\xFF\xE0<?php echo 1; ?>", 'x.jpg'), 'image', 'events'));
    }

    public function testSvgAndHtmlAreRejected(): void
    {
        $this->assertRejected(fn () => $this->uploads->storePublicImage($this->file('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'a.svg', 'image/svg+xml'), 'image', 'events'));
        $this->assertRejected(fn () => $this->uploads->storePrivate($this->file('<html><script>alert(1)</script></html>', 'a.pdf', 'application/pdf'), 'attachment'));
    }

    public function testPrivateAttachmentsLiveOutsideTheWebRoot(): void
    {
        $stored = $this->uploads->storePrivate($this->file("%PDF-1.4\n%fake but signed\n", '../../../secret report?.pdf', 'application/pdf'), 'attachment');
        $path = $this->uploads->privatePath($stored['stored_name']);
        $this->cleanup[] = (string) $path;

        self::assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $stored['stored_name']);
        self::assertStringNotContainsString('/', $stored['original_name']);
        self::assertStringContainsString(DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR, str_replace('/', DIRECTORY_SEPARATOR, (string) $path));
        self::assertNull($this->uploads->privatePath('../../.env'), 'no path traversal through stored names');
    }

    public function testOversizedFilesAreRejected(): void
    {
        $file = $this->file($this->png(), 'big.png');
        $file['size'] = (int) config('uploads.max_bytes') + 1;
        $this->assertRejected(fn () => $this->uploads->storePrivate($file, 'attachment'));
    }
}
