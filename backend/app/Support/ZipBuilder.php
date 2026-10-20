<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Minimal in-memory ZIP writer (PKWARE APPNOTE 6.3, deflate via zlib) so archive downloads do not depend on the
 * optional ext-zip. UTF-8 file names (general purpose flag bit 11). Suitable for archives up to a few hundred MB.
 */
class ZipBuilder
{
    /** @var list<array{name: string, crc: int, csize: int, usize: int, offset: int, method: int, time: int, date: int}> */
    protected array $entries = [];

    protected string $data = '';

    /** @var array<string, true> */
    protected array $names = [];

    public function add(string $name, string $contents): self
    {
        $name = $this->safeName($name);
        if (isset($this->names[$name])) {
            $dot = strrpos($name, '.');
            $i = 2;
            do {
                $candidate = $dot === false ? "{$name} ({$i})" : substr($name, 0, $dot)." ({$i})".substr($name, $dot);
                $i++;
            } while (isset($this->names[$candidate]));
            $name = $candidate;
        }
        $this->names[$name] = true;

        $deflated = gzdeflate($contents, 6);
        $method = ($deflated !== false && strlen($deflated) < strlen($contents)) ? 8 : 0;
        $stored = $method === 8 ? $deflated : $contents;
        [$time, $date] = $this->dosTime(time());
        $crc = crc32($contents);

        $this->entries[] = [
            'name' => $name, 'crc' => $crc, 'csize' => strlen($stored), 'usize' => strlen($contents),
            'offset' => strlen($this->data), 'method' => $method, 'time' => $time, 'date' => $date,
        ];

        $this->data .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, $method, $time, $date, $crc, strlen($stored), strlen($contents), strlen($name), 0)
            .$name.$stored;

        return $this;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function output(): string
    {
        $central = '';
        foreach ($this->entries as $e) {
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, $e['method'], $e['time'], $e['date'], $e['crc'],
                $e['csize'], $e['usize'], strlen($e['name']), 0, 0, 0, 0, 0, $e['offset']).$e['name'];
        }
        $end = pack('VvvvvVVv', 0x06054b50, 0, 0, count($this->entries), count($this->entries), strlen($central), strlen($this->data), 0);

        return $this->data.$central.$end;
    }

    /** Forward slashes only, no absolute paths, no "..", no control characters. */
    protected function safeName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $parts = array_filter(explode('/', $name), fn ($p) => $p !== '' && $p !== '.' && $p !== '..');
        $clean = implode('/', array_map(fn ($p) => preg_replace('/[\x00-\x1F<>:"|?*]+/', '_', $p), $parts));
        if ($clean === '') {
            throw new InvalidArgumentException('Empty file name in archive.');
        }

        return mb_substr($clean, 0, 240);
    }

    /** @return array{int, int} MS-DOS time and date */
    protected function dosTime(int $timestamp): array
    {
        $d = getdate($timestamp);
        if ($d['year'] < 1980) {
            return [0, (1 << 5) | 1];
        }

        return [
            ($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2),
            (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'],
        ];
    }
}
