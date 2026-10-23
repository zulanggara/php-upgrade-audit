<?php
/**
 * Breaking changes of popular packages that an upgrade to a PHP-8-ready version runs into, with the upstream
 * source for each. check_libs.php attaches an entry to a locked package when its installed version matches
 * "from"; the report shows it with the evidence links.
 *
 * Only add what the package's own UPGRADE/CHANGELOG/release notes say, and link that file at a tag (stable
 * links). When the claim can be run, run it on the target PHP and describe the result in "proof".
 *
 * Fields: package, from (Composer constraint on the installed version), to (version that brings the change),
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
];
