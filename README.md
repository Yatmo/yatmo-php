# yatmo/yatmo-php

Add real estate maps, points of interest and neighbourhood data to PHP applications.
`yatmo/yatmo-php` is the official PHP client for the [Yatmo](https://yatmo.com) API: the written neighbourhood text
of a property (rendered on the server, so search engines index it), the nearest schools, nurseries, supermarkets and
public transport with real travel times, scores, listing enrichment, isochrones, routes, geocoding and static map
images, in 25 countries and 23 languages. Works with Laravel, Symfony, WordPress, Drupal and plain PHP 8.1 and later,
with no dependency.

```bash
composer require yatmo/yatmo-php
```

```php
use Yatmo\Client;
use Yatmo\TextRenderer;

$yatmo = new Client($_ENV['YATMO_KEY'], 'BE', 'FR');

// The neighbourhood text as HTML, to write into the property page.
$text = $yatmo->summaryText(50.8461, 4.3664);
echo TextRenderer::html($text);
// <div class="yatmo-text"><h3>Commerces près de la Rue de la Loi</h3><p>Un <strong>Carrefour</strong> à 3 minutes...

// The nearest places by category with walking times.
$summary = $yatmo->summary(50.8461, 4.3664);
foreach ($summary->subCategories() as $sub) {
    $place = $sub->places[0] ?? null;
    echo $sub->label, ': ', $place?->name, ' ', $place?->travel(\Yatmo\TravelMode::Walking)?->travelTimeShortLabel, "\n";
}
```

<p align="center">
  <img src="https://raw.githubusercontent.com/Yatmo/.github/main/profile/img/neighbourhood-text.png" width="720" alt="The Yatmo neighbourhood text on a property page">
</p>

## Install in 5 minutes

1. Get a Yatmo licence key at [yatmo.com](https://yatmo.com) and use the **backend** key on the server
   ([keys explained](https://documentation.yatmo.com/license)).
2. `composer require yatmo/yatmo-php`.
3. Create one `Client` per country, call `summaryText()` and `summary()` for each property, cache the answers (a
   neighbourhood rarely changes) and write the HTML into the page.

Runnable example: [examples/property-page.php](examples/property-page.php). The interactive map itself is an iframe
with your frontend key, no PHP needed: [iframe plugin](https://documentation.yatmo.com/plugins/iframe).

## Client

```php
$yatmo = new Client(
    key: 'your_backend_key',
    country: 'BE',                 // the API host is https://be.yatmo.com/
    language: 'FR',                // labels and texts, default EN
    transport: null,               // optional Yatmo\Transport\TransportInterface (cURL by default, streams without cURL)
    timeoutSeconds: 15,
);
```

| Method | Endpoint | Returns |
|---|---|---|
| `summary($lat, $lng)` | `/summary` | `Summary`: places by category with distances and travel times (walking, bicycling, driving, transit), closest cities, resolved address |
| `summaryText($lat, $lng, $language = null)` | `/Summary/text` | `SummaryText`: the neighbourhood paragraphs in one language (EN fallback), with `[STRONG]` markers around key places |
| `summaryTextRaw($lat, $lng)` | `/Summary/text` | The same in every language of the country, as an array, to cache |
| `scores($lat, $lng)` | `/scores` | `Scores`: one 0 to 10 score per category |
| `enrichment($lat, $lng)` | `/enrichment` | The nearest place of each category with distances and times, as an array, for listing data |
| `points($south, $west, $north, $east, $poiTypeIds = [])` | `/points` | `Poi[]` in a bounding box |
| `simplifiedCategories()` | `/SimplifiedCategories` | `CategoryGroup[]`: category ids by family, the values of `$poiTypeIds` |
| `isochrones($lat, $lng, TravelMode $mode)` | `/Isochrone/GetMultipleTimes` | `Isochrone[]`: the 5, 10 and 20 minute areas (GeoJSON) |
| `isochrone($lat, $lng, $mode, $seconds)` | `/isochrone` | One area for any duration, as a GeoJSON array |
| `route($fromLat, $fromLng, $toLat, $toLng, $mode)` | `/route` | `Route`: distance, duration and LineString |
| `geocode($address)` | `/geolocation` | `Place[]`, best match first, inside the country |
| `geocodeNear($lat, $lng, $query)` | `/Geolocation/GetClose` | `Place[]`: autocomplete around a point |
| `staticMap($lat, $lng, $options)` | `/image` | JPEG bytes of the map centred on the property (colour, size, `MapStyle`, 3D, borders, custom marker) |

Every call throws a `Yatmo\Exception\YatmoException` carrying the HTTP `$status`, with typed subclasses:
`BadRequestException` (400, point outside the country), `AuthenticationException` (401), `ForbiddenException`
(403, country or feature not in the licence), `QuotaException` (429) and `TransportException` (network).

## Rendering the text

```php
TextRenderer::html($text, [
    'heading' => 'h3',             // h2 to h6, or null for no titles
    'titles' => 'street-city',     // street in the first title and city in the second (default), 'city' for discreet listings, 'generic'
    'paragraphs' => ['education', 'shopping', 'publictransports', 'transports', 'tourism', 'cities'],  // which ones, in this order
    'strong' => true,              // key places in <strong>
    'class' => 'my-text',          // extra class on the wrapper
]);
TextRenderer::markdown($text);
TextRenderer::plain($text);
```

## Laravel

```php
// config/services.php
'yatmo' => ['key' => env('YATMO_KEY'), 'country' => env('YATMO_COUNTRY', 'BE')],

// app/Providers/AppServiceProvider.php
$this->app->singleton(\Yatmo\Client::class, fn () => new \Yatmo\Client(config('services.yatmo.key'), config('services.yatmo.country'), app()->getLocale()));

// app/Http/Controllers/ListingController.php
public function show(Listing $listing, \Yatmo\Client $yatmo)
{
    $text = Cache::remember("yatmo:text:{$listing->id}", now()->addDays(30), fn () => $yatmo->summaryText($listing->latitude, $listing->longitude));
    return view('listings.show', ['listing' => $listing, 'neighbourhood' => \Yatmo\TextRenderer::html($text)]);
}
```

```blade
{{-- resources/views/listings/show.blade.php --}}
<iframe src="https://map.yatmo.com/plugin.html?licenseKey={{ config('services.yatmo.frontend_key') }}&country=BE&language=FR&latitude={{ $listing->latitude }}&longitude={{ $listing->longitude }}&mode=overlay" style="width:100%;height:560px;border:0" loading="lazy"></iframe>
{!! $neighbourhood !!}
```

Symfony works the same way: register the client as a service and render the text in Twig with `|raw`.

## More

[Examples](https://github.com/Yatmo/yatmo-examples) · [API documentation](https://documentation.yatmo.com/api) ·
[WordPress plugin](https://wordpress.org/plugins/yatmo-map/) · [JavaScript packages](https://github.com/Yatmo/yatmo-sdk-js)

MIT licence. Yatmo is a paid service for real estate portals, agency networks and developers; a licence key is required.
