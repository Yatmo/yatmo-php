<?php

declare(strict_types=1);

namespace Yatmo;

use Yatmo\Exception\TransportException;
use Yatmo\Exception\YatmoException;
use Yatmo\Model\CategoryGroup;
use Yatmo\Model\Isochrone;
use Yatmo\Model\Place;
use Yatmo\Model\Poi;
use Yatmo\Model\Route;
use Yatmo\Model\Scores;
use Yatmo\Model\Summary;
use Yatmo\Model\SummaryText;
use Yatmo\Transport\CurlTransport;
use Yatmo\Transport\StreamTransport;
use Yatmo\Transport\TransportInterface;

/**
 * Typed client for the Yatmo API, one instance per country. Every call sends the key in the
 * `LicenseKey` header and throws a `Yatmo\Exception\YatmoException` on failure.
 *
 *   $yatmo = new Client('your_backend_key', 'BE', 'FR');
 *   $text = $yatmo->summaryText(50.8461, 4.3664);
 *   echo TextRenderer::html($text);
 */
final class Client
{
    public const VERSION = '1.1.0';

    private readonly string $baseUrl;
    private readonly TransportInterface $transport;

    /**
     * @param string $key The backend key (server to server). The frontend key only ever belongs in the browser.
     * @param string $country Two-letter country code: BE, FR, NL, LU, CH, DE, IT, ES, PT, IE, UK, AT, CA, GR, MA, AU, HR, MT, SI, RS, CY, BA, ME, BG, AL
     * @param string $language Language of the labels and texts, default EN
     * @param string|null $apiBaseUrl Override the API host, for staging environments
     */
    public function __construct(
        private readonly string $key,
        private readonly string $country,
        private readonly string $language = 'EN',
        ?TransportInterface $transport = null,
        private readonly int $timeoutSeconds = 15,
        ?string $apiBaseUrl = null,
    ) {
        if ($key === '') {
            throw new YatmoException(401, 'Yatmo: the key is required');
        }
        if (!preg_match('/^[A-Za-z]{2}$/', $country)) {
            throw new YatmoException(400, 'Yatmo: the country must be a two-letter code');
        }
        $base = $apiBaseUrl ?? 'https://' . strtolower($country) . '.yatmo.com/';
        $this->baseUrl = rtrim($base, '/') . '/';
        $this->transport = $transport ?? (\function_exists('curl_init') ? new CurlTransport() : new StreamTransport());
    }

    public function language(): string
    {
        return strtoupper($this->language);
    }

    /** GET /summary: nearby places by category with travel times, closest cities, resolved address. */
    public function summary(float $latitude, float $longitude): Summary
    {
        return Summary::fromWire($this->summaryRaw($latitude, $longitude));
    }

    /**
     * GET /summary as sent by the API (short keys), a plain array that any cache stores; rebuild with `Summary::fromWire()`.
     *
     * @return array<string, mixed>
     */
    public function summaryRaw(float $latitude, float $longitude): array
    {
        return $this->getJson('summary', $this->position($latitude, $longitude));
    }

    /** GET /Summary/text as sent by the API: every language of the country. Cache it, a location rarely changes. */
    public function summaryTextRaw(float $latitude, float $longitude): array
    {
        return $this->getJson('Summary/text', $this->position($latitude, $longitude));
    }

    /** GET /Summary/text resolved to the configured language (or `$language`), with the `[STRONG]` markers kept. See TextRenderer. */
    public function summaryText(float $latitude, float $longitude, ?string $language = null): SummaryText
    {
        return SummaryText::fromWire($this->summaryTextRaw($latitude, $longitude), $language ?? $this->language());
    }

    /** GET /scores: one 0 to 10 score per category. */
    public function scores(float $latitude, float $longitude): Scores
    {
        return Scores::fromWire($this->scoresRaw($latitude, $longitude));
    }

    /**
     * GET /scores as sent by the API, a plain array that any cache stores; rebuild with `Scores::fromWire()`.
     *
     * @return array<string, mixed>
     */
    public function scoresRaw(float $latitude, float $longitude): array
    {
        return $this->getJson('scores', $this->position($latitude, $longitude));
    }

