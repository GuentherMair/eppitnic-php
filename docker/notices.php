<?php
// Writes THIRD-PARTY-NOTICES.txt: each runtime package's name, version and
// license, then its own license file(s) when vendor/ carries them.
// Usage: php notices.php <app dir> > THIRD-PARTY-NOTICES.txt

$app = $argv[1] ?? '.';
$json = shell_exec('composer licenses --no-dev --format=json --working-dir=' . escapeshellarg($app));
$packages = json_decode((string) $json, true)['dependencies'] ?? null;
if ($packages === null) {
    fwrite(STDERR, "composer licenses gave no package list\n");
    exit(1);
}
ksort($packages);

echo "Third-party software in this image: PHP packages under /app/vendor.\n";
echo "Each package's own license files stay next to it in /app/vendor/<package>/.\n";

foreach ($packages as $name => $info) {
    echo "\n", str_repeat('=', 72), "\n";
    echo $name, ' ', $info['version'], ' -- ', implode(', ', $info['license']), "\n";
    echo str_repeat('=', 72), "\n";
    foreach (glob("$app/vendor/$name/{LICENSE,LICENCE,COPYING}*", GLOB_BRACE) ?: [] as $file) {
        echo "\n--- ", basename($file), " ---\n", trim((string) file_get_contents($file)), "\n";
    }
}
