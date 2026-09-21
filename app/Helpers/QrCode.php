<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Compact QR Code SVG generator (byte mode, error correction M).
 * Built for short public URLs so students can scan the join page on a phone.
 */
final class QrCode
{
    /** @var array<int, array{0:int,1:int,2:int,3:int,4:int}> */
    private const BLOCKS = [
        1 => [10, 1, 16, 0, 0],
        2 => [16, 1, 28, 0, 0],
        3 => [26, 1, 44, 0, 0],
        4 => [18, 2, 32, 0, 0],
        5 => [24, 2, 43, 0, 0],
        6 => [16, 4, 27, 0, 0],
        7 => [18, 4, 31, 0, 0],
        8 => [22, 2, 38, 2, 39],
        9 => [22, 3, 36, 2, 37],
        10 => [26, 4, 43, 1, 44],
    ];

    /** @var array<int, array<int, int>> */
    private const ALIGN = [
        2 => [18],
        3 => [22],
        4 => [26],
        5 => [30],
        6 => [34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    public static function svg(string $text, int $moduleSize = 4, int $margin = 2): string
    {
        $matrix = self::matrix($text);
        $n = count($matrix);
        $dim = ($n + $margin * 2) * $moduleSize;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dim . ' ' . $dim . '" width="' . $dim . '" height="' . $dim . '" role="img" aria-label="QR code">';
        $svg .= '<rect width="100%" height="100%" fill="#ffffff"/>';
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($matrix[$y][$x]) {
                    $px = ($x + $margin) * $moduleSize;
                    $py = ($y + $margin) * $moduleSize;
                    $svg .= '<rect x="' . $px . '" y="' . $py . '" width="' . $moduleSize . '" height="' . $moduleSize . '" fill="#0b1220"/>';
                }
            }
        }
        $svg .= '</svg>';
        return $svg;
    }

    /** @return array<int, array<int, int>> */
    public static function matrix(string $text): array
    {
        $bytes = array_values(unpack('C*', $text) ?: []);
        $version = self::chooseVersion(count($bytes));
        $size = 21 + 4 * ($version - 1);
        $data = self::encode($bytes, $version);
        [$modules, $reserved] = self::buildPatterns($version, $size);
        self::placeData($modules, $reserved, $data, $size);

        $bestMask = 0;
        $bestScore = PHP_INT_MAX;
        $best = $modules;
        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = $modules;
            self::applyMask($candidate, $reserved, $mask, $size);
            self::drawFormat($candidate, $reserved, $mask, $size);
            $score = self::score($candidate, $size);
            if ($score < $bestScore) {
                $bestScore = $score;
                $bestMask = $mask;
                $best = $candidate;
            }
        }

