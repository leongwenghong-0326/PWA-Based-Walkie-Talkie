<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$dir = $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'icons';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}

function create_icon(string $path, int $size): void
{
    $image = imagecreatetruecolor($size, $size);
    if ($image === false) {
        throw new RuntimeException('GD is required to generate icons.');
    }

    $bg = imagecolorallocate($image, 11, 18, 32);
    $amber = imagecolorallocate($image, 245, 158, 11);
    $dark = imagecolorallocate($image, 26, 18, 5);
    $white = imagecolorallocate($image, 255, 248, 230);

    imagefilledrectangle($image, 0, 0, $size - 1, $size - 1, $bg);

    $pad = (int) round($size * 0.14);
    imagefilledellipse($image, (int) ($size / 2), (int) ($size / 2), $size - $pad, $size - $pad, $amber);

    $grill = (int) round($size * 0.30);
    imagefilledellipse($image, (int) ($size / 2), (int) ($size * 0.46), $grill, $grill, $dark);

    $antennaW = max(4, (int) round($size * 0.06));
    $antennaH = (int) round($size * 0.18);
    imagefilledrectangle(
        $image,
        (int) ($size / 2 - $antennaW / 2),
        (int) ($size * 0.08),
        (int) ($size / 2 + $antennaW / 2),
        (int) ($size * 0.08 + $antennaH),
        $white
    );

    imagepng($image, $path);
    imagedestroy($image);
}

create_icon($dir . DIRECTORY_SEPARATOR . 'icon-192.png', 192);
create_icon($dir . DIRECTORY_SEPARATOR . 'icon-512.png', 512);
create_icon($dir . DIRECTORY_SEPARATOR . 'apple-touch-icon.png', 180);

echo "Icons generated in {$dir}\n";
