<?php
declare(strict_types=1);

/**
 * Build a zip you can drag into cPanel File Manager.
 *
 *   docker compose exec app php bin/package-cpanel.php
 *
 * Writes dist/twitch-alerter-cpanel.zip. The zip is the site, database/install.sql,
 * a cPanel .env.example and READ-ME-FIRST.txt. It never contains .env.
 * A local alert pack in public/alerts/ is included, because the server needs it.
 */

$root = dirname(__DIR__);
$top = 'twitch-alerter';
$outDir = $root . '/dist';
$out = $outDir . '/twitch-alerter-cpanel.zip';

/** @return list<array{0:string,1:?string,2:string}> [zip path, disk path or null for a directory, contents if no disk path] */
function collect(string $root, string $top): array
{
    $entries = [];
    $addTree = static function (string $dir, string $prefix) use (&$addTree, &$entries): void {
        $items = scandir($dir);
        if ($items === false) {
            fwrite(STDERR, "Cannot read $dir\n");
            exit(1);
        }
        $entries[] = [$prefix . '/', null, ''];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === '.DS_Store' || $item === 'Thumbs.db') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            $name = $prefix . '/' . $item;
            if (is_link($path)) {
                continue;
            }
            if (is_dir($path)) {
                $addTree($path, $name);
            } else {
                if ($item === '.env') {
                    fwrite(STDERR, "Refusing to package $path\n");
                    exit(1);
                }
                $entries[] = [$name, $path, ''];
            }
        }
    };

    $entries[] = [$top . '/', null, ''];
    foreach (['public', 'src', 'templates'] as $dir) {
        $addTree($root . DIRECTORY_SEPARATOR . $dir, $top . '/' . $dir);
    }
    $entries[] = [$top . '/bin/', null, ''];
    $entries[] = [$top . '/bin/migrate.php', $root . '/bin/migrate.php', ''];
    $entries[] = [$top . '/database/', null, ''];
    $entries[] = [$top . '/database/install.sql', $root . '/database/install.sql', ''];
    $entries[] = [$top . '/.htaccess', $root . '/.htaccess', ''];
    $entries[] = [$top . '/.env.example', $root . '/deploy/cpanel.env.example', ''];
    $entries[] = [$top . '/READ-ME-FIRST.txt', $root . '/deploy/READ-ME-FIRST.txt', ''];
    foreach (['storage', 'storage/logs', 'storage/uploads'] as $dir) {
        $entries[] = [$top . '/' . $dir . '/', null, ''];
    }

    foreach ($entries as [$name, $path]) {
        if ($path !== null && !is_file($path)) {
            fwrite(STDERR, "Missing $path\n");
            exit(1);
        }
    }
    return $entries;
}

/**
 * Write a zip. PHP in the app image has zlib but not the zip extension, so this is done by hand.
 * @param list<array{0:string,1:?string,2:string}> $entries
 */
function writeZip(string $dest, array $entries): void
{
    $fp = fopen($dest, 'wb');
    if ($fp === false) {
        fwrite(STDERR, "Cannot write $dest\n");
        exit(1);
    }
    $now = getdate();
    $time = ($now['hours'] << 11) | ($now['minutes'] << 5) | intdiv($now['seconds'], 2);
    $date = (($now['year'] - 1980) << 9) | ($now['mon'] << 5) | $now['mday'];

    $central = '';
    $offset = 0;
    $count = 0;
    foreach ($entries as [$name, $path]) {
        $dir = str_ends_with($name, '/');
        $raw = $dir ? '' : (string) file_get_contents((string) $path);
        $crc = crc32($raw);
        $method = 0;
        $payload = $raw;
        if (!$dir && $raw !== '') {
            $deflated = gzdeflate($raw, 6);
            if ($deflated !== false && strlen($deflated) < strlen($raw)) {
                $method = 8;
                $payload = $deflated;
            }
        }
        $mode = ($dir ? 040755 : 0100644) << 16;
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $method, $time, $date, $crc, strlen($payload), strlen($raw), strlen($name), 0);
        fwrite($fp, $local . $name . $payload);
        $central .= pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50, 0x0314, 20, 0, $method, $time, $date, $crc,
            strlen($payload), strlen($raw), strlen($name), 0, 0, 0, 0, $mode, $offset
        ) . $name;
        $offset += strlen($local) + strlen($name) + strlen($payload);
        $count++;
        unset($raw, $payload);
    }
    fwrite($fp, $central);
    fwrite($fp, pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), $offset, 0));
    fclose($fp);
}

$entries = collect($root, $top);
if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Cannot create $outDir\n");
    exit(1);
}
if (is_file($out)) {
    unlink($out);
}
writeZip($out, $entries);

$names = array_column($entries, 0);
$bad = array_filter($names, static fn(string $n): bool => $n === $top . '/.env' || str_ends_with($n, '/.env'));
if ($bad !== []) {
    unlink($out);
    fwrite(STDERR, "The zip contained .env and was deleted.\n");
    exit(1);
}

$bytes = filesize($out);
$size = $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : (string) $bytes . ' bytes';
$pack = in_array($top . '/public/alerts/catalog.json', $names, true);
echo "Wrote dist/twitch-alerter-cpanel.zip ($size, " . count(array_filter($names, static fn(string $n): bool => !str_ends_with($n, '/'))) . " files)\n";
echo $pack
    ? "Alert pack: included (public/alerts/catalog.json is in the zip).\n"
    : "Alert pack: not found. Only the starter template is included, so built-in alerts will not play until you add a pack.\n";
echo ".env was not included. Drag the zip into cPanel File Manager, extract it, and follow READ-ME-FIRST.txt.\n";
