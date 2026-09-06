<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The two brand resolvers, and the one thing they must never do: point at a
 * file that is not there.
 *
 * Both have already shipped that bug once — `App_Logo` still holds the
 * template's `logo1.png` with no file behind it, and the image placeholder used
 * to fall back to `storage/default.png` on the uploads disk, which nothing ever
 * writes. A broken image in every empty row looks like a permissions fault and
 * costs an afternoon.
 */
class BrandAssetTest extends TestCase
{
    // `brandLogo()` reads the `App_Logo` setting, so the table has to be there.
    // The placeholder tests below touch nothing, but one shared trait is cheaper
    // than splitting four assertions across two files.
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    #[Test]
    public function the_placeholder_is_the_square_mark_and_the_file_exists(): void
    {
        $url = brandPlaceholder();

        $this->assertStringContainsString('laundo-mark.png', $url);
        $this->assertFileExists(public_path('assets/images/brand/laundo-mark.png'));
    }

    #[Test]
    public function the_placeholder_is_square_because_every_slot_it_fills_is(): void
    {
        // A wordmark scaled into an 80x80 thumbnail is an illegible smudge, so
        // this asserts the shape rather than trusting the filename.
        [$width, $height] = getimagesize(public_path('assets/images/brand/laundo-mark.png'));

        $this->assertSame($width, $height, 'the placeholder must be square');
    }

    #[Test]
    public function the_placeholder_never_points_at_the_uploads_disk(): void
    {
        // The old `storage/default.png` bug: a path on a disk nothing writes to.
        $this->assertStringNotContainsString('/storage/', brandPlaceholder());
    }

    #[Test]
    public function both_logo_variants_resolve_to_files_that_exist(): void
    {
        foreach (['dark', 'light'] as $variant) {
            $url = brandLogo($variant);
            $file = public_path(parse_url($url, PHP_URL_PATH));

            $this->assertFileExists($file, "brandLogo('{$variant}') points at nothing");
        }
    }
}
