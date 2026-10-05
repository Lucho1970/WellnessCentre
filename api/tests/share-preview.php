<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/hosting/netfirms/portal/share-preview-lib.php';

$shell = '<!doctype html><html><head><title>Wellness Centre</title></head><body><div id="root"></div></body></html>';
$service = [
    'slug' => 'massage-session',
    'name' => 'Massage & Bodywork',
    'public_summary' => 'Relax <strong>safely</strong> & recharge.',
    'durations' => [['minutes' => 60, 'price_cents' => 12000], ['minutes' => 90, 'price_cents' => 16000]],
];
$site = ['name' => 'Willow Wellness Centre', 'logo_version' => 'hash-1'];

$withDuration = wellnessShareMeta($shell, $service, $site, 'https://portal.example.test', 60);
if (!str_contains($withDuration, '<title>60 min Massage &amp; Bodywork | Willow Wellness Centre</title>')) throw new RuntimeException('Duration title was not rendered or escaped.');
if (!str_contains($withDuration, 'content="Relax safely &amp; recharge."')) throw new RuntimeException('Public description was not sanitized.');
if (!str_contains($withDuration, 'https://portal.example.test/services/massage-session/book?duration=60')) throw new RuntimeException('Duration link was not preserved.');
if (!str_contains($withDuration, 'https://portal.example.test/api/v1/brand/logo?v=hash-1')) throw new RuntimeException('Public brand logo was not linked.');
if (!str_contains($withDuration, '<div id="root"></div>')) throw new RuntimeException('Portal shell was changed.');

$unknownDuration = wellnessShareMeta($shell, $service, $site, 'https://portal.example.test', 120);
if (str_contains($unknownDuration, 'duration=120') || str_contains($unknownDuration, '120 min')) throw new RuntimeException('Unknown duration was advertised.');

$person = [
    'slug' => 'alex-doe',
    'public_name' => 'Alex "AJ" Doe',
    'summary' => 'Registered massage therapist <script>alert(1)</script>',
    'has_image' => true,
    'image_version' => 'image-hash',
];
$profile = wellnessPractitionerMeta($shell, $person, $site, 'https://portal.example.test');
if (!str_contains($profile, '<title>Alex &quot;AJ&quot; Doe | Willow Wellness Centre</title>')) throw new RuntimeException('Published practitioner title was not escaped.');
if (!str_contains($profile, 'https://portal.example.test/practitioners/alex-doe')) throw new RuntimeException('Practitioner canonical link is missing.');
if (!str_contains($profile, 'https://portal.example.test/api/v1/team/alex-doe/image?v=image-hash')) throw new RuntimeException('Published profile image was not linked.');
if (str_contains($profile, '<script>alert(1)</script>')) throw new RuntimeException('Profile summary HTML was not sanitized.');

echo "share-preview: ok\n";
