<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0b1220">
    <meta name="description" content="Push-to-Talk channel">
    <meta name="csrf-token" content="<?= e($csrf ?? '') ?>">
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <title><?= e($title ?? 'Walkie Talkie') ?></title>
    <link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
    <link rel="icon" type="image/png" href="<?= e(asset('icons/icon-192.png')) ?>">
    <link rel="apple-touch-icon" href="<?= e(asset('icons/apple-touch-icon.png')) ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="wt-body wt-channel-body">
    <?= $content ?? '' ?>
    <script>
        window.WALKIE_CONFIG = <?= json_encode([
            'signalUrl' => url('signal'),
            'leaveUrl' => url('leave'),
            'token' => $token ?? '',
            'peerId' => $peerId ?? '',
            'nickname' => $nickname ?? '',
            'channel' => $channel ?? '',
            'channelDisplay' => $channelDisplay ?? '',
            'iceServers' => $iceServers ?? [],
            'floorTimeoutMs' => $floorTimeoutMs ?? 30000,
            'pollIntervalMs' => $pollIntervalMs ?? 800,
            'csrf' => $csrf ?? '',
            'maxPeers' => $maxPeers ?? 8,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="<?= e(asset('js/signaling.js')) ?>" defer></script>
    <script src="<?= e(asset('js/webrtc.js')) ?>" defer></script>
    <script src="<?= e(asset('js/audio-level.js')) ?>" defer></script>
    <script src="<?= e(asset('js/ptt.js')) ?>" defer></script>
    <script src="<?= e(asset('js/pwa.js')) ?>" defer></script>
    <script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
