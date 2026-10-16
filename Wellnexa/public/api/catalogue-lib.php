<?php
declare(strict_types=1);

/** Public presentation fields only. Never expose a general-purpose API proxy. */
function wellnexaCatalogueProjection(string $resource, array $rows): array
{
    if (!in_array($resource, ['services', 'practitioners'], true) || !array_is_list($rows) || count($rows) > 500) {
        throw new RuntimeException('Invalid public catalogue.');
    }
    $fields = $resource === 'services'
        ? ['slug', 'name', 'name_fr', 'public_summary', 'public_summary_fr', 'category', 'category_fr', 'durations']
        : ['slug', 'public_name', 'public_title', 'public_title_fr', 'summary', 'summary_fr', 'discipline', 'credentials', 'booking_practitioner_id', 'services', 'has_image', 'image_version'];
    $result = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !is_string($row['slug'] ?? null) || !preg_match('/^[a-z0-9][a-z0-9-]{0,119}$/D', $row['slug'])) {
            throw new RuntimeException('Invalid public catalogue item.');
        }
        $item = array_intersect_key($row, array_flip($fields));
        foreach ($item as $key => $value) {
            if (!in_array($key, ['durations', 'services', 'booking_practitioner_id', 'has_image'], true) && $value !== null && !is_string($value)) {
                throw new RuntimeException('Invalid public text field.');
            }
        }
        $nameKey = $resource === 'services' ? 'name' : 'public_name';
        if (!is_string($item[$nameKey] ?? null) || trim($item[$nameKey]) === '') throw new RuntimeException('Missing public name.');
        if ($resource === 'services') {
            if (!is_array($item['durations'] ?? null) || !array_is_list($item['durations'])) throw new RuntimeException('Invalid durations.');
            $item['durations'] = array_map(static function ($duration): array {
                if (!is_array($duration)) throw new RuntimeException('Invalid duration.');
                $minutes = filter_var($duration['minutes'] ?? null, FILTER_VALIDATE_INT);
                $price = filter_var($duration['price_cents'] ?? null, FILTER_VALIDATE_INT);
                if ($minutes === false || $minutes < 1 || $price === false || $price < 0) throw new RuntimeException('Invalid duration or price.');
                return ['minutes' => $minutes, 'price_cents' => $price];
            }, $item['durations']);
        } else {
            $hasImage = $item['has_image'] ?? false;
            if (!in_array($hasImage, [true, false, 0, 1, '0', '1'], true)) throw new RuntimeException('Invalid public photo flag.');
            $item['has_image'] = (bool)$hasImage;
            $id = $item['booking_practitioner_id'] ?? null;
            if ($id !== null) {
                $id = filter_var($id, FILTER_VALIDATE_INT);
                if ($id === false || $id < 1) throw new RuntimeException('Invalid booking identifier.');
            }
            $item['booking_practitioner_id'] = $id;
            if (!is_array($item['services'] ?? null) || !array_is_list($item['services'])) throw new RuntimeException('Invalid practitioner services.');
            $item['services'] = array_map(static function ($service): array {
                if (!is_array($service) || !is_string($service['slug'] ?? null) || !preg_match('/^[a-z0-9][a-z0-9-]{0,119}$/D', $service['slug']) || !is_string($service['name'] ?? null)) throw new RuntimeException('Invalid practitioner service.');
                return array_intersect_key($service, array_flip(['slug', 'name']));
            }, $item['services']);
        }
        $result[] = $item;
    }
    return $result;
}
