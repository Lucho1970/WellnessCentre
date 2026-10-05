<?php
declare(strict_types=1);

function wellnessCleanMeta(mixed $value): string
{
    $value = strip_tags((string) ($value ?? ''));
    return trim((string) preg_replace('/\s+/u', ' ', $value));
}

function wellnessRenderMeta(string $html, string $title, string $description, string $url, string $clinic, ?string $image): string
{
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if (function_exists('mb_substr')) $description = mb_substr($description, 0, 240, 'UTF-8');
    $meta = [
        '<link rel="canonical" href="' . $escape($url) . '">',
        '<meta name="description" content="' . $escape($description) . '">',
        '<meta property="og:type" content="website">',
        '<meta property="og:site_name" content="' . $escape($clinic) . '">',
        '<meta property="og:title" content="' . $escape($title) . '">',
        '<meta property="og:description" content="' . $escape($description) . '">',
        '<meta property="og:url" content="' . $escape($url) . '">',
        '<meta name="twitter:card" content="summary">',
        '<meta name="twitter:title" content="' . $escape($title) . '">',
        '<meta name="twitter:description" content="' . $escape($description) . '">',
    ];
    if ($image !== null) {
        $meta[] = '<meta property="og:image" content="' . $escape($image) . '">';
        $meta[] = '<meta name="twitter:image" content="' . $escape($image) . '">';
    }
    $html = (string) preg_replace('~<title>.*?</title>~is', '<title>' . $escape($title) . '</title>', $html, 1);
    return str_replace('</head>', implode("\n", $meta) . "\n</head>", $html);
}

function wellnessShareMeta(string $html, array $service, array $site, string $origin, ?int $minutes): string
{
    $clinic = wellnessCleanMeta($site['name'] ?? 'Wellness Centre');
    $name = wellnessCleanMeta($service['name'] ?? 'Service');
    $slug = (string) ($service['slug'] ?? '');
    $chosen = null;
    foreach (($service['durations'] ?? []) as $duration) {
        if ($minutes !== null && (int) ($duration['minutes'] ?? 0) === $minutes) {
            $chosen = $duration;
            break;
        }
    }
    $prefix = $chosen === null ? '' : $minutes . ' min ';
    $title = $prefix . $name . ' | ' . $clinic;
    $description = wellnessCleanMeta($service['public_summary'] ?? null) ?: wellnessCleanMeta($service['description'] ?? null);
    if ($description === '') $description = 'Explore appointment options and available times at ' . $clinic . '.';
    $url = rtrim($origin, '/') . '/services/' . rawurlencode($slug) . '/book';
    if ($chosen !== null) $url .= '?duration=' . $minutes;
    $image = empty($site['logo_version']) ? null : rtrim($origin, '/') . '/api/v1/brand/logo?v=' . rawurlencode((string) $site['logo_version']);
    return wellnessRenderMeta($html, $title, $description, $url, $clinic, $image);
}

function wellnessPractitionerMeta(string $html, array $person, array $site, string $origin): string
{
    $clinic = wellnessCleanMeta($site['name'] ?? 'Wellness Centre');
    $name = wellnessCleanMeta($person['public_name'] ?? 'Practitioner');
    $title = $name . ' | ' . $clinic;
    $description = wellnessCleanMeta($person['summary'] ?? null);
    if ($description === '') $description = 'Explore ' . $name . "'s treatments and appointment options at " . $clinic . '.';
    $url = rtrim($origin, '/') . '/practitioners/' . rawurlencode((string) ($person['slug'] ?? ''));
    $image = !empty($person['has_image'])
        ? rtrim($origin, '/') . '/api/v1/team/' . rawurlencode((string) $person['slug']) . '/image?v=' . rawurlencode((string) ($person['image_version'] ?? ''))
        : (empty($site['logo_version']) ? null : rtrim($origin, '/') . '/api/v1/brand/logo?v=' . rawurlencode((string) $site['logo_version']));
    return wellnessRenderMeta($html, $title, $description, $url, $clinic, $image);
}
