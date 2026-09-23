<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use Wellness\Service\CatalogService;

$raw=json_encode([
    ['id'=>9,'minutes'=>90,'price_cents'=>16000],
    ['id'=>6,'minutes'=>60,'price_cents'=>12000],
    ['id'=>12,'minutes'=>120,'price_cents'=>20000],
],JSON_THROW_ON_ERROR);
$sorted=(new ReflectionMethod(CatalogService::class,'sortedDurations'))->invoke(null,$raw);
if(array_column($sorted,'minutes')!==[60,90,120]||array_column($sorted,'id')!==[6,9,12])throw new RuntimeException('Catalogue duration order is incorrect.');
echo "Catalogue duration order passed.\n";
