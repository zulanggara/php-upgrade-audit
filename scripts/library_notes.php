<?php
/**
 * Breaking changes of popular packages that an upgrade to a PHP-8-ready version runs into, with the upstream
 * source for each. check_libs.php attaches an entry to a locked package when its installed version matches
 * "from"; the report shows it with the evidence links.
 *
 * Only add what the package's own UPGRADE/CHANGELOG/release notes say, and link that file at a tag (stable
 * links). When the claim can be run, run it on the target PHP and describe the result in "proof".
 *
 * Fields: package, from (Composer constraint on the installed version), to (version that brings the change;
 * null with replaced_by => 'vendor/package' when the package has to be replaced),
 * sev (wajib | deprecated | perilaku | info), en/id => [title, body], evidence => [[kind, title, url], ...],
 * proof (optional, ['en' => ..., 'id' => ...]: what was run on which PHP and what it showed).
 */
return [
    [
        'package' => 'firebase/php-jwt', 'from' => '<7.0', 'to' => '7.0.0', 'sev' => 'wajib',
        'en' => ['HMAC keys shorter than the algorithm are rejected',
            'Since 7.0.0, JWT::encode()/decode() throw DomainException("Provided key is too short") when the key has fewer bits than the algorithm: HS256 needs at least 32 bytes, HS384 48, HS512 64; RSA keys need 2048 bits. Tokens signed or verified with a shorter secret stop working after the upgrade: rotate the key first.'],
        'id' => ['Kunci HMAC yang lebih pendek dari algoritmanya ditolak',
            'Sejak 7.0.0, JWT::encode()/decode() melempar DomainException("Provided key is too short") bila jumlah bit kunci lebih kecil dari algoritmanya: HS256 minimal 32 byte, HS384 48, HS512 64; kunci RSA minimal 2048 bit. Token yang ditandatangani atau diverifikasi dengan secret yang lebih pendek berhenti bekerja setelah upgrade: ganti kuncinya dulu.'],
        'evidence' => [
            ['kind' => 'changelog', 'title' => 'php-jwt CHANGELOG 7.0.0: "add key size validation" (security fix)', 'url' => 'https://github.com/firebase/php-jwt/blob/v7.0.0/CHANGELOG.md'],
            ['kind' => 'issue', 'title' => 'php-jwt PR #613: add key size validation', 'url' => 'https://github.com/firebase/php-jwt/pull/613'],
            ['kind' => 'source', 'title' => 'JWT::validateHmacKeyLength()', 'url' => 'https://github.com/firebase/php-jwt/blob/v7.0.0/src/JWT.php'],
        ],
        'proof' => [
            'en' => 'On PHP 8.5.12, JWT::encode() with HS256 and a 12-byte key: v6.11.1 returns a token, v7.2.1 throws DomainException: Provided key is too short. A 32-byte key works on both.',
            'id' => 'Di PHP 8.5.12, JWT::encode() dengan HS256 dan kunci 12 byte: v6.11.1 menghasilkan token, v7.2.1 melempar DomainException: Provided key is too short. Kunci 32 byte jalan di keduanya.',
        ],
    ],
    [
        'package' => 'guzzlehttp/guzzle', 'from' => '<7.0', 'to' => '7.0.0', 'sev' => 'wajib',
        'en' => ['Guzzle 7: typed signatures, removed options and classes',
            'Methods now have type hints and return types, so classes that extend Guzzle must update their signatures. The request options exceptions, save_to and pool_size are gone (use http_errors, sink, concurrency); ConnectException no longer extends RequestException and has no getResponse(); ClientInterface::VERSION is replaced by MAJOR_VERSION; UriTemplate and SeekException are removed; IDN support is off by default (needs ext-intl).'],
        'id' => ['Guzzle 7: signature bertipe, opsi dan class yang dihapus',
            'Method sekarang punya type hint dan return type, jadi class yang meng-extend Guzzle harus menyesuaikan signature-nya. Opsi request exceptions, save_to, dan pool_size dihapus (pakai http_errors, sink, concurrency); ConnectException bukan lagi turunan RequestException dan tidak punya getResponse(); ClientInterface::VERSION diganti MAJOR_VERSION; UriTemplate dan SeekException dihapus; dukungan IDN mati secara default (butuh ext-intl).'],
        'evidence' => [
            ['kind' => 'upgrade', 'title' => 'Guzzle UPGRADING.md: 6.0 to 7.0', 'url' => 'https://github.com/guzzle/guzzle/blob/7.0.0/UPGRADING.md'],
        ],
    ],
    [
        'package' => 'monolog/monolog', 'from' => '<2.0', 'to' => '2.0.0', 'sev' => 'wajib',
        'en' => ['Monolog 2: add*() and short level methods removed, no default handler',
            'addWarning(), addError() and the other add* methods, and emerg/crit/err/warn, are removed (use the PSR-3 methods warning(), error()...). An empty Logger no longer writes anywhere until a handler is set. Dates are formatted with timezone and microseconds, which can break log parsing. Custom handlers must implement close().'],
        'id' => ['Monolog 2: method add*() dan level singkat dihapus, tanpa handler default',
            'addWarning(), addError(), dan method add* lainnya, juga emerg/crit/err/warn, dihapus (pakai method PSR-3 warning(), error(), dst.). Logger tanpa handler tidak lagi menulis ke mana pun. Tanggal ditulis dengan zona waktu dan mikrodetik, yang bisa merusak parsing log. Handler buatan sendiri harus mengimplementasikan close().'],
        'evidence' => [
            ['kind' => 'upgrade', 'title' => 'Monolog UPGRADE.md: 2.0.0', 'url' => 'https://github.com/Seldaek/monolog/blob/2.0.0/UPGRADE.md'],
        ],
    ],
    [
        'package' => 'monolog/monolog', 'from' => '<3.0', 'to' => '3.0.0', 'sev' => 'perilaku',
        'en' => ['Monolog 3: records are LogRecord objects, levels are an enum',
            'Requires PHP 8.1. Formatters, handlers and processors receive a LogRecord object instead of an array ($record->context instead of $record[\'context\'], array access still works); levels are the Monolog\\Level enum and Logger::WARNING and friends are deprecated; properties are typed, so subclasses that redeclare them must add the same types.'],
        'id' => ['Monolog 3: record berupa objek LogRecord, level berupa enum',
            'Butuh PHP 8.1. Formatter, handler, dan processor menerima objek LogRecord, bukan array ($record->context alih-alih $record[\'context\'], akses array masih jalan); level memakai enum Monolog\\Level dan Logger::WARNING dkk. deprecated; properti sudah bertipe, jadi subclass yang mendeklarasikan ulang harus memakai tipe yang sama.'],
        'evidence' => [
            ['kind' => 'upgrade', 'title' => 'Monolog UPGRADE.md: 3.0.0', 'url' => 'https://github.com/Seldaek/monolog/blob/3.0.0/UPGRADE.md'],
        ],
    ],
    [
        'package' => 'doctrine/dbal', 'from' => '<3.0', 'to' => '3.0.0', 'sev' => 'wajib',
        'en' => ['DBAL 3: new result API, removed Connection methods and FetchMode',
            'Statement::execute() returns a Result; fetch()/fetchAll()/fetchColumn() become fetchNumeric()/fetchAssociative()/fetchOne() and fetchAll*(); FetchMode and setFetchMode() are gone and a Statement is no longer iterable (use iterateAssociative()). Connection::query(), exec() and executeUpdate() are removed; DBALException is renamed Doctrine\\DBAL\\Exception; a user-provided PDO instance is no longer supported.'],
        'id' => ['DBAL 3: API result baru, method Connection dan FetchMode dihapus',
            'Statement::execute() mengembalikan Result; fetch()/fetchAll()/fetchColumn() menjadi fetchNumeric()/fetchAssociative()/fetchOne() dan fetchAll*(); FetchMode dan setFetchMode() dihapus dan Statement tidak bisa di-iterate lagi (pakai iterateAssociative()). Connection::query(), exec(), dan executeUpdate() dihapus; DBALException berganti nama menjadi Doctrine\\DBAL\\Exception; instance PDO dari luar tidak didukung lagi.'],
        'evidence' => [
            ['kind' => 'upgrade', 'title' => 'Doctrine DBAL UPGRADE.md: Upgrade to 3.0', 'url' => 'https://github.com/doctrine/dbal/blob/3.0.0/UPGRADE.md',
             'quote' => 'The `Doctrine\DBAL\DBALException` class has been renamed to `Doctrine\DBAL\Exception`.'],
        ],
    ],
    [
        'package' => 'phpunit/phpunit', 'from' => '<10.0', 'to' => '10.0.0', 'sev' => 'wajib',
        'en' => ['PHPUnit 10: needs PHP 8.1, removed assertions, TestListener and setMethods()',
            'PHP 7.3, 7.4 and 8.0 are no longer supported. Removed: TestListener (replaced by the event system), MockBuilder::setMethods() and setMethodsExcept(), assertRegExp(), assertFileNotExists(), assertDirectoryNotExists() and the other *Not* variants, the Prophecy integration. Non-static and non-public data providers are deprecated; the public methods of Assert and TestCase are final.'],
        'id' => ['PHPUnit 10: butuh PHP 8.1, assertion, TestListener, dan setMethods() dihapus',
            'PHP 7.3, 7.4, dan 8.0 tidak didukung lagi. Dihapus: TestListener (diganti event system), MockBuilder::setMethods() dan setMethodsExcept(), assertRegExp(), assertFileNotExists(), assertDirectoryNotExists() dan varian *Not* lainnya, integrasi Prophecy. Data provider non-static dan non-public deprecated; method public Assert dan TestCase sekarang final.'],
        'evidence' => [
            ['kind' => 'changelog', 'title' => 'PHPUnit ChangeLog-10.0.md', 'url' => 'https://github.com/sebastianbergmann/phpunit/blob/10.0.0/ChangeLog-10.0.md',
             'quote' => 'PHP 7.3, PHP 7.4, and PHP 8.0 are no longer supported'],
        ],
    ],
    [
        'package' => 'swiftmailer/swiftmailer', 'from' => '*', 'to' => null, 'replaced_by' => 'symfony/mailer', 'sev' => 'wajib',
        'en' => ['Swift Mailer is no longer maintained: move to Symfony Mailer',
            'Swift Mailer stopped being maintained at the end of November 2021. Its successor is symfony/mailer, which has a different API (Mailer, Email, transports by DSN), so the code that builds and sends mail must be rewritten.'],
        'id' => ['Swift Mailer tidak dipelihara lagi: pindah ke Symfony Mailer',
            'Swift Mailer berhenti dipelihara akhir November 2021. Penggantinya symfony/mailer dengan API berbeda (Mailer, Email, transport lewat DSN), jadi kode yang menyusun dan mengirim email harus ditulis ulang.'],
        'evidence' => [
            ['kind' => 'docs', 'title' => 'Swift Mailer README at v6.3.0', 'url' => 'https://github.com/swiftmailer/swiftmailer/blob/v6.3.0/README.md',
             'quote' => 'Swiftmailer will stop being maintained at the end of November 2021.'],
        ],
    ],
    [
        'package' => 'phpseclib/phpseclib', 'from' => '<3.0', 'to' => '3.0.0', 'sev' => 'perilaku',
        'en' => ['phpseclib 3: classes move to the phpseclib3 namespace',
            'phpseclib 3.0 roots its PSR-4 autoloading at \\phpseclib3 (2.0: \\phpseclib), so every use/import of a phpseclib class changes. 2.0 and 3.0 are both long term support branches; read the 3.0 documentation (phpseclib.com) before switching.'],
        'id' => ['phpseclib 3: class pindah ke namespace phpseclib3',
            'phpseclib 3.0 memakai autoload PSR-4 dengan namespace \\phpseclib3 (2.0: \\phpseclib), jadi setiap use/import class phpseclib berubah. 2.0 dan 3.0 sama-sama cabang long term support; baca dokumentasi 3.0 (phpseclib.com) sebelum pindah.'],
        'evidence' => [
            ['kind' => 'docs', 'title' => 'phpseclib README at 3.0.0: branches', 'url' => 'https://github.com/phpseclib/phpseclib/blob/3.0.0/README.md',
             'quote' => 'PSR-4 autoloading with namespace rooted at `\phpseclib3`'],
        ],
    ],
    [
        'package' => 'laravel/framework', 'from' => '<9.0', 'to' => '9.0', 'sev' => 'wajib',
        'en' => ['Laravel 9: PHP 8.0.2, Flysystem 3 and Symfony Mailer',
            'Laravel 9 requires PHP 8.0.2. Storage moved from Flysystem 1.x to 3.x: put/write/writeStream now overwrite existing files, and the S3/FTP/SFTP drivers need their own packages. Mail moved from SwiftMailer to Symfony Mailer: Mailgun and Postmark need new transport packages and Swift-specific code must change.'],
        'id' => ['Laravel 9: PHP 8.0.2, Flysystem 3, dan Symfony Mailer',
            'Laravel 9 butuh PHP 8.0.2. Storage pindah dari Flysystem 1.x ke 3.x: put/write/writeStream sekarang menimpa file yang ada, dan driver S3/FTP/SFTP butuh package sendiri. Mail pindah dari SwiftMailer ke Symfony Mailer: Mailgun dan Postmark butuh package transport baru dan kode khusus Swift harus diubah.'],
        'evidence' => [
            ['kind' => 'upgrade', 'title' => 'Laravel upgrade guide 8.x to 9.0', 'url' => 'https://github.com/laravel/docs/blob/9.x/upgrade.md',
             'quote' => 'Laravel now requires PHP 8.0.2 or greater.'],
        ],
    ],
    [
        'package' => 'laravel/framework', 'from' => '<10.0', 'to' => '10.0', 'sev' => 'wajib',
        'en' => ['Laravel 10: PHP 8.1, DB expressions and $dates',
            'Laravel 10 requires PHP 8.1 and Composer 2.2. Database expressions (DB::raw) can no longer be cast to string: use getValue($grammar). The deprecated Eloquent $dates property is removed: use $casts with "datetime".'],
        'id' => ['Laravel 10: PHP 8.1, ekspresi DB, dan $dates',
            'Laravel 10 butuh PHP 8.1 dan Composer 2.2. Ekspresi database (DB::raw) tidak bisa di-cast ke string lagi: pakai getValue($grammar). Properti Eloquent $dates yang deprecated dihapus: pakai $casts dengan "datetime".'],
        'evidence' => [
            ['kind' => 'upgrade', 'title' => 'Laravel upgrade guide 9.x to 10.0', 'url' => 'https://github.com/laravel/docs/blob/10.x/upgrade.md',
             'quote' => 'Laravel now requires PHP 8.1.0 or greater.'],
        ],
    ],
    [
        'package' => 'laravel/framework', 'from' => '<11.0', 'to' => '11.0', 'sev' => 'wajib',
        'en' => ['Laravel 11: PHP 8.2, column changes keep no modifiers, float/double types',
            'Laravel 11 requires PHP 8.2. When a migration modifies a column, every modifier to keep (unsigned, default, comment...) must be repeated or it is dropped. The double and float column types were rewritten to be the same on every database. The new, slimmer application structure is not recommended for upgraded applications: Laravel 11 also supports the Laravel 10 structure.'],
        'id' => ['Laravel 11: PHP 8.2, ubah kolom tanpa modifier lama, tipe float/double',
            'Laravel 11 butuh PHP 8.2. Saat migration mengubah kolom, semua modifier yang ingin dipertahankan (unsigned, default, comment...) harus ditulis ulang atau akan hilang. Tipe kolom double dan float ditulis ulang supaya sama di semua database. Struktur aplikasi baru yang lebih ramping tidak disarankan untuk aplikasi hasil upgrade: Laravel 11 tetap mendukung struktur Laravel 10.'],
        'evidence' => [
            ['kind' => 'upgrade', 'title' => 'Laravel upgrade guide 10.x to 11.0', 'url' => 'https://github.com/laravel/docs/blob/11.x/upgrade.md',
             'quote' => 'Laravel now requires PHP 8.2.0 or greater.'],
        ],
    ],
];
