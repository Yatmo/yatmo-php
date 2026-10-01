# Changelog

## 1.1.0

`summaryRaw()` and `scoresRaw()` return the API answer as plain arrays, like `summaryTextRaw()`, so a cache
that only unserializes an allow-list of classes (Laravel 13) can store them; rebuild with `Summary::fromWire()` and
`Scores::fromWire()`. Used by `yatmo/laravel`.

## 1.0.0

First release: typed client for summary, neighbourhood text (every language or resolved, with HTML, Markdown and plain
rendering), scores, listing enrichment, points, isochrones, routes, geocoding, simplified categories and static maps.
PHP 8.1 and later, no dependency (cURL or stream transport, any client through TransportInterface).
