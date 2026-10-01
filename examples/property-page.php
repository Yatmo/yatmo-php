<?php
// A property page in plain PHP: the neighbourhood text rendered on the server (indexable) and the
// nearest places with walking times. Run: YATMO_KEY=... php examples/property-page.php > page.html
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yatmo\Client;
use Yatmo\Model\SummaryCategory;
use Yatmo\TextRenderer;
use Yatmo\TravelMode;

$yatmo = new Client(getenv('YATMO_KEY') ?: 'YOUR_BACKEND_KEY', 'BE', 'EN');
$latitude = 50.8461;
$longitude = 4.3664;

// Cache both answers per listing (a neighbourhood rarely changes): APCu, Redis, your database...
$text = $yatmo->summaryText($latitude, $longitude);
$summary = $yatmo->summary($latitude, $longitude);

echo "<h2>Living here</h2>\n";
echo TextRenderer::html($text, ['heading' => 'h3', 'titles' => 'street-city']) . "\n";

echo "<h2>Around the property</h2>\n";
foreach ($summary->subCategories(SummaryCategory::EDUCATION, SummaryCategory::SHOPPING, SummaryCategory::TRANSPORT) as $sub) {
    $place = $sub->places[0] ?? null;
    if ($place === null) {
        continue;
    }
    $walk = $place->travel(TravelMode::Walking);
    printf("<h3>%s</h3><p>%s%s</p>\n", htmlspecialchars($sub->singularLabel ?? $sub->label), htmlspecialchars($place->name), $walk ? ' (' . htmlspecialchars($walk->travelTimeShortLabel ?? '') . ' on foot)' : '');
}