    /**
     * GET /enrichment: the nearest place of each category with distances and times for four travel
     * modes, as the API sends it (camelCase keys), for listing data.
     *
     * @return array<string, mixed>
     */
    public function enrichment(float $latitude, float $longitude): array
    {
        return $this->getJson('enrichment', $this->position($latitude, $longitude));
    }

    /**
     * GET /points inside a bounding box. Ask from zoom 13 upwards.
     *
     * @param int[] $poiTypeIds Category ids to keep (see simplifiedCategories()), every category when empty
     * @return Poi[]
     */
    public function points(float $southLatitude, float $westLongitude, float $northLatitude, float $eastLongitude, array $poiTypeIds = []): array
    {
        $query = [
            'bound1' => self::coordinate($southLatitude) . ',' . self::coordinate($westLongitude),
            'bound2' => self::coordinate($northLatitude) . ',' . self::coordinate($eastLongitude),
            'groupSamePositions' => 'true',
            'caringForBigResponse' => 'true',
        ];
        if ($poiTypeIds !== []) {
            $query['poiTypesIds'] = implode(',', $poiTypeIds);
        }

        return array_map([Poi::class, 'fromWire'], $this->getJson('points', $query));
    }

    /**
     * GET /SimplifiedCategories: category ids grouped by family, the values accepted by `$poiTypeIds`.
     *
     * @return CategoryGroup[]
     */
    public function simplifiedCategories(): array
    {
        $groups = [];
        foreach ($this->getJson('SimplifiedCategories', []) as $name => $ids) {
            $groups[] = new CategoryGroup((string) $name, array_map('intval', (array) $ids));
        }
        usort($groups, fn (CategoryGroup $a, CategoryGroup $b) => strcmp($a->name, $b->name));

        return $groups;
    }

    /**
     * GET /Isochrone/GetMultipleTimes: the 5, 10 and 20 minute areas, smallest first.
     *
     * @return Isochrone[]
     */
    public function isochrones(float $latitude, float $longitude, TravelMode $mode): array
    {
        $wire = $this->getJson('Isochrone/GetMultipleTimes', $this->position($latitude, $longitude) + ['travelMode' => $mode->value]);

        return array_map(fn (array $item) => new Isochrone((string) ($item['label'] ?? ''), $item['iso'] ?? []), $wire);
    }

    /**
     * GET /isochrone: the area reachable within `$seconds`, as a GeoJSON geometry.
     *
     * @return array<string, mixed>
     */
    public function isochrone(float $latitude, float $longitude, TravelMode $mode, int $seconds): array
    {
        $wire = $this->getJson('isochrone', $this->position($latitude, $longitude) + ['travelMode' => $mode->value, 'numberOfSeconds' => (string) $seconds]);

        return $wire['geometry'] ?? $wire;
    }

    /** GET /route: the route between two points, distance in metres and duration in seconds. */
    public function route(float $fromLatitude, float $fromLongitude, float $toLatitude, float $toLongitude, TravelMode $mode): Route
    {
        return Route::fromWire($this->getJson('route', [
            'travelMode' => $mode->value,
            'startLatitude' => self::coordinate($fromLatitude), 'startLongitude' => self::coordinate($fromLongitude),
            'arrivalLatitude' => self::coordinate($toLatitude), 'arrivalLongitude' => self::coordinate($toLongitude),
        ]));
    }

    /**
     * GET /geolocation: an address to coordinates, inside the configured country. Best match first.
     *
     * @return Place[]
     */
    public function geocode(string $address): array
    {
        return $this->places($this->getJson('geolocation', ['address' => trim($address)]));
    }

    /**
     * GET /Geolocation/GetClose: address autocomplete near a position, inside the configured country.
     *
     * @return Place[]
     */
    public function geocodeNear(float $latitude, float $longitude, string $query): array
    {
        return $this->places($this->getJson('Geolocation/GetClose', $this->position($latitude, $longitude) + ['address' => trim($query)]));
    }

