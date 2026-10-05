<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Wellness\Http\ApiException;
use Wellness\Service\PublicCardContact;

$empty = PublicCardContact::fromInput([]);
if ($empty !== ['email' => null, 'phone' => null, 'sms' => 0, 'website' => null]) throw new RuntimeException('Private staff contacts must not be published by default.');
$public = PublicCardContact::fromInput(['public_contact_email' => 'Care@Example.test', 'public_contact_phone' => '+12892975234', 'public_contact_sms' => true]);
if ($public !== ['email' => 'care@example.test', 'phone' => '+12892975234', 'sms' => 1, 'website' => null]) throw new RuntimeException('Public contact opt-in was not normalized.');
foreach ([
    ['public_contact_email' => 'not-an-email'],
    ['public_contact_phone' => '2892975234'],
    ['public_contact_sms' => true],
    ['public_website_url' => 'javascript:alert(1)'],
    ['public_website_url' => 'https://user:password@example.test/'],
    ['public_website_url' => 'facebook.com/example'],
    ['public_website_url' => ['https://example.test/']],
    ['public_website_url' => 'https://example.test/' . str_repeat('a', 2048)],
] as $invalid) {
    try { PublicCardContact::fromInput($invalid); throw new RuntimeException('Invalid public contact was accepted.'); }
    catch (ApiException) {}
}
foreach (['https://example.test/', 'https://www.facebook.com/example?ref=profile', 'http://example.test/'] as $url) {
    if (PublicCardContact::fromInput(['public_website_url' => ' ' . $url . ' '])['website'] !== $url) throw new RuntimeException('Website URL was not preserved.');
}
if (PublicCardContact::fromInput(['public_website_url' => ' '])['website'] !== null) throw new RuntimeException('Website link could not be cleared.');
echo "Practitioner public card contact tests passed.\n";
