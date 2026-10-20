<?php

declare(strict_types=1);

namespace UniScript\Integration;

use InvalidArgumentException;

final class StaticUi
{
    public static function inspect(string $root, array $manifest): array
    {
        if (!array_key_exists('ui', $manifest)) { return ['sha256' => hash('sha256',''), 'files' => []]; }
        $ui=$manifest['ui'];
        if (($manifest['manifest_version']??null)!==2 || !is_array($ui) || array_diff(array_keys($ui),['profile','entrypoint'])!==[] || ($ui['profile']??null)!=='static-v1' || !is_string($ui['entrypoint']??null) || !str_ends_with($ui['entrypoint'],'.html')) { throw new InvalidArgumentException('ui_contract'); }
        self::path($ui['entrypoint']);
        $base=realpath($root.'/ui');if($base===false||is_link($root.'/ui')||!is_file($root.'/'.$ui['entrypoint'])){throw new InvalidArgumentException('ui_entrypoint');}
        $files=[];$bytes=0;
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base,\FilesystemIterator::SKIP_DOTS)) as $file){
            $relative=substr($file->getPathname(),strlen($base)+1);self::path('ui/'.$relative);
            if(!$file->isFile()||$file->isLink()||($bytes+=$file->getSize())>2097152||count($files)>=100){throw new InvalidArgumentException('ui_bounds');}
            $files[$relative]=hash_file('sha256',$file->getPathname());
        }
        if($files===[]){throw new InvalidArgumentException('ui_empty');}ksort($files,SORT_STRING);
        return ['sha256'=>hash('sha256',json_encode($files,JSON_THROW_ON_ERROR)),'files'=>$files];
    }

    private static function path(string $path): void
    {
        if(!preg_match('/\Aui\/(?:[A-Za-z0-9][A-Za-z0-9_-]*\/)*[A-Za-z0-9][A-Za-z0-9_-]*\.(?:html|js|css)\z/D',$path)){throw new InvalidArgumentException('ui_path');}
    }
}
