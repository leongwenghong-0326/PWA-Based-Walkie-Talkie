<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\QrCode;

final class AssetController
{
    public function manifest(Request $request): void
    {
        $name = 'Walkie Talkie';
        $payload = [
            'name' => $name,
            'short_name' => 'Walkie',
            'description' => 'Push-to-Talk instant voice communication',
            'start_url' => App::url(),
            'scope' => App::url(),
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#0b1220',
            'theme_color' => '#0b1220',
            'icons' => [
                [
                    'src' => App::asset('icons/icon-192.png'),
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any maskable',
                ],
                [
                    'src' => App::asset('icons/icon-512.png'),
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any maskable',
                ],
            ],
        ];

        http_response_code(200);
        header('Content-Type: application/manifest+json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function serviceWorker(Request $request): void
    {
        $base = App::url();
        $assets = App::url('public/assets/');
        header('Service-Worker-Allowed: ' . $base);
        header('Cache-Control: no-cache');

        $js = <<<JS
const CACHE_NAME = 'walkie-talkie-shell-v4';
const BASE = '{$base}';
const ASSETS = '{$assets}';
const PRECACHE = [
  ASSETS + 'icons/icon-192.png',
  ASSETS + 'icons/icon-512.png',
  BASE + 'manifest.webmanifest'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)));
    await self.clients.claim();
  })());
});

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);
  const path = url.pathname;

  // Never cache HTML pages or live JS/CSS — phones were stuck on old code + stale CSRF forms.
  const isHtmlNav = request.mode === 'navigate' || path.endsWith('.php') || path.endsWith('/');
  const isAppCode = /\\/public\\/assets\\/(js|css)\\//.test(path)
    || /\\/(sw\\.js|manifest\\.webmanifest)$/.test(path);
  const isDynamic = /\\/(signal|join|leave|channel|qr\\.svg)(\\/|$|\\?)/.test(path);

  if (isHtmlNav || isAppCode || isDynamic) {
    event.respondWith(
      fetch(request, { cache: 'no-store' }).catch(async () => {
        const cached = await caches.match(request);
        return cached || Response.error();
      })
    );
    return;
  }

  event.respondWith(
    caches.match(request).then((cached) => {
      if (cached) {
        return cached;
      }
      return fetch(request).then((response) => {
        if (response.ok && request.url.startsWith(self.location.origin) && path.includes('/icons/')) {
          const copy = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
        }
        return response;
      }).catch(() => cached);
    })
  );
});
JS;

        Response::text($js, 'application/javascript; charset=UTF-8');
    }

    public function qr(Request $request): void
    {
        $svg = QrCode::svg($request->publicUrl(), 5, 2);
        header('Cache-Control: no-store');
        Response::text($svg, 'image/svg+xml; charset=UTF-8');
    }
}