    /**
     * GET /image: a JPEG of the map centred on the property, as bytes.
     *
     * @param array{color?: string, width?: int, height?: int, mapStyle?: MapStyle, threeD?: bool, bigIcons?: bool, multiBorders?: bool, customMarker?: array{url: string, width: int, height: int}} $options
     */
    public function staticMap(float $latitude, float $longitude, array $options = []): string
    {
        return $this->request($this->staticMapUrl($latitude, $longitude, $options), 'image/jpeg');
    }

    /**
     * URL of the static map; the request must carry the `LicenseKey` header (see headers()), it is not an `<img>` URL.
     *
     * @param array<string, mixed> $options See staticMap()
     */
    public function staticMapUrl(float $latitude, float $longitude, array $options = []): string
    {
        $query = $this->position($latitude, $longitude) + [
            'hexaColor' => ltrim((string) ($options['color'] ?? '#428BFF'), '#'),
            'width' => (string) ($options['width'] ?? 800),
            'height' => (string) ($options['height'] ?? 450),
        ];
        if (isset($options['mapStyle'])) {
            $query['mapStyle'] = (string) ($options['mapStyle'] instanceof MapStyle ? $options['mapStyle']->value : $options['mapStyle']);
        }
        if (!empty($options['threeD'])) {
            $query['threeDMode'] = 'true';
        }
        if (!empty($options['bigIcons'])) {
            $query['bigIcon'] = 'true';
        }
        if (!empty($options['multiBorders'])) {
            $query['multiborders'] = 'true';
        }
        if (isset($options['customMarker'])) {
            $query['customMarkerUrl'] = (string) $options['customMarker']['url'];
            $query['customMarkerWidth'] = (string) $options['customMarker']['width'];
            $query['customMarkerHeight'] = (string) $options['customMarker']['height'];
        }

        return $this->url('image', $query);
    }

    /**
     * Headers sent with every request, for callers that need an endpoint not wrapped above.
     *
     * @return array<string, string>
     */
    public function headers(string $accept = 'application/json'): array
    {
        return ['LicenseKey' => $this->key, 'X-Yatmo-SDK' => 'yatmo-php/' . self::VERSION, 'Accept' => $accept];
    }

    /**
     * Full URL of an endpoint with the language appended.
     *
     * @param array<string, string> $query
     */
    public function url(string $path, array $query = []): string
    {
        return $this->baseUrl . $path . '?' . http_build_query($query + ['language' => $this->language()], '', '&', PHP_QUERY_RFC3986);
    }

    private function request(string $url, string $accept): string
    {
        $response = $this->transport->get($url, $this->headers($accept), $this->timeoutSeconds);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $message = $response['body'];
            $decoded = json_decode($response['body'], true);
            if (\is_array($decoded) && isset($decoded['Error'])) {
                $message = (string) $decoded['Error'];
            }
            throw YatmoException::fromStatus($response['status'], sprintf('Yatmo API %d: %s', $response['status'], $message));
        }

        return $response['body'];
    }

    /**
     * @param array<string, string> $query
     * @return array<mixed>
     */
    private function getJson(string $path, array $query): array
    {
        $body = $this->request($this->url($path, $query), 'application/json');
        $decoded = json_decode($body, true);
        if (!\is_array($decoded)) {
            throw new TransportException(0, 'Yatmo API: unreadable response');
        }

        return $decoded;
    }

    /**
     * @param array<mixed> $root
     * @return Place[]
     */
    private function places(array $root): array
    {
        return array_values(array_filter(array_map([Place::class, 'fromFeature'], $root['features'] ?? [])));
    }

    /** @return array<string, string> */
    private function position(float $latitude, float $longitude): array
    {
        return ['latitude' => self::coordinate($latitude), 'longitude' => self::coordinate($longitude)];
    }

    private static function coordinate(float $value): string
    {
        return number_format($value, 7, '.', '');
    }
}
