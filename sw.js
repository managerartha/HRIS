/*
 * Service worker HRIS: membuat HRIS bisa dipasang sebagai aplikasi di HP.
 * Sengaja TIDAK menyimpan (cache) halaman/data, supaya karyawan selalu memakai versi terbaru
 * dan data absensi selalu langsung dari server. Saat tidak ada internet, tampil pesan offline.
 */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));
self.addEventListener('fetch', event => {
  if (event.request.mode !== 'navigate') return;
  event.respondWith(
    fetch(event.request).catch(() => new Response(
      '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
      '<title>HRIS - Offline</title></head><body style="font-family:system-ui,sans-serif;background:#0f172a;color:#fff;display:flex;' +
      'align-items:center;justify-content:center;min-height:100vh;margin:0;text-align:center;padding:24px">' +
      '<div><h2>Tidak ada koneksi internet</h2><p>Absensi membutuhkan internet. Periksa data seluler / Wi-Fi lalu coba lagi.</p>' +
      '<button onclick="location.reload()" style="margin-top:12px;padding:10px 18px;border:0;border-radius:10px;background:#059669;color:#fff;font-weight:bold">Coba Lagi</button></div>' +
      '</body></html>', { headers: { 'Content-Type': 'text/html; charset=utf-8' } }))
  );
});
