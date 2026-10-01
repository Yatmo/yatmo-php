<?php

declare(strict_types=1);

namespace Yatmo\Tests;

use PHPUnit\Framework\TestCase;
use Yatmo\Client;
use Yatmo\Exception\ForbiddenException;
use Yatmo\Exception\TransportException;
use Yatmo\Exception\YatmoException;
use Yatmo\MapStyle;
use Yatmo\Model\SummaryCategory;
use Yatmo\TextRenderer;
use Yatmo\TravelMode;

/** Hermetic: the transport is faked, Yatmo is never called. */
final class ClientTest extends TestCase
{
    private const SUMMARY_TEXT = ['Paragraphs' => [
        ['IconId' => 'shopping', 'Title' => ['FR' => 'Commerces', 'EN' => 'Shops', 'NL' => 'Winkels'], 'TitleBis' => ['FR' => 'Commerces près de la Rue de la Loi', 'EN' => 'Shops near Rue de la Loi'], 'TitleTer' => ['FR' => 'Commerces à Bruxelles', 'EN' => 'Shops in Brussels'],
            'Sentences' => [['FR' => 'Un [STRONG]Carrefour[/STRONG] à 3 minutes.', 'EN' => 'A [STRONG]Carrefour[/STRONG] 3 minutes away.']], 'List' => []],
        ['IconId' => 'education', 'Title' => ['FR' => 'Écoles', 'EN' => 'Schools'], 'TitleBis' => ['FR' => 'Écoles près de la Rue de la Loi', 'EN' => 'Schools near Rue de la Loi'], 'TitleTer' => ['FR' => 'Écoles à Bruxelles', 'EN' => 'Schools in Brussels'],
            'Sentences' => [['FR' => 'Deux écoles <primaires> à 10 minutes.', 'EN' => 'Two <primary> schools.']], 'List' => [['FR' => 'École A', 'EN' => 'School A']]],
        ['IconId' => 'cities', 'Title' => ['FR' => 'Villes', 'EN' => 'Cities'], 'Sentences' => [], 'List' => []],
    ]];
    private const SUMMARY = ['AvailableCategoriesAroundPosition' => [
        ['ct' => 1, 'l' => 'Education', 'sc' => [['st' => 4, 'l' => 'Nurseries', 'lb' => 'Nursery', 'd' => [['id' => 10010004, 'n' => 'Little Bears', 'la' => 50.84, 'lo' => 4.36, 'td' => [['hti' => true, 'tm' => 2, 'ptdd' => 300, 'ptdsl' => '300 m', 'tt' => 240, 'ttsl' => "4'"]]]]]]],
        ['ct' => 3, 'l' => 'Shopping', 'sc' => [['st' => 9, 'l' => 'Supermarkets', 'lb' => 'Supermarket', 'd' => [['n' => 'Carrefour', 'la' => 50.84, 'lo' => 4.36, 'td' => []]]]]],
    ], 'CloseCities' => [['n' => ['EN' => 'Mechelen', 'FR' => 'Malines'], 'td' => [], 'c' => ['Latitude' => 51.02, 'Longitude' => 4.48]]], 'PlaceInformation' => ['StreetName' => 'Rue de la Loi', 'IsLocality' => false, 'CityName' => 'Brussels', 'ZipCode' => '1000']];

    private function client(array $answers, string $language = 'FR'): array
    {
        $transport = new FakeTransport($answers);

        return [new Client('abc123', 'BE', $language, $transport), $transport];
    }

    public function testConstructorValidation(): void
    {
        $this->expectException(YatmoException::class);
        new Client('', 'BE');
    }

    public function testUrlsAndHeaders(): void
    {
        [$client, $transport] = $this->client(['/summary?' => ['body' => self::SUMMARY]]);
        $summary = $client->summary(50.8461, 4.3664);
        self::assertSame('https://be.yatmo.com/summary?latitude=50.8461000&longitude=4.3664000&language=FR', $transport->calls[0]['url']);
        self::assertSame('abc123', $transport->calls[0]['headers']['LicenseKey']);
        self::assertStringStartsWith('yatmo-php/', $transport->calls[0]['headers']['X-Yatmo-SDK']);
        self::assertCount(2, $summary->categories);
        self::assertSame('Rue de la Loi', $summary->placeInformation?->streetName);
        self::assertSame('Malines', $summary->closeCities[0]->names['FR']);
        $nursery = $summary->categories[0]->subCategories[0]->places[0];
        self::assertSame("4'", $nursery->travel(TravelMode::Walking)?->travelTimeShortLabel);
        self::assertNull($nursery->travel(TravelMode::Driving));
        self::assertSame(['Supermarkets'], array_map(fn ($s) => $s->label, iterator_to_array($summary->subCategories(SummaryCategory::SHOPPING), false)));
    }

    public function testCustomBaseUrl(): void
    {
        $client = new Client('k', 'FR', 'EN', new FakeTransport([]), 15, 'http://localhost:5000');
        self::assertSame('http://localhost:5000/scores?latitude=48.8566000&longitude=2.3522000&language=EN', $client->url('scores', ['latitude' => '48.8566000', 'longitude' => '2.3522000']));
    }

    public function testErrorsAreTyped(): void
    {
        [$client] = $this->client(['/scores?' => ['status' => 403, 'body' => ['Error' => 'Country not allowed']]]);
        try {
            $client->scores(50.8, 4.3);
            self::fail('expected an exception');
        } catch (ForbiddenException $e) {
            self::assertSame(403, $e->status);
            self::assertSame('Yatmo API 403: Country not allowed', $e->getMessage());
        }
        [$client] = $this->client(['/summary?' => ['body' => 'not json']]);
        $this->expectException(TransportException::class);
        $client->summary(50.8, 4.3);
    }

