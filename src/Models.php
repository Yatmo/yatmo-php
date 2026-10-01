<?php

declare(strict_types=1);

namespace Yatmo\Model;

use Yatmo\TravelMode;

/** Distance and travel time of one travel mode towards a place. */
final class TravelData
{
    public function __construct(
        public readonly bool $hasTravelInformation,
        public readonly ?TravelMode $travelMode,
        public readonly ?string $translatedTravelMode,
        public readonly ?int $distanceMeters,
        public readonly ?string $distanceLongLabel,
        public readonly ?string $distanceShortLabel,
        public readonly ?int $travelTimeSeconds,
        public readonly ?string $travelTimeLongLabel,
        public readonly ?string $travelTimeShortLabel,
        public readonly ?string $travelTimeExtraShortLabel,
    ) {
    }

    /** @param array<string, mixed> $w */
    public static function fromWire(array $w): self
    {
        return new self(
            (bool) ($w['hti'] ?? false), TravelMode::fromSummaryCode((int) ($w['tm'] ?? 0)), $w['ttm'] ?? null,
            isset($w['ptdd']) ? (int) $w['ptdd'] : null, $w['ptdll'] ?? null, $w['ptdsl'] ?? null,
            isset($w['tt']) ? (int) $w['tt'] : null, $w['ttll'] ?? null, $w['ttsl'] ?? null, $w['ttesl'] ?? null,
        );
    }
}

/** One place of the summary (a nursery, a supermarket, a bus stop...). */
final class SummaryPlace
{
    /** @param TravelData[] $travelData */
    public function __construct(
        public readonly int $categoryId,
        public readonly int $icon,
        public readonly int $subIcon,
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly string $name,
        public readonly ?string $specificData,
        public readonly array $travelData,
    ) {
    }

    /** The travel data of a mode, when the API computed it. */
    public function travel(TravelMode $mode): ?TravelData
    {
        foreach ($this->travelData as $t) {
            if ($t->travelMode === $mode && $t->hasTravelInformation) {
                return $t;
            }
        }

        return null;
    }
}

/** A sub-category of the summary (nurseries, high schools, bus stops...). */
final class SummarySubCategory
{
    /** @param SummaryPlace[] $places */
    public function __construct(
        public readonly int $subType,
        public readonly string $label,
        public readonly ?string $singularLabel,
        public readonly array $places,
    ) {
    }
}

/** A category of the summary: 1 education, 2 transport, 3 shopping, 7 tourism. */
final class SummaryCategory
{
    public const EDUCATION = 1;
    public const TRANSPORT = 2;
    public const SHOPPING = 3;
    public const TOURISM = 7;

    /** @param SummarySubCategory[] $subCategories */
    public function __construct(
        public readonly int $categoryType,
        public readonly string $label,
        public readonly array $subCategories,
    ) {
    }
}

/** A major city near the property, with the travel data. */
final class CloseCity
{
    /**
     * @param array<string, string> $names City name per language code
     * @param TravelData[] $travelData
     */
    public function __construct(
        public readonly array $names,
        public readonly array $travelData,
        public readonly ?float $latitude,
        public readonly ?float $longitude,
    ) {
    }
}

/** Street, city and zip code when Yatmo resolves the address of the position. */
final class PlaceInformation
{
    /**
     * @param array<string, string>|null $localizedStreetNames
     * @param array<string, string>|null $localizedCityNames
     */
    public function __construct(
        public readonly ?string $streetName,
        public readonly bool $isLocality,
        public readonly ?string $cityName,
        public readonly ?string $zipCode,
        public readonly ?array $localizedStreetNames,
        public readonly ?array $localizedCityNames,
    ) {
    }
}

/** GET /summary: the places around a position by category, the closest cities, the resolved address. */
final class Summary
{
    /**
     * @param SummaryCategory[] $categories
     * @param CloseCity[] $closeCities
     */
    public function __construct(
        public readonly array $categories,
        public readonly array $closeCities,
        public readonly ?PlaceInformation $placeInformation,
    ) {
    }

