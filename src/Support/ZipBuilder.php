<?php

namespace Bitdreamit\QzTray\Support;

/**
 * Minimal pure-PHP ZIP writer — STORE method (no compression).
 *
 * Why this exists: qz:client-bundle used to depend on PHP's ext-zip for the
 * downloadable qz-client-bundle.zip. Many cPanel builds ship PHP WITHOUT
 * php-zip (and CLI/FPM often have different extension sets), and worse, an
 * unchecked ZipArchive::close() failure (disk quota, open_basedir, full
 * temp dir) used to leave a HALF-WRITTEN archive on disk that clients then
 * downloaded and could not open — the classic "invalid zip" report.
 *
 * This writer has zero extension dependencies, embeds the CRC-32 of every
 * payload, validates the finished archive structurally before trusting it,
 * and never leaves a partial file behind: the archive is assembled in
 * memory and moved into place with an atomic rename.
 *
 * Output is the classic PKZIP APPNOTE structure (local file header + stored
 * payload per entry, one central directory, one end-of-central-directory
 * record). Windows Explorer, 7-Zip, WinRAR, macOS Archive Utility, unzip(1)
 * and PHP's own ZipArchive all read it natively.
 */
class ZipBuilder
{
    /** Files >= 4 GB cannot be represented in the classic (non-zip64) format. */
    private const MAX_ENTRY_BYTES = 0xFFFFFFF0;

    /**
     * Build a .zip from the given files.
     *
     * @param array<string,string> $files      map of filesystem path => archive entry name
     * @param string               $outputPath where the finished zip is written
     * @param string|null          $error      populated with a human-readable reason on failure
     */
    public static function build(array $files, string $outputPath, ?string &$error = null): bool
    {
        $error = null;
        $entries = [];

        foreach ($files as $path => $name) {
            if (! is_file($path)) {
                continue; // optional entries are simply skipped
            }

            $data = @file_get_contents($path);

            if ($data === false) {
                $error = "unreadable input file: {$path}";
                return false;
            }

            if (strlen($data) > self::MAX_ENTRY_BYTES) {
                $error = "entry too large for the classic zip format: {$name}";
                return false;
            }

            $entries[] = [
                'name' => (string) $name,
                'data' => $data,
                'crc'  => crc32($data),
                'time' => (int) (@filemtime($path) ?: time()),
            ];
        }

        if ($entries === []) {
            $error = 'no readable input files';
            return false;
        }

        $localParts   = [];
        $centralParts = [];
        $offset       = 0;

        foreach ($entries as $entry) {
            $name = $entry['name'];
            $data = $entry['data'];
            $crc  = $entry['crc'];
            $size = strlen($data);
            [$dosTime, $dosDate] = self::dosDateTime($entry['time']);

            // --- local file header (30 bytes + name) + stored payload ---
            $local = pack(
                'VvvvvvVVVvv', // sig, version, flags, method, time, date, crc, csize, usize, nlen, elen
                0x04034b50,
                20,            // version needed to extract: 2.0
                0,             // general purpose flags: none
                0,             // compression method: STORE
                $dosTime,
                $dosDate,
                $crc,
                $size,
                $size,
                strlen($name),
                0              // extra field length
            ).$name.$data;

            // --- central directory header (46 bytes + name) ---
            $central = pack(
                'VvvvvvvVVVvvvvvVV', // sig, verMade, verNeed, flags, method, time, date, crc, csize, usize, nlen, elen, clen, disk, iattr, eattr, offset
                0x02014b50,
                20,            // version made by: 2.0 / MS-DOS
                20,
                0,
                0,
                $dosTime,
                $dosDate,
                $crc,
                $size,
                $size,
                strlen($name),
                0,
                0,
                0,
                0,
                0,
                $offset
            ).$name;

            $localParts[]   = $local;
            $centralParts[] = $central;
            $offset        += strlen($local);
        }

        $centralDir = implode('', $centralParts);

        // --- end of central directory record (22 bytes) ---
        $eocd = pack(
            'VvvvvVVv', // sig, diskNo, cdDisk, entriesThisDisk, entriesTotal, cdSize, cdOffset, commentLen
            0x06054b50,
            0,
            0,
            count($entries),
            count($entries),
            strlen($centralDir),
            $offset,
            0
        );

        $zip = implode('', $localParts).$centralDir.$eocd;

        // Sanity gate before it ever reaches a client: signature, entry count
        // and total length must all line up with what we intended to write.
        // (The EOCD signature sits at length-22, directly before the fixed
        // 22-byte end-of-central-directory record — NOT in the last 4 bytes.)
        if (substr($zip, 0, 4) !== "PK\x03\x04"
            || substr($zip, -22, 4) !== "PK\x05\x06"
            || strlen($zip) !== $offset + strlen($centralDir) + 22) {
            $error = 'internal sanity check failed while assembling the archive';
            return false;
        }

        // Atomic move into place — a concurrent reader never observes a
        // partial archive, and a failed write never clobbers a good file.
        $tmp = $outputPath.'.tmp'.uniqid('', true);

        if (@file_put_contents($tmp, $zip) !== strlen($zip) || ! @rename($tmp, $outputPath)) {
            @unlink($tmp);
            $error = "unable to write {$outputPath} (disk quota / permissions?)";
            return false;
        }

        @chmod($outputPath, 0644);

        return true;
    }

    /**
     * Unix timestamp -> [DOS time, DOS date]. The DOS epoch starts in 1980;
     * earlier timestamps are clamped. gmdate() keeps archive metadata free of
     * server-timezone drift.
     *
     * @return array{0:int,1:int}
     */
    private static function dosDateTime(int $timestamp): array
    {
        $timestamp = max($timestamp, 315532800); // 1980-01-01 00:00:00 UTC

        [$y, $m, $d, $h, $i, $s] = array_map('intval', explode('|', gmdate('Y|n|j|G|i|s', $timestamp)));

        $dosDate = (($y - 1980) << 9) | ($m << 5) | $d;
        $dosTime = ($h << 11) | ($i << 5) | intdiv($s, 2);

        return [$dosTime, $dosDate];
    }
}
