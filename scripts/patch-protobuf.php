<?php

$protoDir = __DIR__.'/../app/GameProtocol/Proto';
if (! is_dir($protoDir)) {
    echo "Proto directory not found at $protoDir\n";
    exit(1);
}

$files = glob($protoDir.'/*/Metadata/*.php');
if (empty($files)) {
    echo "No metadata files found to patch.\n";
    exit(0);
}

$patchedCount = 0;

foreach ($files as $file) {
    $content = file_get_contents($file);

    // Normalize line endings to LF for consistent matching
    $content = str_replace("\r\n", "\n", $content);

    // Target either the default unpatched block or our previous class-name-based patch
    $targetDefault = "        if (static::\$is_initialized == true) {\n            return;\n        }";

    $targetPreviousPatch = <<<'PHP'
        if (static::$is_initialized == true) {
            $shortName = (new \ReflectionClass(static::class))->getShortName();
            $group = $shortName === 'Vsinterface' ? 'VsInterface' : $shortName;
            $msgNamespace = str_replace('\\Metadata\\' . $shortName, '\\' . $group, static::class);
            $checkClass = $shortName === 'Vsinterface' ? $msgNamespace . '\\StartupAuthRequest' : $msgNamespace . '\\BookKeepingRequest';
            if (!is_null($pool->getDescriptorByClassName($checkClass))) {
                return;
            }
        }
PHP;

    $replacement = <<<'PHP'
        if (static::$is_initialized == true) {
            $segments = explode('\\', static::class);
            $version = strtolower($segments[3]);
            $group = strtolower($segments[5]);
            $protoPackage = $group . '.' . $version;
            $checkProto = $group === 'vsinterface' ? $protoPackage . '.StartupAuthRequest' : $protoPackage . '.BookKeepingRequest';
            if (!is_null($pool->getDescriptorByProtoName($checkProto))) {
                return;
            }
        }
PHP;

    // Standardize newlines for $targetPreviousPatch
    $targetPreviousPatch = str_replace("\r\n", "\n", $targetPreviousPatch);

    if (str_contains($content, $targetPreviousPatch)) {
        $content = str_replace($targetPreviousPatch, $replacement, $content);
        file_put_contents($file, $content);
        $patchedCount++;
    } elseif (str_contains($content, $targetDefault)) {
        $content = str_replace($targetDefault, $replacement, $content);
        file_put_contents($file, $content);
        $patchedCount++;
    }
}

echo "Successfully patched $patchedCount protobuf metadata files using proto name lookup.\n";
