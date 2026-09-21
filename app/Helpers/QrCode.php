<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * QR code PNG output using the bundled phpqrcode library (LGPL).
 * The previous custom SVG encoder produced codes phones could not detect.
 */
final class QrCode
{
    public static function png(string $text, int $pixelSize = 8, int $margin = 4): void
    {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'phpqrcode.php';

        if (!defined('QR_ECLEVEL_M')) {
            throw new \RuntimeException('phpqrcode failed to load.');
        }

        // phpqrcode is old; ignore deprecations so PHP 8.2 error handler does not abort output.
        $previous = set_error_handler(static function (int $severity, string $message): bool {
            if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED || $severity === E_NOTICE) {
                return true;
            }
            return false;
        });

        try {
            \QRcode::png(
                $text,
                false,
                QR_ECLEVEL_M,
                max(4, $pixelSize),
                max(3, $margin),
                false,
                0xFFFFFF,
                0x000000
            );
        } finally {
            if ($previous !== null) {
                set_error_handler($previous);
            } else {
                restore_error_handler();
            }
        }
    }
}
