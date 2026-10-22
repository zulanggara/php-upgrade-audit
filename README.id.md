# php-upgrade-audit

[English](README.md) · **Bahasa Indonesia**

Perangkat audit **read-only** untuk memindahkan kode PHP 7.x ke PHP 8.x (target default **8.5.12**). Dirancang
untuk dijalankan oleh AI coding agent (skill Claude Code, atau agent lain lewat `AGENTS.md`), tapi setiap
langkahnya berupa script PHP biasa yang juga bisa Anda jalankan sendiri.

Hasilnya terstandar: JSON per tool, catatan analis dengan format tetap, dan satu laporan HTML berisi halaman
per repo, riwayat setiap scan, dan dashboard.

## Yang diperiksa

| Area | Caranya |
|---|---|
| Fungsi, syntax, dan helper yang dihapus, deprecated, atau berubah perilaku (PHP 7.1 → 8.5) | Scanner berbasis tokenizer, sekitar 80 aturan dari panduan migrasi resmi php.net |
| Kode yang dijaga pengecekan versi | `if (PHP_VERSION_ID < 80000)`, `is_php()`, `function_exists()`, dan sejenisnya dievaluasi terhadap versi target, jadi cabang lama yang tidak dijalankan tidak dihitung |
| Parse error dan deprecation saat kompilasi | `php -l` ke semua file dengan PHP target |
| Library Composer | Constraint `php` setiap package di `composer.lock` vs target, package abandoned, versi terbaru di major yang sama, versi terbaru yang mendukung target (Packagist) |
| Ekstensi PHP | Ekstensi yang dibutuhkan package tapi sudah dihapus dari core (mcrypt, imap, dll.) |
| Pembanding | PHPCompatibility (phpcs) dan Rector `--dry-run` (menunjukkan apa yang bisa diperbaiki otomatis) |
| Ganti atau merge framework (opsional) | Patch lokal vs rilis resmi, API yang hilang/berubah, konflik override dengan aplikasi (aturan signature PHP), pemanggilan API yang hilang, key config |

Kode yang diaudit tidak pernah diubah, dan `composer` maupun Rector tidak pernah dijalankan di dalamnya.

## Laporan