    /** @param array<string, mixed> $w */
    public static function fromWire(array $w): self
    {
        $categories = [];
        foreach ($w['AvailableCategoriesAroundPosition'] ?? [] as $c) {
            $subs = [];
            foreach ($c['sc'] ?? [] as $s) {
                $places = [];
                foreach ($s['d'] ?? [] as $d) {
                    $places[] = new SummaryPlace(
                        (int) ($d['id'] ?? 0), (int) ($d['i'] ?? 0), (int) ($d['si'] ?? 0), (float) $d['la'], (float) $d['lo'],
                        (string) ($d['n'] ?? ''), $d['sd'] ?? null, array_map([TravelData::class, 'fromWire'], $d['td'] ?? []),
                    );
                }
                $subs[] = new SummarySubCategory((int) ($s['st'] ?? 0), (string) ($s['l'] ?? ''), $s['lb'] ?? null, $places);
            }
            $categories[] = new SummaryCategory((int) ($c['ct'] ?? 0), (string) ($c['l'] ?? ''), $subs);
        }
        $cities = [];
        foreach ($w['CloseCities'] ?? [] as $c) {
            $cities[] = new CloseCity(
                $c['n'] ?? [], array_map([TravelData::class, 'fromWire'], $c['td'] ?? []),
                isset($c['c']['Latitude']) ? (float) $c['c']['Latitude'] : null, isset($c['c']['Longitude']) ? (float) $c['c']['Longitude'] : null,
            );
        }
        $p = $w['PlaceInformation'] ?? null;
        $place = \is_array($p) ? new PlaceInformation(
            $p['StreetName'] ?? null, (bool) ($p['IsLocality'] ?? false), $p['CityName'] ?? null, $p['ZipCode'] ?? null,
            $p['LocalizedStreetNames'] ?? null, $p['LocalizedCityNames'] ?? null,
        ) : null;

        return new self($categories, $cities, $place);
    }

    /** The sub-categories of the given category types (SummaryCategory constants), every type when empty. */
    public function subCategories(int ...$categoryTypes): \Generator
    {
        foreach ($this->categories as $category) {
            if ($categoryTypes === [] || \in_array($category->categoryType, $categoryTypes, true)) {
                foreach ($category->subCategories as $sub) {
                    yield $sub;
                }
            }
        }
    }
}

/** One paragraph of the neighbourhood text, in one language. Sentences keep the `[STRONG]...[/STRONG]` markers. */
final class TextParagraph
{
    /**
     * @param string[] $sentences
     * @param string[] $items
     */
    public function __construct(
        /** Stable type: education, shopping, publictransports, transports, tourism, cities. */
        public readonly string $iconId,
        public readonly string $title,
        /** Title naming the street, when known. */
        public readonly ?string $titleStreet,
        /** Title naming the city, when known. */
        public readonly ?string $titleCity,
        public readonly array $sentences,
        public readonly array $items,
    ) {
    }

    public function text(): string
    {
        return implode(' ', $this->sentences);
    }
}

/** GET /Summary/text resolved to one language (the requested one, else English, else the first one). */
final class SummaryText
{
    /** @param TextParagraph[] $paragraphs */
    public function __construct(
        public readonly string $language,
        public readonly array $paragraphs,
    ) {
    }

    /**
     * @param array<string, mixed> $w The API answer, every language at once
     */
    public static function fromWire(array $w, string $language): self
    {
        $language = strtoupper($language);
        $used = null;
        $paragraphs = [];
        foreach ($w['Paragraphs'] ?? [] as $p) {
            $lang = self::pick($p['Title'] ?? [], $language);
            if ($lang === null) {
                continue;
            }
            $used ??= $lang;
            $sentences = array_values(array_filter(array_map(fn ($s) => \is_array($s) ? ($s[$lang] ?? null) : null, $p['Sentences'] ?? [])));
            $items = array_values(array_filter(array_map(fn ($s) => \is_array($s) ? ($s[$lang] ?? null) : null, $p['List'] ?? [])));
            if ($sentences === [] && $items === []) {
                continue;
            }
            $paragraphs[] = new TextParagraph(
                (string) ($p['IconId'] ?? ''), (string) ($p['Title'][$lang] ?? ''),
                $p['TitleBis'][$lang] ?? null, $p['TitleTer'][$lang] ?? null, $sentences, $items,
            );
        }

        return new self($used ?? $language, $paragraphs);
    }

    /** @param array<string, string> $localized */
    private static function pick(array $localized, string $language): ?string
    {
        foreach ([$language, 'EN'] as $candidate) {
            if (!empty($localized[$candidate])) {
                return $candidate;
            }
        }
        $first = array_key_first($localized);

        return $first === null ? null : (string) $first;
    }

    /** Every paragraph's text, separated by blank lines, markers included. */
    public function text(): string
    {
        return implode("\n\n", array_map(fn (TextParagraph $p) => $p->text(), $this->paragraphs));
    }
}

/** One score of a category, 0 to 10. */
final class Score
{
    /** @param int[] $subTypes */
    public function __construct(
        /** Stable key: publicTransport, trains, motorways, nurseries, schools, supermarkets... */
        public readonly string $key,
        public readonly string $label,
        public readonly float $value,
        public readonly ?int $iconId,
        public readonly ?int $categoryType,
        public readonly array $subTypes,
    ) {
    }
}

/** GET /scores. */
final class Scores
{
    /** @param Score[] $scores */
    public function __construct(public readonly array $scores, public readonly ?string $language)
    {
    }

