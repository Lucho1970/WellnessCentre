param([Parameter(Mandatory=$true)][string]$ReleaseDirectory)
$ErrorActionPreference='Stop'
$repo=Split-Path $PSScriptRoot -Parent
$release=(Resolve-Path -LiteralPath $ReleaseDirectory).Path
$manifest=Get-Content -LiteralPath (Join-Path $release 'manifest.json') -Raw | ConvertFrom-Json
if($manifest.source_commit -notmatch '^[a-f0-9]{40}$'){throw 'Missing exact source revision.'}
git -C $repo cat-file -e ($manifest.source_commit+'^{commit}')
if($LASTEXITCODE -ne 0){throw 'Release source revision is unavailable.'}
$expected=@('wellness-public.zip','wellness-common-portal.zip','copihue-portal-landing.zip','wellness-api-private.zip','wellness-api-public.zip','tuff-tar-mail-bridge.zip','wellnexa-website.zip')
if(@(Compare-Object $expected @($manifest.archives.file)).Count){throw 'Unexpected archive set.'}
Add-Type -AssemblyName System.IO.Compression.FileSystem
$checks=0;$entries=0;$scanned=0
$signature='-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----|gh[pousr]_[A-Za-z0-9]{30,}|AKIA[A-Z0-9]{16}|AccountKey=[A-Za-z0-9+/]{40,}'
$hashBytes={param([byte[]]$bytes) $sha=[Security.Cryptography.SHA256]::Create();try{[BitConverter]::ToString($sha.ComputeHash($bytes)).Replace('-','').ToLowerInvariant()}finally{$sha.Dispose()}}
$entryBytes={param($entry) $stream=$entry.Open();$memory=[IO.MemoryStream]::new();try{$stream.CopyTo($memory);return ,$memory.ToArray()}finally{$stream.Dispose();$memory.Dispose()}}
foreach($record in $manifest.archives){
    $path=Join-Path $release $record.file
    if((Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant() -ne $record.sha256){throw "Checksum mismatch: $($record.file)"}
    $checks++
    $zip=[IO.Compression.ZipFile]::OpenRead($path)
    try {
        if($zip.Entries.Count -ne $record.entries){throw "Entry count mismatch: $($record.file)"}
        $names=@{}
        foreach($entry in $zip.Entries){
            $name=$entry.FullName
            $fontMap=$record.file -eq 'wellness-api-private.zip' -and $name -match '^vendor/dompdf/php-font-lib/maps/[a-zA-Z0-9_-]+\.map$'
            if($name -match '(^|/)\.\.(/|$)|^/|^[a-zA-Z]:|\\|(^|/)(\.env[^/]*|\.git|node_modules)(/|$)' -or ($name -match '\.map$' -and -not $fontMap)){throw "Unsafe/private/development entry in $($record.file): $name"}
            if($names.ContainsKey($name)){throw "Duplicate archive entry: $name"};$names[$name]=$true
            $entries++
            if($name -match '\.(php|js|mjs|json|html|css|sql|md|txt|xml|pem|key|crt|cer|yml|yaml)$|(^|/)\.htaccess$'){
                $text=[Text.Encoding]::UTF8.GetString((& $entryBytes $entry))
                if($text -match $signature){throw "Credential signature candidate in $($record.file): $name"}
                $scanned++
            }
        }
        if($record.file -eq 'wellness-api-private.zip'){
            foreach($source in Get-ChildItem (Join-Path $repo 'api/src'),(Join-Path $repo 'api/bin') -Recurse -File){
                $relative=$source.FullName.Substring((Join-Path $repo 'api').Length+1).Replace('\','/')
                $entry=$zip.GetEntry($relative)
                if($null -eq $entry -or (& $hashBytes (& $entryBytes $entry)) -ne (Get-FileHash -LiteralPath $source.FullName -Algorithm SHA256).Hash.ToLowerInvariant()){throw "Private source mismatch: $relative"}
                $checks++
            }
            foreach($required in @('vendor/autoload.php','vendor/composer/installed.json','composer.lock','src/PilotPolicy.php','bin/pilot-preflight.php')){if($null -eq $zip.GetEntry($required)){throw "Missing private application file: $required"};$checks++}
            if(@($zip.Entries | Where-Object {$_.FullName -match '^(tests|database|var|public)/'}).Count){throw 'Unexpected first-party fixture/data/public directory in private ZIP.'}
        }
        $buildRoot=switch($record.file){
            'wellness-public.zip' {'Frontend/dist/public'}
            'wellness-common-portal.zip' {'Frontend/dist/portal'}
            'wellnexa-website.zip' {'Wellnexa/dist'}
        }
        if($buildRoot){
            $root=(Resolve-Path -LiteralPath (Join-Path $repo $buildRoot)).Path
            foreach($source in Get-ChildItem -LiteralPath $root -File -Recurse -Force){
                $relative=$source.FullName.Substring($root.Length+1).Replace('\','/')
                $entry=$zip.GetEntry($relative)
                if($null -eq $entry -or (& $hashBytes (& $entryBytes $entry)) -ne (Get-FileHash -LiteralPath $source.FullName -Algorithm SHA256).Hash.ToLowerInvariant()){throw "Built-output mismatch in $($record.file): $relative"}
                $checks++
            }
        }
        if($record.file -eq 'copihue-portal-landing.zip'){
            $root=(Resolve-Path -LiteralPath (Join-Path $repo 'Frontend/dist/application-admin')).Path
            foreach($source in Get-ChildItem -LiteralPath $root -Recurse -File){
                $relative=$source.FullName.Substring($root.Length+1).Replace('\','/')
                if($relative -eq 'central.html'){$relative='index.html'}
                $entry=$zip.GetEntry('admin/'+$relative)
                if($null -eq $entry -or (& $hashBytes (& $entryBytes $entry)) -ne (Get-FileHash -LiteralPath $source.FullName -Algorithm SHA256).Hash.ToLowerInvariant()){throw "Central build mismatch: $relative"}
                $checks++
            }
        }
    } finally {$zip.Dispose()}
}
foreach($property in $manifest.lockfile_sha256.PSObject.Properties){
    if((Get-FileHash -LiteralPath (Join-Path $repo $property.Name) -Algorithm SHA256).Hash.ToLowerInvariant() -ne $property.Value){throw "Lockfile mismatch: $($property.Name)"};$checks++
}
$result=[ordered]@{source_commit=$manifest.source_commit;archives=$expected.Count;archive_entries=$entries;text_entries_signature_scanned=$scanned;checks=$checks;status='Local artifact checks passed; hosted acceptance not performed.'}
$result | ConvertTo-Json