- Satu file HTML mandiri. Bisa dipakai sebagai [Claude Artifact](https://claude.ai) atau dibuka lokal.
- Tampilan seperti dokumentasi: menu samping, satu halaman per repo dan per perbandingan, dashboard dan tujuan scan.
- **Riwayat:** setiap scan disimpan. Halaman repo punya pemilih versi (default scan terakhir) dan menunjukkan
  berapa temuan yang teratasi, baru, atau tetap dibanding scan sebelumnya.
- Setiap halaman punya anchor: `#<repo>` (scan terakhir) atau `#<repo>~<scan-id>` (scan tertentu).
- Catatan analis dengan perbandingan kode **Saat ini vs Disarankan**, yang wajib diuji agent di PHP target
  sebelum disarankan.
- Bahasa Inggris atau Indonesia (`--lang=en|id`), tema terang dan gelap, tampilan ponsel.

## Privasi dan jaringan

- **Rahasia disensor** di setiap output tool dan sekali lagi pada seluruh laporan: token seperti `ghp_…` dan
  `glpat-…`, key AWS/Google/Stripe/Slack, JWT, private key, `user:password@` di URL, `password => '…'`,
  `$secret = '…'`, `DB_PASSWORD=…`, dan literal acak yang panjang. Semuanya tampil sebagai `«REDACTED»`.
- Path absolut lokal dibuang dari laporan.
- Satu-satunya akses jaringan saat audit adalah ke `repo.packagist.org`, hanya dengan nama package publik.
  Package privat dilewati. `--offline` mematikannya.
- HTML memuat font IBM Plex dari Google Fonts. Render dengan `--offline` untuk halaman tanpa request eksternal sama sekali.
- Tidak ada telemetri.

## Kebutuhan

- PHP **8.1+** untuk menjalankan script. Untuk bukti yang sebenarnya, versi PHP **target** (mis. 8.5.12) sebagai `php`.
- Composer (untuk memasang tool analisa sekali saja), git, `diff`.
- Browser untuk membaca laporan.

## Instalasi

```bash
git clone https://github.com/zulanggara/php-upgrade-audit.git
cd php-upgrade-audit
bash scripts/setup.sh        # memasang Rector, PHP_CodeSniffer + PHPCompatibility, composer/semver ke tools/
```

### Claude Code

Clone ke folder skill supaya Claude bisa memuat `SKILL.md`:

```bash
git clone https://github.com/zulanggara/php-upgrade-audit.git ~/.claude/skills/php-upgrade-audit
bash ~/.claude/skills/php-upgrade-audit/scripts/setup.sh
```

Lalu minta, misalnya: *"audit /path/ke/repo untuk PHP 8.5.12"*.

### AI coding agent lain

Clone di mana saja, lalu arahkan agent ke `AGENTS.md`, misalnya:

- **Codex / Gemini CLI / Aider / Cline / Windsurf:** tambahkan ke instruksi proyek: *"Untuk audit upgrade PHP,
  ikuti `/path/ke/php-upgrade-audit/AGENTS.md`."*
- **Cursor:** tambahkan project rule yang merujuk ke `AGENTS.md`.
- **GitHub Copilot:** rujuk dari `.github/copilot-instructions.md`.

## Menjalankan manual

```bash
STORE=~/php-audit-store

# 1. scan (jalankan lagi nanti: versi baru ditambahkan, tidak ada yang ditimpa)
php scripts/audit.php /path/ke/repo --out=$STORE --name=aplikasi-saya --target=8.5.12

# 2. opsional: ganti framework
php scripts/compare_framework.php --old=/path/lama/system --new=/path/baru/system --app=/path/ke/repo \
    --name="Merge framework" --store=$STORE

# 3. tulis $STORE/aplikasi-saya/scans/<scan-id>/notes.json dan $STORE/overview.json (lihat AGENTS.md)

# 4. render lalu buka
php scripts/render.php --store=$STORE --out=$STORE/report.html --lang=id --offline
php -S 127.0.0.1:8085 -t $STORE     # lalu buka http://127.0.0.1:8085/report.html
```

Lihat [`examples/sample-report.html`](examples/sample-report.html) untuk contoh laporan dari fixture bawaan
(`fixtures/legacy-app`, kode PHP 7 yang sengaja dibuat bermasalah).

## Struktur store

```
<store>/
  overview.json                     ringkasan lintas repo (versi lama disimpan di _overview/)
  <repo>/index.json                 daftar scan
  <repo>/scans/<scan-id>/           report.json, scan.json, lint.json, libs.json, phpcompat.json, rector.json, notes.json
  <perbandingan>/scans/<scan-id>/   compare.json, notes.json, diffs/
  .cache/                           metadata Packagist dan cache Rector
```

Scan id berupa timestamp UTC (`20261022T131200Z`), jadi folder otomatis terurut menurut waktu.

## Tingkat temuan

| Key | Arti |
|---|---|
| `wajib` | Wajib diubah: error/fatal, atau kode tidak lagi berjalan seperti sebelumnya |
| `deprecated` | Masih jalan di target tapi memunculkan `E_DEPRECATED`; rusak di PHP 9 |
| `perilaku` | Perilaku berubah: tidak error, tapi hasilnya bisa berbeda. Perlu review |
| `info` | Catatan, tidak mendesak |

## Batasan

- Analisa statis tidak bisa melihat nilai saat runtime (mis. `null` yang dikirim ke fungsi bawaan). Laporan
  mencantumkan apa saja yang perlu diawasi saat aplikasi dijalankan di PHP target dengan `E_ALL`.
- Katalog aturan mencakup PHP 7.1 → 8.5 sesuai php.net. Perbarui saat ada versi PHP baru (`references/rules.md`).
- Bukti dengan memuat class (`ci3_link_app.php`) khusus CodeIgniter 3; framework lain butuh loader sendiri.
- PHPCompatibility 10 masih alpha; scanner dan `php -l` tidak bergantung padanya.

## Lisensi

MIT, lihat [LICENSE](LICENSE). Tool analisa yang dipasang `setup.sh` tetap memakai lisensinya masing-masing
(Rector MIT, PHP_CodeSniffer BSD-3-Clause, PHPCompatibility LGPL-3.0, composer/semver MIT).