    public function testSummaryTextResolvesLanguageAndRenders(): void
    {
        [$client, $transport] = $this->client(['/Summary/text?' => ['body' => self::SUMMARY_TEXT]]);
        $text = $client->summaryText(50.8461, 4.3664);
        self::assertSame('FR', $text->language);
        self::assertSame(['shopping', 'education'], array_map(fn ($p) => $p->iconId, $text->paragraphs));
        self::assertStringContainsString('be.yatmo.com/Summary/text?latitude=50.8461000', $transport->calls[0]['url']);
        self::assertSame(
            '<div class="yatmo-text"><h3>Commerces près de la Rue de la Loi</h3><p>Un <strong>Carrefour</strong> à 3 minutes.</p>'
            . '<h3>Écoles à Bruxelles</h3><p>Deux écoles &lt;primaires&gt; à 10 minutes.</p><ul><li>École A</li></ul></div>',
            TextRenderer::html($text),
        );
        self::assertSame(
            '<div class="yatmo-text mine"><h2>Écoles à Bruxelles</h2><p>Deux écoles &lt;primaires&gt; à 10 minutes.</p><ul><li>École A</li></ul></div>',
            TextRenderer::html($text, ['paragraphs' => ['education'], 'heading' => 'h2', 'titles' => 'city', 'class' => 'mine']),
        );
        self::assertSame("### Commerces\n\nUn **Carrefour** à 3 minutes.\n\n### Écoles\n\nDeux écoles <primaires> à 10 minutes.\n- École A", TextRenderer::markdown($text, ['titles' => 'generic']));
        self::assertSame("Commerces près de la Rue de la Loi\nUn Carrefour à 3 minutes.\n\nÉcoles à Bruxelles\nDeux écoles <primaires> à 10 minutes.\n- École A", TextRenderer::plain($text));

        $english = $client->summaryText(50.8461, 4.3664, 'DE');
        self::assertSame('EN', $english->language);
        self::assertSame('Shops', $english->paragraphs[0]->title);
    }

    public function testOtherEndpoints(): void
    {
        [$client, $transport] = $this->client([
            '/points?' => ['body' => [['n' => 'Bus', 't' => 'Bus stop', 'la' => 50.84, 'ln' => 4.35, 'p' => '1003', 'i' => '35,36', 'si' => '', 'g' => true]]],
            '/Isochrone/GetMultipleTimes' => ['body' => [['label' => '5', 'iso' => ['type' => 'Polygon', 'coordinates' => []]]]],
            '/isochrone?' => ['body' => ['type' => 'Polygon', 'coordinates' => []]],
            '/route?' => ['body' => ['paths' => [['distance' => 1234.5, 'time' => 456000, 'points' => ['type' => 'LineString', 'coordinates' => [[4.3, 50.8]]]]], 'info' => []]],
            '/geolocation?' => ['body' => ['features' => [['geometry' => ['coordinates' => [4.3677, 50.84367]], 'properties' => ['street' => 'Rue de la Loi', 'housenumber' => '16', 'postcode' => '1000', 'city' => 'Brussels']], ['geometry' => null]]]],
            '/SimplifiedCategories' => ['body' => ['Transports' => [2, 1], 'Education' => [3]]],
        ]);
        $pois = $client->points(50.84, 4.34, 50.85, 4.36, [1003]);
        self::assertStringContainsString('bound1=50.8400000%2C4.3400000&bound2=50.8500000%2C4.3600000&groupSamePositions=true&caringForBigResponse=true&poiTypesIds=1003', $transport->calls[0]['url']);
        self::assertSame(['35', '36'], $pois[0]->iconIds);
        self::assertTrue($pois[0]->grouped);

        $areas = $client->isochrones(50.84, 4.35, TravelMode::Bicycling);
        self::assertStringContainsString('travelMode=Bicycling', $transport->calls[1]['url']);
        self::assertSame('Polygon', $areas[0]->geometry['type']);
        self::assertSame('Polygon', $client->isochrone(50.84, 4.35, TravelMode::Walking, 600)['type']);
        self::assertStringContainsString('numberOfSeconds=600', $transport->calls[2]['url']);

        $route = $client->route(50.84, 4.35, 50.86, 4.36, TravelMode::Driving);
        self::assertStringContainsString('travelMode=Driving&startLatitude=50.8400000&startLongitude=4.3500000&arrivalLatitude=50.8600000&arrivalLongitude=4.3600000', $transport->calls[3]['url']);
        self::assertSame(456, $route->paths[0]->durationSeconds);

        $places = $client->geocode(' Rue de la Loi 16, Bruxelles ');
        self::assertStringContainsString('geolocation?address=Rue%20de%20la%20Loi%2016%2C%20Bruxelles', $transport->calls[4]['url']);
        self::assertCount(1, $places);
        self::assertSame('Rue de la Loi 16, 1000 Brussels', $places[0]->label);
        self::assertSame(50.84367, $places[0]->latitude);

        $groups = $client->simplifiedCategories();
        self::assertSame(['Education', 'Transports'], array_map(fn ($g) => $g->name, $groups));
    }

    public function testStaticMapUrl(): void
    {
        [$client] = $this->client([]);
        self::assertSame(
            'https://be.yatmo.com/image?latitude=50.8000000&longitude=4.3000000&hexaColor=06A7EA&width=600&height=300&mapStyle=6&bigIcon=true&language=FR',
            $client->staticMapUrl(50.8, 4.3, ['color' => '#06A7EA', 'width' => 600, 'height' => 300, 'mapStyle' => MapStyle::Dark, 'bigIcons' => true]),
        );
    }
}
