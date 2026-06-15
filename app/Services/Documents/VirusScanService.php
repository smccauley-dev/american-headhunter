<?php

namespace App\Services\Documents;

use App\Services\BaseService;

/**
 * Streams uploaded file bytes to a ClamAV daemon (clamd) over TCP using the
 * INSTREAM protocol. No file is ever written to the AV host — bytes are scanned
 * in memory.
 *
 * Disabled by default (CLAMAV_ENABLED=false) so local/test environments need no
 * clamd. In production, point CLAMAV_HOST/PORT at a clamd service and enable it.
 */
class VirusScanService extends BaseService
{
    public const CLEAN    = 'clean';
    public const INFECTED = 'infected';

    public function enabled(): bool
    {
        return (bool) config('services.clamav.enabled', false);
    }

    /**
     * Scan raw bytes. Returns self::CLEAN or self::INFECTED.
     *
     * @throws \RuntimeException if clamd is unreachable or returns an error —
     *         callers must treat this as "unknown", never as "clean".
     */
    public function scan(string $bytes): string
    {
        $host    = (string) config('services.clamav.host', '127.0.0.1');
        $port    = (int) config('services.clamav.port', 3310);
        $timeout = (int) config('services.clamav.timeout', 30);

        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

        if ($socket === false) {
            throw new \RuntimeException("ClamAV unreachable at {$host}:{$port}: {$errstr} ({$errno})");
        }

        try {
            stream_set_timeout($socket, $timeout);
            fwrite($socket, "zINSTREAM\0");

            // clamd reads length-prefixed chunks; a zero-length chunk ends the stream.
            foreach (str_split($bytes, 8192) as $chunk) {
                fwrite($socket, pack('N', strlen($chunk)) . $chunk);
            }
            fwrite($socket, pack('N', 0));

            $response = trim((string) fgets($socket, 4096));
        } finally {
            fclose($socket);
        }

        // "stream: OK" = clean; "stream: <Signature> FOUND" = infected.
        if (str_contains($response, 'FOUND')) {
            return self::INFECTED;
        }

        if (str_ends_with($response, 'OK')) {
            return self::CLEAN;
        }

        throw new \RuntimeException("ClamAV scan error: {$response}");
    }
}
