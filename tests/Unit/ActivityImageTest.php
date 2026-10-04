<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Card covers: own upload first, else one of the type/category default photos, never another record's image. */
final class ActivityImageTest extends TestCase
{
    private const EVENT_TYPES = ['lecture', 'workshop', 'competition', 'sports', 'cultural', 'exhibition'];
    private const CATEGORIES = ['event_management' => 'event-management', 'design' => 'design', 'translation' => 'translation', 'tech_support' => 'tech-support'];

    public function testUploadedImageWins(): void
    {
        self::assertSame('uploads/events/abc.png', activity_image(['id' => 3, 'image_path' => 'uploads/events/abc.png', 'type_code' => 'sports'], 'event'));
    }

    public function testEveryTypeAndCategoryHasBundledDefaults(): void
    {
        $public = dirname(__DIR__, 2) . '/public/';
        foreach (self::EVENT_TYPES as $code) {
            $path = activity_image(['id' => 1, 'image_path' => null, 'type_code' => $code], 'event');
            self::assertMatchesRegularExpression("#^assets/img/defaults/events/$code-\\d+\\.jpg$#", $path);
            self::assertFileExists($public . $path);
        }
        foreach (self::CATEGORIES as $code => $file) {
            $path = activity_image(['id' => 1, 'image_path' => '', 'type_code' => $code], 'volunteer');
            self::assertMatchesRegularExpression("#^assets/img/defaults/volunteering/$file-\\d+\\.jpg$#", $path);
            self::assertFileExists($public . $path);
        }
    }

    public function testThePhotoIsStablePerRecordAndVariesAcrossNeighbours(): void
    {
        $count = count(default_photos('assets/img/defaults/events', 'workshop'));
        self::assertGreaterThanOrEqual(2, $count);
        $a = activity_image(['id' => 10, 'type_code' => 'workshop'], 'event');
        self::assertSame($a, activity_image(['id' => 10, 'type_code' => 'workshop'], 'event'));
        self::assertNotSame($a, activity_image(['id' => 11, 'type_code' => 'workshop'], 'event'));
        self::assertSame($a, activity_image(['id' => 10 + $count, 'type_code' => 'workshop'], 'event'));
    }

    public function testOtherUnknownOrHostileTypeFallsBackToGeneral(): void
    {
        foreach (['other', 'new_type', '../../x', ''] as $code) {
            self::assertMatchesRegularExpression('#^assets/img/defaults/events/general-\d+\.jpg$#', activity_image(['id' => 5, 'type_code' => $code], 'event'));
        }
        self::assertMatchesRegularExpression('#^assets/img/defaults/volunteering/general-\d+\.jpg$#', activity_image([], 'volunteer'));
    }
}