    /** @param array<string, mixed> $w */
    public static function fromWire(array $w): self
    {
        $scores = [];
        foreach ($w['scores'] ?? [] as $s) {
            $scores[] = new Score((string) ($s['k'] ?? ''), (string) ($s['l'] ?? ''), (float) ($s['v'] ?? 0), isset($s['iconId']) ? (int) $s['iconId'] : null, isset($s['pt']) ? (int) $s['pt'] : null, $s['st'] ?? []);
        }

        return new self($scores, $w['language'] ?? null);
    }
}

/** One point of interest of GET /points. */
final class Poi
{
    /**
     * @param string[] $iconIds
     * @param string[] $subIconIds
     */
    public function __construct(
        public readonly string $name,
        /** Translated type, for example "Preschool" or "Bus stop (Dansaert)". */
        public readonly string $type,
        public readonly float $latitude,
        public readonly float $longitude,
        /** Category id, the value to pass in `$poiTypeIds`. */
        public readonly string $categoryId,
        public readonly array $iconIds,
        public readonly array $subIconIds,
        public readonly bool $grouped,
        /** Specific data as a JSON string (transit lines, brand...), "{}" when empty. */
        public readonly ?string $specificData,
    ) {
    }

    /** @param array<string, mixed> $w */
    public static function fromWire(array $w): self
    {
        $split = fn ($v) => array_values(array_filter(explode(',', (string) ($v ?? ''))));

        return new self((string) $w['n'], (string) ($w['t'] ?? ''), (float) $w['la'], (float) $w['ln'], (string) ($w['p'] ?? ''), $split($w['i'] ?? ''), $split($w['si'] ?? ''), (bool) ($w['g'] ?? false), $w['sd'] ?? null);
    }
}

/** A geocoded place (GET /geolocation). */
final class Place
{
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $street,
        public readonly ?string $houseNumber,
        public readonly ?string $postcode,
        public readonly ?string $city,
        public readonly ?string $country,
        public readonly ?string $countryCode,
        public readonly ?string $type,
        public readonly float $latitude,
        public readonly float $longitude,
        /** "Rue de la Loi 16, 1000 Brussels" style single line. */
        public readonly string $label,
    ) {
    }

    /** @param array<string, mixed> $f A GeoJSON feature */
    public static function fromFeature(array $f): ?self
    {
        $coordinates = $f['geometry']['coordinates'] ?? null;
        if (!\is_array($coordinates) || \count($coordinates) < 2) {
            return null;
        }
        $p = $f['properties'] ?? [];
        $streetLine = implode(' ', array_filter([$p['street'] ?? $p['name'] ?? null, $p['housenumber'] ?? null]));
        $cityLine = implode(' ', array_filter([$p['postcode'] ?? null, $p['city'] ?? null]));

        return new self(
            $p['name'] ?? null, $p['street'] ?? null, $p['housenumber'] ?? null, $p['postcode'] ?? null, $p['city'] ?? null,
            $p['country'] ?? null, $p['countrycode'] ?? null, $p['type'] ?? null, (float) $coordinates[1], (float) $coordinates[0],
            implode(', ', array_filter([$streetLine, $cityLine])),
        );
    }
}

/** One path of GET /route. */
final class RoutePath
{
    /**
     * @param array<string, mixed> $geometry GeoJSON LineString, coordinates as [longitude, latitude]
     * @param array<int, mixed>|null $instructions
     */
    public function __construct(
        public readonly float $distanceMeters,
        public readonly int $durationSeconds,
        public readonly array $geometry,
        public readonly ?array $instructions,
    ) {
    }
}

/** GET /route. */
final class Route
{
    /** @param RoutePath[] $paths */
    public function __construct(public readonly array $paths, public readonly mixed $info)
    {
    }

    /** @param array<string, mixed> $w */
    public static function fromWire(array $w): self
    {
        $paths = [];
        foreach ($w['paths'] ?? [] as $p) {
            $paths[] = new RoutePath((float) ($p['distance'] ?? 0), (int) round(((int) ($p['time'] ?? 0)) / 1000), $p['points'] ?? ['type' => 'LineString', 'coordinates' => []], $p['instructions'] ?? null);
        }

        return new self($paths, $w['info'] ?? null);
    }
}

/** One reachable area; `$geometry` is a GeoJSON Polygon or MultiPolygon. */
final class Isochrone
{
    /** @param array<string, mixed> $geometry */
    public function __construct(public readonly string $label, public readonly array $geometry)
    {
    }
}

/** Category ids grouped by family (Education, Transports, Shopping...), the values of `$poiTypeIds`. */
final class CategoryGroup
{
    /** @param int[] $poiTypeIds */
    public function __construct(public readonly string $name, public readonly array $poiTypeIds)
    {
    }
}
