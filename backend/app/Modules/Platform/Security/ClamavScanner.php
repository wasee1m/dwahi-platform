<?php

namespace App\Modules\Platform\Security;

use RuntimeException;

/**
 * ClamAV عبر clamd بأمر INSTREAM (بلا حزم إضافية): أجزاء بطولها (4 بايت big-endian) ثم صفر، والرد
 * "stream: OK" أو "stream: <التوقيع> FOUND".
 */
final class ClamavScanner implements VirusScanner
{
    private const CHUNK = 64 * 1024;

    public function __construct(private readonly string $host, private readonly int $port, private readonly int $timeout = 30) {}

    public function scan(string $bytes): ?string
    {
        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $error, $this->timeout);
        if (! $socket) {
            throw new RuntimeException("clamd unreachable: {$error}");
        }
        stream_set_timeout($socket, $this->timeout);
        try {
            fwrite($socket, "zINSTREAM\0");
            foreach (str_split($bytes === '' ? "\0" : $bytes, self::CHUNK) as $chunk) {
                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }
            fwrite($socket, pack('N', 0));
            $reply = trim((string) stream_get_contents($socket), "\0\r\n ");
        } finally {
            fclose($socket);
        }
        if (str_ends_with($reply, 'OK')) {
            return null;
        }
        if (preg_match('/:\s*(.+)\s+FOUND$/', $reply, $m)) {
            return $m[1];
        }
        throw new RuntimeException("clamd error: {$reply}");
    }
}
