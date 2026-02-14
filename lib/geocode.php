<?php
/**
 * Reverse geocoding: get road/address from lat,lng via Nominatim
 */

require_once __DIR__ . '/../config.php';

/** Build address from components, skipping suburb/neighbourhood which are often incorrect. */
function buildAddressFromComponents(array $addr): string {
    $parts = [];
    $road = $addr['road'] ?? $addr['street'] ?? $addr['pedestrian'] ?? $addr['footway'] ?? null;
    if ($road) {
        $house = $addr['house_number'] ?? null;
        $parts[] = $house ? $house . ' ' . $road : $road;
    }
    $locality = $addr['village'] ?? $addr['hamlet'] ?? $addr['town'] ?? $addr['city'] ?? $addr['municipality'] ?? $addr['county'] ?? null;
    if ($locality) $parts[] = $locality;
    $postcode = $addr['postcode'] ?? null;
    if ($postcode) $parts[] = $postcode;
    $country = $addr['country'] ?? null;
    if ($country) $parts[] = $country;
    return implode(', ', $parts);
}

function getGeocodeData(?float $lat, ?float $lng): ?array {
    if ($lat === null || $lng === null) return null;
    $key = round($lat, 5) . '_' . round($lng, 5);
    $cacheDir = CACHE_DIR . '/geocode/v2';
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);
    $safeKey = str_replace(['.', '-'], ['p', 'm'], $key);
    $cacheFile = $cacheDir . '/' . $safeKey . '.json';
    if (file_exists($cacheFile)) {
        return json_decode(file_get_contents($cacheFile), true);
    }
    $url = 'https://nominatim.openstreetmap.org/reverse?format=json&lat=' . urlencode((string)$lat) . '&lon=' . urlencode((string)$lng) . '&addressdetails=1&zoom=18';
    $ctx = stream_context_create([
        'http' => [
            'header' => "User-Agent: FieldReports/1.0\r\nAccept-Language: en\r\n",
            'timeout' => 5,
        ],
    ]);
    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return null;
    $data = json_decode($json, true);
    if (!$data) return null;
    $addr = $data['address'] ?? [];
    $road = $addr['road'] ?? $addr['street'] ?? $addr['pedestrian'] ?? $addr['footway'] ?? null;
    if ($road) {
        $house = $addr['house_number'] ?? null;
        $road = $house ? $house . ' ' . $road : $road;
    }
    if (!$road) $road = $data['display_name'] ?? null;
    // Build address from trusted components only (skip suburb/neighbourhood which can be wrong)
    $address = buildAddressFromComponents($addr);
    if (!$address) $address = $data['display_name'] ?? null;
    $cached = ['road' => $road, 'address' => $address];
    file_put_contents($cacheFile, json_encode($cached));
    return $cached;
}

function getRoadName(?float $lat, ?float $lng): ?string {
    $d = getGeocodeData($lat, $lng);
    return $d['road'] ?? null;
}

function getAddress(?float $lat, ?float $lng): ?string {
    $d = getGeocodeData($lat, $lng);
    return $d['address'] ?? null;
}
