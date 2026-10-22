# php-upgrade-audit

[![tests](https://github.com/zulanggara/php-upgrade-audit/actions/workflows/tests.yml/badge.svg)](https://github.com/zulanggara/php-upgrade-audit/actions/workflows/tests.yml)

[English](README.md) · **Bahasa Indonesia**

Perangkat audit **read-only** untuk memindahkan kode PHP ke versi PHP 8 yang lebih baru. Asalnya bisa PHP 7.x
atau 8.x yang lebih lama, dengan Laravel, Symfony, WordPress, CodeIgniter, atau tanpa framework. Target
default adalah versi terbaru yang dicakup aturan (**8.5.12**). Target lain juga bisa, misalnya
`--target=8.3.30`.

Dirancang untuk dijalankan oleh AI coding agent (skill atau plugin Claude Code, atau agent lain lewat
`AGENTS.md`), tapi setiap langkahnya berupa script PHP biasa yang bisa Anda jalankan sendiri atau di CI.

Hasilnya terstandar: JSON per tool, catatan analis dengan format tetap, dan satu laporan HTML berisi halaman
per repo, riwayat setiap scan, dan dashboard.

- [Memakai lewat AI agent](#memakai-lewat-ai-agent): contoh perintah, sudah atau belum dipasang
- [Instalasi](#instalasi): skill atau plugin Claude Code, agent lain, manual
- [Menjalankan manual](#menjalankan-manual) · [CI](#ci) · [Memilih target](#memilih-versi-php-target)
- [Yang diperiksa](#yang-diperiksa) · [Laporan](#laporan) · [Privasi](#privasi-dan-jaringan) · [Batasan](#batasan)

## Memakai lewat AI agent

Agent apa pun yang bisa menjalankan perintah shell bisa dipakai (Claude Code, Codex, Cursor, Gemini CLI,
Copilot agent mode, Cline, Aider, Windsurf, dan lainnya). Sebutkan repo mana, versi PHP berapa, dan bahasa
laporannya.

### 1. Belum memasang apa pun

Berikan link repo ini ke agent. Agent akan meng-clone, membaca instruksinya, lalu menjalankannya:

> Pakai kit audit upgrade PHP dari https://github.com/zulanggara/php-upgrade-audit: clone ke
> `~/tools/php-upgrade-audit`, jalankan `scripts/setup.sh`, lalu ikuti `AGENTS.md`-nya untuk mengaudit
> `~/src/toko-saya` ke PHP 8.5.12. Laporan dalam bahasa Indonesia, store di `~/php-audit-store`.

Versi singkat, dengan target tertentu:

> Dengan skill https://github.com/zulanggara/php-upgrade-audit, analisa repo `~/src/api-lama`: apakah sudah
> siap untuk PHP 8.3.30? Laporan dalam bahasa Indonesia.

### 2. Sudah di-clone manual

Arahkan agent ke file instruksinya:

> Ikuti `~/tools/php-upgrade-audit/AGENTS.md` dan audit `~/src/toko-saya` serta `~/src/admin` untuk PHP 8.4.
> Muat semua class di PHP target, tulis catatan beserta saran kode, lalu render laporannya.

Untuk agent yang otomatis membaca aturan proyek, tambahkan satu baris ke file instruksi proyek Anda
(`AGENTS.md` untuk Codex, `.cursor/rules` untuk Cursor, `.github/copilot-instructions.md` untuk Copilot,
`GEMINI.md` untuk Gemini CLI, `CONVENTIONS.md` untuk Aider, `.clinerules` untuk Cline):

```text
Untuk audit upgrade PHP, ikuti ~/tools/php-upgrade-audit/AGENTS.md (read-only; jangan ubah repo yang diaudit).
```

### 3. Sudah terpasang sebagai skill atau plugin Claude Code

Ketik perintahnya, dengan repo dan opsi sebagai argumen:

```text
/php-upgrade-audit ~/src/toko-saya                                # target terbaru (8.5.12), bahasa Anda
/php-upgrade-audit ~/src/toko-saya ~/src/admin --target=8.3.30 --lang=id
/php-upgrade-audit ~/src/lama --target=8.2 --store=~/audit --name=lama
```

Sebagai plugin, perintahnya diberi namespace: `/php-upgrade-audit:php-upgrade-audit ~/src/toko-saya --target=8.4`.
Permintaan biasa juga bisa, Claude akan memilih skill-nya sendiri:

> audit ~/src/toko-saya untuk PHP 8.3 dan sebutkan library mana yang menghalangi upgrade

### Meminta versi PHP tertentu

Targetnya adalah versi yang Anda sebut: `8.1`, `8.3.30`, `8.4.27`, `8.5.12`, atau `latest`. Perubahan yang
baru muncul setelah target tidak dilaporkan (audit untuk 8.3 tidak menampilkan deprecation 8.4/8.5), dan
Rector serta PHPCompatibility memeriksa terhadap versi minor target. Untuk bukti runtime (`php -l`, memuat
class) agent butuh versi PHP itu terpasang, dan akan memberi tahu kalau belum ada (lihat
[Memilih target](#memilih-versi-php-target)).

## Instalasi

Kebutuhan: PHP **8.1+** untuk menjalankan script, Composer (sekali, untuk memasang tool analisa), git, `diff`.
Linux dan macOS didukung. Di Windows pakai WSL (Windows native belum diuji). Untuk bukti yang sebenarnya, Anda
juga butuh versi PHP **target**.

### Claude Code: skill personal (`/php-upgrade-audit`)

```bash
git clone https://github.com/zulanggara/php-upgrade-audit.git ~/.claude/skills/php-upgrade-audit
bash ~/.claude/skills/php-upgrade-audit/scripts/setup.sh
```

Untuk satu proyek saja, clone ke `<proyek>/.claude/skills/php-upgrade-audit`. Perbarui dengan `git pull`.

### Claude Code: plugin (`/php-upgrade-audit:php-upgrade-audit`)

```text
/plugin install php-upgrade-audit --marketplace zulanggara/php-upgrade-audit
```

Di versi Claude Code yang lebih lama, dalam dua langkah:

```text
/plugin marketplace add zulanggara/php-upgrade-audit
/plugin install php-upgrade-audit@php-upgrade-audit
```

Tool analisa tidak ikut terunduh bersama plugin. Skill akan menjalankan `scripts/setup.sh` saat pertama
dipakai, dan lagi setelah plugin diperbarui.

### AI agent lain, atau manual

```bash
git clone https://github.com/zulanggara/php-upgrade-audit.git ~/tools/php-upgrade-audit
bash ~/tools/php-upgrade-audit/scripts/setup.sh   # memasang Rector, PHP_CodeSniffer + PHPCompatibility, composer/semver ke tools/
```

Lalu arahkan agent ke `AGENTS.md` (lihat [di atas](#2-sudah-di-clone-manual)). `setup.sh` memeriksa bahwa
tool-nya jalan, lalu mencetak perintah berikutnya.

## Menjalankan manual

```bash
TOOL=~/tools/php-upgrade-audit
STORE=~/php-audit-store

# 1. scan (jalankan lagi nanti: versi baru ditambahkan, tidak ada yang ditimpa)
php $TOOL/scripts/audit.php /path/ke/repo --out=$STORE --name=aplikasi-saya --target=8.5.12 --load-classes

# 2. opsional: ganti atau merge framework
php $TOOL/scripts/compare_framework.php --old=/path/lama/system --new=/path/baru/system --app=/path/ke/repo \
    --name="Merge framework" --store=$STORE

# 3. tulis $STORE/aplikasi-saya/scans/<scan-id>/notes.json dan $STORE/overview.json (format di AGENTS.md), lalu cek
php $TOOL/scripts/validate_notes.php --store=$STORE

# 4. render lalu buka
php $TOOL/scripts/render.php --store=$STORE --out=$STORE/report.html --lang=id --offline
php -S 127.0.0.1:8085 -t $STORE     # lalu buka http://127.0.0.1:8085/report.html
```

`php scripts/audit.php --help` menjelaskan semua opsi dan seluruh alurnya. Setiap script punya `--help`.

Lihat [`examples/sample-report.id.html`](examples/sample-report.id.html) (bahasa Indonesia) dan
[`examples/sample-report.html`](examples/sample-report.html) (bahasa Inggris): dua scan dari fixture bawaan
(`fixtures/legacy-app`, kode PHP 7 yang sengaja dibuat bermasalah) lengkap dengan catatan analis. Unduh lalu
buka di browser.

## CI

```bash
php scripts/audit.php . --out="$RUNNER_TEMP/php-audit" --target=8.4 --skip=rector --fail-on=wajib
```

Exit code setiap script: `0` ok, `1` salah pemakaian, `2` masalah environment (mis. tool belum terpasang),
`10` ada temuan di tingkat `--fail-on` atau di atasnya (`wajib` > `deprecated` > `perilaku`). Akhir output
merangkum jumlah temuan per tingkat.

## Memilih versi PHP target

- `--target` menentukan **apa yang diperiksa**: aturan sampai versi itu, Rector `UP_TO_PHP_xx`,
  `testVersion` PHPCompatibility, dan constraint Composer.
- PHP yang **menjalankan** `php -l` dan pemuatan class menentukan **apa yang dibuktikan**. Pakai versi target
  sebagai `php`, atau berikan `--php=/path/ke/php8.3`. `audit.php` memberi peringatan kalau keduanya berbeda.
- Belum punya PHP target? Pakai Homebrew `php@8.3`, paket distribusi (`php8.3-cli`), atau Docker:
  `docker run --rm -v "$PWD:$PWD" -w "$PWD" php:8.3-cli php ...` (tool analisa lalu butuh Composer di image,
  mis. disalin dari `composer:2`).
- Target yang lebih baru dari katalog aturan (mis. 8.6 sebelum aturan diperbarui) diterima dengan peringatan:
  perubahan di versi itu tidak diperiksa scanner.

## Yang diperiksa

| Area | Caranya |
|---|---|
| Fungsi, syntax, dan helper yang dihapus, deprecated, atau berubah perilaku (PHP 7.1 → 8.5) | Scanner berbasis tokenizer, sekitar 80 aturan dari panduan migrasi resmi php.net, difilter sesuai target |
| Kode yang dijaga pengecekan versi | `if (PHP_VERSION_ID < 80000)`, `is_php()`, `function_exists()`, dan sejenisnya dievaluasi terhadap versi target, jadi cabang lama yang tidak dijalankan tidak dihitung |
| Parse error dan deprecation saat kompilasi | `php -l` ke semua file dengan PHP target |
| Class yang tidak bisa dimuat lagi (`--load-classes`) | Setiap class aplikasi dimuat di PHP target pada salinan sementara: konflik signature (fatal sejak 8.0), class induk yang hilang, deprecation saat class di-link. Lewat autoload Composer, atau file biasa untuk proyek tanpa Composer |
| Library Composer | Constraint `php` setiap package di `composer.lock` vs target, package abandoned, versi terbaru di major yang sama, versi terbaru yang mendukung target (Packagist) |
| Ekstensi PHP | Ekstensi yang dibutuhkan package tapi sudah dihapus dari core di versi target (mcrypt, imap, dll.) |
| Pembanding | PHPCompatibility (phpcs) dan Rector `--dry-run` (menunjukkan apa yang bisa diperbaiki otomatis) |
| Ganti atau merge framework (opsional) | Patch lokal vs rilis resmi, API yang hilang/berubah, konflik override dengan aplikasi (aturan signature PHP), pemanggilan API yang hilang, key config. Adapter framework menambah pengecekan khusus ([`scripts/adapters/`](scripts/adapters/), CodeIgniter 3 sudah tersedia) |

Kode yang diaudit tidak pernah diubah, dan `composer` maupun Rector tidak pernah dijalankan di dalamnya.

## Laporan

- Satu file HTML mandiri. Bisa dipakai sebagai [Claude Artifact](https://claude.ai) atau dibuka lokal.
- Tampilan seperti dokumentasi: menu samping, satu halaman per repo dan per perbandingan, dashboard dan tujuan scan.
- **Riwayat:** setiap scan disimpan. Halaman repo punya pemilih versi (default scan terakhir) dan menunjukkan
  berapa temuan yang teratasi, baru, atau tetap dibanding scan sebelumnya.
- Setiap halaman punya anchor: `#<repo>` (scan terakhir) atau `#<repo>~<scan-id>` (scan tertentu).
- Catatan analis dengan perbandingan kode **Saat ini vs Disarankan**, yang wajib diuji agent di PHP target
  sebelum disarankan. `validate_notes.php` memeriksa formatnya.
- Bahasa Inggris atau Indonesia (`--lang=en|id`), tema terang dan gelap, tampilan ponsel.

## Privasi dan jaringan

- **Rahasia disensor** di setiap output tool dan sekali lagi pada seluruh laporan: token seperti `ghp_…` dan
  `glpat-…`, key AWS/Google/Stripe/Slack, JWT, private key, `user:password@` di URL, `password => '…'`,
  `$secret = '…'`, `DB_PASSWORD=…`, dan literal acak yang panjang. Semuanya tampil sebagai `«REDACTED»`.
- Path absolut lokal (folder home, folder sementara, store, folder tool ini) dibuang dari laporan.
- Akses jaringan saat audit: nama package publik ke `repo.packagist.org` (package privat dilewati), dan dengan
  `--load-classes`, `composer install` biasa untuk package yang terkunci di salinan sementara (dari sumber yang
  ditulis `composer.lock`, dengan kredensial Composer Anda sendiri). `--offline` mematikan keduanya.
- HTML memuat font IBM Plex dari Google Fonts. Render dengan `--offline` untuk halaman tanpa request eksternal sama sekali.
- Tidak ada telemetri. Laporan bisa memuat masalah keamanan kode yang diaudit, jadi bagikan dengan hati-hati.

## Struktur store

```
<store>/
  overview.json                     ringkasan lintas repo (versi lama disimpan di _overview/)
  <repo>/index.json                 daftar scan
  <repo>/scans/<scan-id>/           report.json, scan.json, lint.json, libs.json, runtime.json, phpcompat.json, rector.json, notes.json
  <perbandingan>/scans/<scan-id>/   compare.json, notes.json, diffs/
  .cache/                           metadata Packagist, cache Rector, salinan sementara untuk memuat class
```

Scan id berupa timestamp UTC (`20261022T131200Z`), jadi folder otomatis terurut menurut waktu.

## Tingkat temuan

| Key | Arti |
|---|---|
| `wajib` | Wajib diubah: error/fatal, atau kode tidak lagi berjalan seperti sebelumnya |
| `deprecated` | Masih jalan di target tapi memunculkan `E_DEPRECATED`; rusak di versi berikutnya |
| `perilaku` | Perilaku berubah: tidak error, tapi hasilnya bisa berbeda. Perlu review |
| `info` | Catatan, tidak mendesak |

Key-nya sama di kedua bahasa laporan.

## Batasan

Sudah diuji pada proyek publik berikut dengan PHP 8.5.12 (`audit.php --load-classes`, selebihnya default).
Waktu adalah total satu audit. Di lingkungan uji, install Composer berjalan lewat git, jadi install biasa lebih
cepat. False positive: 15 temuan scanner acak per proyek, dibaca satu per satu, sebelum dan sesudah perbaikan
yang dihasilkannya.

| Jenis proyek | Proyek (commit) | File PHP / baris | Waktu | Memori puncak Rector | Temuan: wajib / deprecated / perilaku | Memuat class di 8.5.12 | False positive |
|---|---|---|---|---|---|---|---|
| Composer + PSR-4 (Symfony) | symfony/demo (8d2e2ef) | 51 / 4.680 | 29 dtk | 142 MB | 0 / 0 / 0 | 33 dari 34 termuat, 0 gagal, 1 unresolved (class require-dev) | 2 dari 2 sebelumnya, kini tidak ada |
| Plugin WordPress, composer.json tanpa lock dan autoload | WordPress/wordpress-importer (2fd6589) | 239 / 52.985 | 35 dtk | 262 MB | 0 / 2 / 9 | 164 dari 215 termuat, 0 gagal, 51 unresolved (PHPUnit, core WordPress), 24 dilewati | 7 dari 15 sebelumnya, 2 dari 11 sesudahnya (18%) |
| Legacy, tanpa framework, tanpa Composer | osTicket (8d38b06) | 717 / 181.252 | 296 dtk (Rector 212 dtk) | 1.333 MB | 57 / 150 / 144 | 210 dari 287 termuat, 0 gagal, 77 unresolved (include dan konstanta dari bootstrap), 430 dilewati, 27 deprecation; 19 package terbundel diperiksa | 6 dari 15 sebelumnya, 3 dari 15 sesudahnya (20%) |
| CodeIgniter 3 | pocketarc/codeigniter (795dc3f) | 292 / 79.346 | 39 dtk | 275 MB | 113 / 17 / 30 (+34 di cabang yang tidak jalan di 8.5) | 139 dari 228 termuat, 0 gagal, 89 unresolved (PHPUnit, `CI_DB`), 8 deprecation | 7 dari 15 sebelumnya, 1 dari 15 sesudahnya (7%) |

- Sebagian besar false positive yang tersisa adalah perbandingan longgar (`== 0`) pada nilai yang saat runtime
  berupa integer, dan kode di cabang yang hanya jalan di PHP lama. Untuk mengenalinya dibutuhkan analisa aliran
  data, yang tidak dilakukan scanner. Temuan dengan confidence rendah perlu dicek sebelum masuk catatan.
- Di CodeIgniter 3, 11 dari 15 sampel temuan memang benar, tapi berada di driver database yang mungkin tidak
  dipakai aplikasi (`mysql`, `mssql`, `ibase`, `oci8`); lihat `references/ci3.md`.
- Laravel tidak diuji sebagai proyek tersendiri; strukturnya Composer + PSR-4 seperti symfony/demo. Drupal,
  Magento, dan framework lain belum diuji.
- Rector butuh sekitar 1,3 GB untuk kode 180 ribu baris; `--memory=2G` bawaan sudah cukup. Naikkan untuk kode
  yang lebih besar, atau pakai `--skip=rector`.

- Analisa statis tidak bisa melihat nilai saat runtime (mis. `null` yang dikirim ke fungsi bawaan). Laporan
  mencantumkan apa saja yang perlu diawasi saat aplikasi dijalankan di PHP target dengan `E_ALL`.
- Memuat class membuktikan bahwa class bisa **di-link** di PHP target, bukan bahwa perilakunya sama. Method
  tidak dijalankan. File yang menjalankan kode saat di-include (script entry, template) dilewati, tidak dimuat,
  dan setiap file class tetap dimuat, termasuk yang hanya dipakai aplikasi di versi PHP lama.
- Katalog aturan mencakup PHP 7.1 → 8.5 sesuai php.net. Perbarui saat ada versi PHP baru (`references/rules.md`).
- PHPCompatibility 10 masih alpha; scanner, `php -l`, dan pemuatan class tidak bergantung padanya.
- Didukung: Linux, macOS, WSL. Windows native belum diuji (script memakai `diff`, `git`, dan path POSIX).

## Struktur repo

```
SKILL.md              skill Claude Code (sekaligus satu-satunya skill di plugin)
AGENTS.md             instruksi untuk AI coding agent apa pun
.claude-plugin/       manifest plugin dan marketplace Claude Code
scripts/              audit, scanner, lint, library, memuat class, tool, perbandingan, riwayat, sensor, validasi, render
scripts/adapters/     adapter framework opsional (codeigniter3)
assets/               template laporan
references/           panduan aturan, catatan CodeIgniter 3
fixtures/             proyek contoh untuk tes (kode PHP 7 rusak, aplikasi Composer, aplikasi tanpa Composer, framework)
examples/             contoh laporan
tests/                run.sh (end-to-end) dan tes penyensoran
tools/composer.json   tool analisa (dipasang setup.sh, vendor/ tidak di-commit)
```

## Kontribusi, keamanan, lisensi

- [CONTRIBUTING.md](CONTRIBUTING.md): menambah aturan, adapter, dan tes; aturan penyensoran.
- [SECURITY.md](SECURITY.md): cara melaporkan rahasia yang lolos dari penyensoran.
- [CHANGELOG.md](CHANGELOG.md).
- MIT, lihat [LICENSE](LICENSE). Tool analisa yang dipasang `setup.sh` tetap memakai lisensinya masing-masing
  (Rector MIT, PHP_CodeSniffer BSD-3-Clause, PHPCompatibility LGPL-3.0, composer/semver MIT).
