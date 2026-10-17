<?php
declare(strict_types=1);
namespace Wellness\Service;
use Wellness\Http\ApiException;

final class PublicSocialLinks
{
    public static function validate(mixed $links): array
    {
        $invalid = static fn() => new ApiException(422,'validation_error','Enter up to 20 public social links with a platform name and HTTPS URL.',['social_links'=>'Invalid public social links']);
        if (!is_array($links)||!array_is_list($links)||count($links)>20) throw $invalid();
        $result=[];$seen=[];
        foreach ($links as $link) {
            if (!is_array($link)||array_diff(array_keys($link),['label','url'])||!is_string($link['label']??null)||!is_string($link['url']??null)) throw $invalid();
            $label=trim($link['label']);$url=trim($link['url']);$parts=parse_url($url);
            if ($label===''||strlen($label)>60||preg_match('/[\x00-\x1f\x7f]/',$label)||strlen($url)>2048||filter_var($url,FILTER_VALIDATE_URL)===false||!is_array($parts)||strtolower($parts['scheme']??'')!=='https'||isset($parts['user'])||isset($parts['pass'])||isset($seen[$url])) throw $invalid();
            $seen[$url]=true;$result[]=['label'=>$label,'url'=>$url];
        }
        return $result;
    }
    public static function decode(?string $json): array
    {
        return $json===null||$json===''?[]:self::validate(json_decode($json,true,32,JSON_THROW_ON_ERROR));
    }
}
