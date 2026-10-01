<?php

declare(strict_types=1);

namespace Yatmo\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Yatmo\Client;
use Yatmo\TextRenderer;
use Yatmo\TravelMode;

/** Manual: calls the real Yatmo API with the key in YATMO_LIVE_KEY (`composer test:live`). */
#[Group('live')]
final class LiveTest extends TestCase
{
    private function client(): Client
    {
        $key = getenv('YATMO_LIVE_KEY');
        if ($key === false || $key === '') {
            self::markTestSkipped('YATMO_LIVE_KEY is not set');
        }

        return new Client($key, 'BE', 'FR');
    }

    public function testSummaryTextScoresEnrichment(): void
    {
        $yatmo = $this->client();
        $summary = $yatmo->summary(50.8461, 4.3664);
        self::assertNotEmpty($summary->categories);
        self::assertNotEmpty($summary->placeInformation?->cityName);
        $text = $yatmo->summaryText(50.8461, 4.3664);
        self::assertSame('FR', $text->language);
        self::assertStringContainsString('<h3>', TextRenderer::html($text));
        self::assertNotEmpty($yatmo->scores(50.8461, 4.3664)->scores);
        self::assertNotEmpty($yatmo->enrichment(50.8461, 4.3664)['categories'] ?? []);
    }

    public function testPointsRoutesGeocodingImage(): void
    {
        $yatmo = $this->client();
        self::assertNotEmpty($yatmo->simplifiedCategories());
        self::assertNotEmpty($yatmo->points(50.844, 4.362, 50.849, 4.371));
        self::assertCount(3, $yatmo->isochrones(50.8461, 4.3664, TravelMode::Walking));
        self::assertMatchesRegularExpression('/Polygon/', $yatmo->isochrone(50.8461, 4.3664, TravelMode::Walking, 600)['type']);
        self::assertGreaterThan(500, $yatmo->route(50.8461, 4.3664, 50.8503, 4.3517, TravelMode::Walking)->paths[0]->distanceMeters);
        self::assertNotEmpty($yatmo->geocode('Rue de la Loi 16, 1000 Bruxelles')[0]->city);
        self::assertNotEmpty($yatmo->geocodeNear(50.8461, 4.3664, 'Grand Place'));
        $jpeg = $yatmo->staticMap(50.8461, 4.3664, ['width' => 400, 'height' => 240]);
        self::assertGreaterThan(5000, \strlen($jpeg));
        self::assertSame("\xFF\xD8", substr($jpeg, 0, 2));
    }
}