        unset($bestMask);
        return $best;
    }

    /** @param array<int, int> $bytes */
    private static function chooseVersion(int $length): int
    {
        for ($version = 1; $version <= 10; $version++) {
            $ccBits = $version < 10 ? 8 : 16;
            $bits = 4 + $ccBits + $length * 8 + 4;
            $needed = intdiv($bits + 7, 8);
            $capacity = self::BLOCKS[$version][1] * self::BLOCKS[$version][2]
                + self::BLOCKS[$version][3] * self::BLOCKS[$version][4];
            if ($needed <= $capacity) {
                return $version;
            }
        }

        throw new \RuntimeException('QR data is too long.');
    }

    /** @param array<int, int> $bytes @return array<int, int> */
    private static function encode(array $bytes, int $version): array
    {
        [$ecPerBlock, $g1Blocks, $g1Data, $g2Blocks, $g2Data] = self::BLOCKS[$version];
        $capacity = $g1Blocks * $g1Data + $g2Blocks * $g2Data;
        $ccBits = $version < 10 ? 8 : 16;

        $bits = '0100';
        $bits .= str_pad(decbin(count($bytes)), $ccBits, '0', STR_PAD_LEFT);
        foreach ($bytes as $b) {
            $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        }
        $capacityBits = $capacity * 8;
        $terminator = min(4, $capacityBits - strlen($bits));
        $bits .= str_repeat('0', $terminator);
        while (strlen($bits) % 8 !== 0) {
            $bits .= '0';
        }
        $pad = ['11101100', '00010001'];
        $i = 0;
        while (strlen($bits) < $capacityBits) {
            $bits .= $pad[$i % 2];
            $i++;
        }
        $bits = substr($bits, 0, $capacityBits);

        $data = [];
        for ($i = 0; $i < $capacity; $i++) {
            $data[] = bindec(substr($bits, $i * 8, 8));
        }

        $blocks = [];
        $offset = 0;
        for ($b = 0; $b < $g1Blocks; $b++) {
            $slice = array_slice($data, $offset, $g1Data);
            $offset += $g1Data;
            $blocks[] = array_merge($slice, self::rs($slice, $ecPerBlock));
        }
        for ($b = 0; $b < $g2Blocks; $b++) {
            $slice = array_slice($data, $offset, $g2Data);
            $offset += $g2Data;
            $blocks[] = array_merge($slice, self::rs($slice, $ecPerBlock));
        }

        $maxLen = 0;
        foreach ($blocks as $block) {
            $maxLen = max($maxLen, count($block));
        }

        $interleaved = [];
        for ($i = 0; $i < $maxLen; $i++) {
            foreach ($blocks as $block) {
                if (isset($block[$i])) {
                    $interleaved[] = $block[$i];
                }
            }
        }

        $remainders = [1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7, 7 => 0, 8 => 0, 9 => 0, 10 => 0];
        $bitString = '';
        foreach ($interleaved as $byte) {
            $bitString .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }
        $bitString .= str_repeat('0', $remainders[$version]);

        return array_map('intval', str_split($bitString));
    }

    /** @param array<int, int> $data @return array<int, int> */
    private static function rs(array $data, int $ecCount): array
    {
        $gfExp = [];
        $gfLog = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $gfExp[$i] = $x;
            $gfLog[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11d;
            }
        }
        $gfExp[255] = $gfExp[0];

        $gen = [1];
        for ($i = 0; $i < $ecCount; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            for ($j = 0; $j < count($gen); $j++) {
                $next[$j] ^= $gen[$j];
                $next[$j + 1] ^= self::gfMul($gen[$j], $gfExp[$i], $gfExp, $gfLog);
            }
            $gen = $next;
        }

        $ec = array_fill(0, $ecCount, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ $ec[0];
            array_shift($ec);
            $ec[] = 0;
            if ($factor === 0) {
                continue;
            }
            $factorLog = $gfLog[$factor];
            for ($j = 0; $j < $ecCount; $j++) {
                $ec[$j] ^= self::gfMul($gen[$j + 1], $gfExp[$factorLog], $gfExp, $gfLog);
            }
        }

        return $ec;
    }

    /** @param array<int, int> $gfExp @param array<int, int> $gfLog */
    private static function gfMul(int $a, int $b, array $gfExp, array $gfLog): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        return $gfExp[($gfLog[$a] + $gfLog[$b]) % 255];
    }

    /**
     * @return array{0:array<int,array<int,int>>,1:array<int,array<int,int>>}
     */
    private static function buildPatterns(int $version, int $size): array
    {
        $modules = array_fill(0, $size, array_fill(0, $size, 0));
        $reserved = array_fill(0, $size, array_fill(0, $size, 0));

        self::finder($modules, $reserved, 0, 0);
        self::finder($modules, $reserved, $size - 7, 0);
        self::finder($modules, $reserved, 0, $size - 7);
        self::timing($modules, $reserved, $size);
        self::reserveFormat($reserved, $size);

        $modules[($size - 8)][8] = 1;
        $reserved[($size - 8)][8] = 1;

        foreach (self::ALIGN[$version] ?? [] as $row) {
            foreach (self::ALIGN[$version] as $col) {
                if (self::isFinderArea($row, $col, $size)) {
                    continue;
                }
                self::alignment($modules, $reserved, $col, $row);
            }
        }

        if ($version >= 7) {
            self::reserveVersion($reserved, $size);
            self::drawVersion($modules, $version, $size);
        }

        return [$modules, $reserved];
    }

    /** @param array<int, array<int, int>> $m @param array<int, array<int, int>> $r */
    private static function finder(array &$m, array &$r, int $x, int $y): void
    {
        for ($dy = -1; $dy <= 7; $dy++) {
            for ($dx = -1; $dx <= 7; $dx++) {
                $xx = $x + $dx;
                $yy = $y + $dy;
                if ($xx < 0 || $yy < 0 || $xx >= count($m) || $yy >= count($m)) {
                    continue;
                }
                $on = $dx === -1 || $dx === 7 || $dy === -1 || $dy === 7
                    ? 0
                    : (($dx === 0 || $dx === 6 || $dy === 0 || $dy === 6) ? 1 : (($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4) ? 1 : 0));
                if ($dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6) {
                    $m[$yy][$xx] = ($dx === 0 || $dx === 6 || $dy === 0 || $dy === 6 || ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4)) ? 1 : 0;
                } else {
                    $m[$yy][$xx] = 0;
                }
                $r[$yy][$xx] = 1;
                unset($on);
            }
        }
    }

    /** @param array<int, array<int, int>> $m @param array<int, array<int, int>> $r */
    private static function timing(array &$m, array &$r, int $size): void
    {
        for ($i = 8; $i < $size - 8; $i++) {
            $bit = $i % 2 === 0 ? 1 : 0;
            $m[6][$i] = $bit;
            $m[$i][6] = $bit;
            $r[6][$i] = 1;
            $r[$i][6] = 1;
        }
    }

    /** @param array<int, array<int, int>> $r */
    private static function reserveFormat(array &$r, int $size): void
    {
        for ($i = 0; $i < 9; $i++) {
            $r[8][$i] = 1;
            $r[$i][8] = 1;
            $r[8][$size - 1 - $i] = 1;
            $r[$size - 1 - $i][8] = 1;
        }
        $r[8][8] = 1;
    }

    /** @param array<int, array<int, int>> $r */
    private static function reserveVersion(array &$r, int $size): void
    {
        for ($i = 0; $i < 6; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $r[$i][$size - 11 + $j] = 1;
                $r[$size - 11 + $j][$i] = 1;
            }
        }
    }

    /** @param array<int, array<int, int>> $m */
    private static function drawVersion(array &$m, int $version, int $size): void
    {
        $poly = $version << 12;
        $generator = 0x1f25;
        for ($i = 17; $i >= 12; $i--) {
            if (($poly >> $i) & 1) {
                $poly ^= $generator << ($i - 12);
            }
        }
        $bits = ($version << 12) | $poly;
        for ($i = 0; $i < 18; $i++) {
            $bit = ($bits >> $i) & 1;
            $a = intdiv($i, 3);
            $b = $i % 3;
            $m[$a][$size - 11 + $b] = $bit;
            $m[$size - 11 + $b][$a] = $bit;
        }
    }

    /** @param array<int, array<int, int>> $m @param array<int, array<int, int>> $r */
    private static function alignment(array &$m, array &$r, int $cx, int $cy): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $x = $cx + $dx;
                $y = $cy + $dy;
                $m[$y][$x] = (max(abs($dx), abs($dy)) !== 1) ? 1 : 0;
                $r[$y][$x] = 1;
            }
        }
    }

    private static function isFinderArea(int $row, int $col, int $size): bool
    {
        $finder = [[3, 3], [3, $size - 4], [$size - 4, 3]];
        foreach ($finder as [$fy, $fx]) {
            if (abs($row - $fy) <= 3 && abs($col - $fx) <= 3) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, array<int, int>> $m
     * @param array<int, array<int, int>> $r
     * @param array<int, int> $bits
     */
    private static function placeData(array &$m, array $r, array $bits, int $size): void
    {
        $i = 0;
        $inc = -1;
        $row = $size - 1;
        for ($col = $size - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--;
            }
            while ($row >= 0 && $row < $size) {
                for ($c = 0; $c < 2; $c++) {
                    $x = $col - $c;
                    if ($r[$row][$x]) {
                        continue;
                    }
                    $m[$row][$x] = $bits[$i] ?? 0;
                    $i++;
                }
                $row += $inc;
            }
            $inc = -$inc;
            $row += $inc;
        }
    }

    /** @param array<int, array<int, int>> $m @param array<int, array<int, int>> $r */
    private static function applyMask(array &$m, array $r, int $mask, int $size): void
    {
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($r[$y][$x]) {
                    continue;
                }
                $flip = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
                    5 => (($x * $y) % 2) + (($x * $y) % 3) === 0,
                    6 => ((($x * $y) % 2) + (($x * $y) % 3)) % 2 === 0,
                    default => ((($x + $y) % 2) + (($x * $y) % 3)) % 2 === 0,
                };
                if ($flip) {
                    $m[$y][$x] ^= 1;
                }
            }
        }
    }

    /** @param array<int, array<int, int>> $m @param array<int, array<int, int>> $r */
    private static function drawFormat(array &$m, array $r, int $mask, int $size): void
    {
        unset($r);
        $data = (0b00 << 3) | $mask;
        $bits = $data << 10;
        $gen = 0x537;
        for ($i = 14; $i >= 10; $i--) {
            if (($bits >> $i) & 1) {
                $bits ^= $gen << ($i - 10);
            }
        }
        $format = (($data << 10) | $bits) ^ 0x5412;

        $positions = [
            [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8],
            [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8],
        ];
        $positions2 = [
            [$size - 1, 8], [$size - 2, 8], [$size - 3, 8], [$size - 4, 8], [$size - 5, 8], [$size - 6, 8], [$size - 7, 8],
            [8, $size - 8], [8, $size - 7], [8, $size - 6], [8, $size - 5], [8, $size - 4], [8, $size - 3], [8, $size - 2], [8, $size - 1],
        ];

        for ($i = 0; $i < 15; $i++) {
            $bit = ($format >> $i) & 1;
            $m[$positions[$i][0]][$positions[$i][1]] = $bit;
            $m[$positions2[$i][0]][$positions2[$i][1]] = $bit;
        }
    }

    /** @param array<int, array<int, int>> $m */
    private static function score(array $m, int $size): int
    {
        $score = 0;
        for ($y = 0; $y < $size; $y++) {
            $run = 1;
            for ($x = 1; $x < $size; $x++) {
                if ($m[$y][$x] === $m[$y][$x - 1]) {
                    $run++;
                    if ($run === 5) {
                        $score += 3;
                    } elseif ($run > 5) {
                        $score++;
                    }
                } else {
                    $run = 1;
                }
            }
        }
        for ($x = 0; $x < $size; $x++) {
            $run = 1;
            for ($y = 1; $y < $size; $y++) {
                if ($m[$y][$x] === $m[$y - 1][$x]) {
                    $run++;
                    if ($run === 5) {
                        $score += 3;
                    } elseif ($run > 5) {
                        $score++;
                    }
                } else {
                    $run = 1;
                }
            }
        }
        return $score;
    }
}
