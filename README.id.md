# php-upgrade-audit

[![tests](https://github.com/zulanggara/php-upgrade-audit/actions/workflows/tests.yml/badge.svg)](https://github.com/zulanggara/php-upgrade-audit/actions/workflows/tests.yml)

[English](README.md) · **Bahasa Indonesia**

Perangkat audit **read-only** untuk memindahkan kode PHP ke versi PHP 8 yang lebih baru. Asalnya bisa PHP 7.x
atau 8.x yang lebih lama, dengan Laravel, Symfony, WordPress, CodeIgniter, atau tanpa framework. Target
default adalah **rilis PHP terbaru** dari versi minor terbaru yang dicakup aturan (ditanyakan ke php.net:
saat ini 8.5.12, dan 8.5.12 bila offline). Target lain juga bisa, misalnya `--target=8.3.30`.

Dirancang untuk dijalankan oleh AI coding agent (skill atau plugin Claude Code, atau agent lain lewat
`AGENTS.md`), tapi setiap langkahnya berupa script PHP biasa yang bisa Anda jalankan sendiri atau di CI.

Hasilnya terstandar: JSON per tool, catatan analis dengan format tetap, dan satu laporan HTML berisi halaman
per repo, riwayat setiap scan, dan dashboard.

- [Memakai lewat AI agent](#memakai-lewat-ai-agent): contoh perintah, sudah atau belum dipasang
- [Instalasi](#instalasi): skill atau plugin Claude Code, agent lain, manual
- [Menjalankan manual](#menjalankan-manual) · [CI](#ci) · [Memilih target](#memilih-versi-php-target)
- [Yang diperiksa](#yang-diperiksa) · [Bukti](#bukti-evidence) · [Laporan](#laporan) · [Update](#update) · [Privasi](#privasi-dan-jaringan) · [Batasan](#batasan)

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
/php-upgrade-audit ~/src/toko-saya                                # rilis PHP terbaru (8.5.x), bahasa Anda
/php-upgrade-audit ~/src/toko-saya ~/src/admin --target=8.3.30 --lang=id
/php-upgrade-audit ~/src/lama --target=8.2 --store=~/audit --name=lama
```

Sebagai plugin, perintahnya diberi namespace: `/php-upgrade-audit:php-upgrade-audit ~/src/toko-saya --target=8.4`.
Permintaan biasa juga bisa, Claude akan memilih skill-nya sendiri:

> audit ~/src/toko-saya untuk PHP 8.3 dan sebutkan library mana yang menghalangi upgrade

### Meminta versi PHP tertentu

Targetnya adalah versi yang Anda sebut: `8.3.30`, `8.4.27`, `8.5.12`, versi minor saja seperti `8.3` (rilis
terbarunya), atau `latest` (default: rilis terbaru dari minor terbaru yang dicakup aturan). Perubahan yang
baru muncul setelah target tidak dilaporkan (audit untuk 8.3 tidak menampilkan deprecation 8.4/8.5), dan
Rector serta PHPCompatibility memeriksa terhadap versi minor target. Untuk bukti runtime (`php -l`, memuat
class) agent butuh versi PHP itu terpasang, atau Docker (`--php=docker`), dan akan memberi tahu kalau
keduanya tidak ada (lihat
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
dipakai, dan lagi setelah plugin diperbarui. Supaya versi baru diterima otomatis, nyalakan auto-update sekali:
`/plugin` > Marketplaces > php-upgrade-audit > Enable auto-update (lihat [Update](#update)).

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
php $TOOL/scripts/audit.php /path/ke/repo --out=$STORE --name=aplikasi-saya --load-classes   # PHP terbaru; atau --target=8.3.30
#    PHP target belum terpasang tapi ada Docker: tambahkan --php=docker
#    log error dari aplikasi yang sudah jalan di PHP target (mis. disalin dari staging): tambahkan --logs=/path/ke/log (boleh berulang)

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

Temuan sebagai anotasi di pull request (GitHub code scanning, SARIF 2.1.0):

```yaml
- run: php scripts/audit.php . --out="$RUNNER_TEMP/php-audit" --skip=rector --sarif="$RUNNER_TEMP/php-upgrade.sarif"
- uses: github/codeql-action/upload-sarif@v3
  with:
    sarif_file: ${{ runner.temp }}/php-upgrade.sarif
    category: php-upgrade-audit
```

Temuan wajib menjadi error, deprecated menjadi warning, perilaku menjadi note; temuan library menunjuk baris
package-nya di `composer.lock`. `php scripts/sarif.php <report.json>` mengonversi scan lama mana pun.

Exit code setiap script: `0` ok, `1` salah pemakaian, `2` masalah environment (mis. tool belum terpasang),
`4` `render.php` menolak menulis laporan yang masih memuat rahasia, `10` ada temuan di tingkat `--fail-on` atau di atasnya (`wajib` > `deprecated` > `perilaku`). Akhir output
merangkum jumlah temuan per tingkat.

## Memilih versi PHP target

- `--target` menentukan **apa yang diperiksa**: aturan sampai versi itu, Rector `UP_TO_PHP_xx`,
  `testVersion` PHPCompatibility, dan constraint Composer.
- PHP yang **menjalankan** `php -l` dan pemuatan class menentukan **apa yang dibuktikan**. Pakai versi target
  sebagai `php`, atau berikan `--php=/path/ke/php8.3`. `audit.php` memberi peringatan kalau keduanya berbeda.
- Belum punya PHP target? Dengan Docker, `--php=docker` menjalankan `php -l`, scanner, dan pemuatan class di
  image resmi `php:<target>-cli` (`--php=docker:8.3` untuk versi lain, `--php=docker:<image>` untuk image
  sendiri). Satu container per audit, tanpa jaringan, repo di-mount read-only, dihapus di akhir; Composer tetap
  jalan di mesin Anda dengan versi PHP container sebagai platform. Selain itu: Homebrew `php@8.3` atau paket
  distribusi (`php8.3-cli`).
- `latest` (default) mengikuti php.net: saat PHP 8.5.13 rilis, targetnya 8.5.13. Bila sudah ada minor yang lebih
  baru dari cakupan aturan (mis. 8.6.0), target tetap di minor terbaru yang dicakup dan audit memberitahukannya;
  perbarui kit ini, atau berikan `--target=8.6.0` (diterima dengan peringatan: perubahan di 8.6 tidak diperiksa
  scanner).

## Yang diperiksa

| Area | Caranya |
|---|---|
| Fungsi, syntax, dan helper yang dihapus, deprecated, atau berubah perilaku (PHP 7.1 → 8.5) | Scanner berbasis tokenizer, sekitar 80 aturan dari panduan migrasi resmi php.net, difilter sesuai target |
| Kode yang dijaga pengecekan versi | `if (PHP_VERSION_ID < 80000)`, `is_php()`, `function_exists()`, dan sejenisnya dievaluasi terhadap versi target, jadi cabang lama yang tidak dijalankan tidak dihitung |
| `null` yang dikirim ke fungsi bawaan (8.1) | `json_decode()`, `strtolower()`, `trim()`, `new DateTime()`… yang menerima input request, data session, segmen URI, `env()` tanpa default, superglobal yang dibaca tanpa `isset()`, `?? null`, atau variabel yang diisi dari salah satunya. Setiap temuan menyebut argumen ke berapa dan dari mana nilainya. Daftar parameternya (1.400+ fungsi, constructor, dan static method) dibangkitkan dari PHP 8.5.12 |
| Log runtime (`--logs`) | File log CodeIgniter 3, Laravel/Monolog, dan PHP `error_log`: deprecation, warning, dan fatal error dikelompokkan per pesan dan file:baris, dipetakan ke aturan, dan ditandai bila scanner tidak melihatnya (biasanya `NULL` dari database). Log di `application/logs`, `storage/logs`, `var/log`, `logs` dibaca otomatis |
| Parse error dan deprecation saat kompilasi | `php -l` ke semua file dengan PHP target |
| Class yang tidak bisa dimuat lagi (`--load-classes`) | Setiap class aplikasi dimuat di PHP target pada salinan sementara: konflik signature (fatal sejak 8.0), class induk yang hilang, deprecation saat class di-link. Lewat autoload Composer, atau file biasa untuk proyek tanpa Composer |
| Library Composer | Constraint `php` setiap package di `composer.lock` vs target, package abandoned, versi terbaru di major yang sama, versi terbaru yang mendukung target (Packagist) |
| Security advisory | Kerentanan yang sudah diketahui (CVE/GHSA) di versi terpasang setiap package publik, dari API security advisories Packagist (data yang sama dengan `composer audit`), beserta versi terendah yang memperbaikinya dan mendukung target. Masing-masing dihitung wajib |
| Ekstensi PHP | Ekstensi yang dibutuhkan package tapi sudah dihapus dari core di versi target (mcrypt, imap, dll.) |
| Pembanding | PHPCompatibility (phpcs) dan Rector `--dry-run` (menunjukkan apa yang bisa diperbaiki otomatis) |
| Ganti atau merge framework (opsional) | Patch lokal vs rilis resmi, API yang hilang/berubah, konflik override dengan aplikasi (aturan signature PHP), pemanggilan API yang hilang, key config. Adapter framework menambah pengecekan khusus ([`scripts/adapters/`](scripts/adapters/), CodeIgniter 3 sudah tersedia) |

Kode yang diaudit tidak pernah diubah, dan `composer` maupun Rector tidak pernah dijalankan di dalamnya.

## Bukti (evidence)

Setiap kesimpulan menautkan sumber yang bisa dicek sendiri oleh pembaca:

- **Temuan scanner**: setiap aturan (~80) menautkan halaman migrasi php.net, entri `UPGRADING` php-src dari
  rilis yang membuat perubahan itu (dikutip persis, di cabang rilisnya), dan RFC yang dirujuk entri tersebut
  ([`scripts/rules_evidence.php`](scripts/rules_evidence.php)). Kutipannya muncul saat kursor diarahkan.
- **Library** menautkan halaman Packagist, repo sumber, dan halaman rilis versi yang disarankan; security
  advisory menautkan GitHub advisory dan catatan CVE-nya. Perubahan yang merusak di package populer (php-jwt 7,
  Guzzle 7, Monolog 2/3, Doctrine DBAL 3, PHPUnit 10, Swift Mailer, phpseclib 3, Laravel 9/10/11) disertai
  sumbernya sendiri, dibaca pada tag tertentu: misalnya firebase/php-jwt 7.0
  menolak kunci HMAC yang lebih pendek dari algoritmanya (HS256 butuh 32 byte), lengkap dengan changelog, PR,
  baris kode sumbernya, dan hasil uji di PHP 8.5.12 (v6.11.1 menerima kunci 12 byte, v7.2.1 melempar
  `Provided key is too short`). Lihat [`scripts/library_notes.php`](scripts/library_notes.php).
- **Catatan analis** memuat `evidence` (php.net, RFC, php-src, changelog, catatan rilis, kode sumber, advisory)
  dan, untuk setiap saran perubahan kode, `proof` hasil uji jalannya di PHP target. `validate_notes.php`
  memberi peringatan bila catatan wajib, deprecated, atau perubahan perilaku tidak punya sumber.

[`references/evidence.md`](references/evidence.md) berisi urutan sumber yang diutamakan, cara menautkan supaya
link tetap valid, dan perubahan yang sudah diverifikasi (cost bcrypt, ukuran kunci php-jwt, Guzzle 7, Monolog 2/3).

## Laporan

- Satu file HTML mandiri. Bisa dipakai sebagai [Claude Artifact](https://claude.ai) atau dibuka lokal.
- Tampilan seperti dokumentasi: menu samping, satu halaman per repo dan per perbandingan, dashboard dan tujuan scan.
- **Riwayat:** setiap scan disimpan. Halaman repo punya pemilih versi (default scan terakhir) dan menunjukkan
  berapa temuan yang teratasi, baru, atau tetap dibanding scan sebelumnya.
- Setiap halaman punya anchor: `#<repo>` (scan terakhir) atau `#<repo>~<scan-id>` (scan tertentu).
- Catatan analis dengan perbandingan kode **Saat ini vs Disarankan**, yang wajib diuji agent di PHP target
  sebelum disarankan, hasil uji jalannya, dan link [bukti](#bukti-evidence). `validate_notes.php` memeriksa
  formatnya. Hanya link `http(s)` yang ditampilkan.
- Setiap baris temuan menampilkan tingkat keyakinannya sendiri bila aturannya menyediakan, dan untuk argumen
  `null` menyebut argumen mana yang bisa null dan dari mana nilainya (juga di pesan SARIF).
- Bagian **Log runtime**: isi log error, dikelompokkan dan ditautkan ke aturan, dengan tanda "hanya di log"
  untuk baris yang tidak dilaporkan scanner, serta petunjuk memakai `--logs` bila belum ada log yang dibaca.
- Footer menyebut versi kit yang membuat laporan.
- Bahasa Inggris atau Indonesia (`--lang=en|id`), tema terang dan gelap, tampilan ponsel.

## Update

Skill menjalankan `scripts/update.sh` setiap kali audit dimulai, jadi salinan yang terpasang selalu ikut versi terbaru:

| Terpasang sebagai | Yang terjadi |
|---|---|
| git clone (`~/.claude/skills/...`, `~/tools/...`) | Dicek ke GitHub paling sering sekali sehari, lalu di-fast-forward ke `main` terbaru; `setup.sh` dijalankan ulang bila tool analisa berubah. Clone yang punya perubahan lokal atau di branch lain tidak pernah disentuh, hanya diberi tahu. |
| Plugin Claude Code | Claude Code memperbarui plugin sendiri setelah auto-update dinyalakan (`/plugin` > Marketplaces > php-upgrade-audit > Enable auto-update; default-nya mati untuk marketplace pihak ketiga). Kalau belum, `update.sh` menjalankan `claude plugin update` bila bisa, atau meminta Anda menjalankan `/plugin marketplace update php-upgrade-audit`. |
| Salinan unduhan | `update.sh` memberi tahu bila ada versi baru. |

Manual: `bash scripts/update.sh --force` (cek sekarang), `--check` (hanya melaporkan). Matikan dengan
`PHP_UPGRADE_AUDIT_NO_UPDATE=1` atau `--offline`.

Setiap versi diberi tag (`v1.3.0`) beserta changelog-nya di halaman
[Releases](https://github.com/zulanggara/php-upgrade-audit/releases); footer laporan menampilkan versi dan
commit yang menghasilkannya.

## Privasi dan jaringan

- **Rahasia disensor** di setiap output tool dan sekali lagi pada seluruh laporan: token seperti `ghp_…` dan
  `glpat-…`, key AWS/Google/Stripe/Slack, JWT, private key, `user:password@` di URL, `password => '…'`,
  `$secret = '…'`, `DB_PASSWORD=…`, dan literal acak yang panjang. Semuanya tampil sebagai `«REDACTED»`.
- Path absolut lokal (folder home, folder sementara, store, folder tool ini) dibuang dari laporan.
- Log runtime dibaca di mesin sendiri dan tidak dikirim ke mana pun. Yang disimpan hanya nama file log, lokasi
  relatif terhadap repo, dan pesan yang dinormalisasi: nilai yang berubah-ubah menjadi `N` atau `"…"`, alamat
  email disembunyikan, dan hasilnya disensor seperti output lain.
- Akses jaringan saat audit: nama package publik ke `repo.packagist.org` dan `packagist.org` (metadata dan
  security advisory; package privat dilewati), satu request ke `php.net` untuk rilis PHP terbaru bila target
  tidak disebut persis, image resmi `php` dari Docker Hub dengan `--php=docker`, dan dengan `--load-classes`,
  `composer install` biasa untuk package yang terkunci di salinan sementara (dari sumber yang ditulis
  `composer.lock`, dengan kredensial Composer Anda sendiri). `--offline` mematikan semuanya kecuali pull image
  Docker.
- `scripts/update.sh` mengecek versi baru kit ini ke GitHub paling sering sekali sehari (satu `git fetch`, atau
  satu request untuk `plugin.json` yang terpublikasi). `PHP_UPGRADE_AUDIT_NO_UPDATE=1` mematikannya.
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
