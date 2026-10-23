<?php
/**
 * Rule catalogue of the PHP 7.x -> 8.5 code scanner.
 *
 * Source: the official php.net migration guides (migrationXY.incompatible / migrationXY.deprecated).
 * Titles and fixes here are Indonesian; English versions live in rules_en.php (render --lang=en).
 *
 * sev:  wajib      = must fix: error/fatal/removed by 8.5, code breaks
 *       deprecated = still runs on 8.5 but emits E_DEPRECATED, breaks in PHP 9
 *       perilaku   = behaviour change: no error but the result can differ, needs review
 *       info       = soft-deprecated / note, not urgent
 * conf: tinggi | sedang | rendah  (high | medium | low: how likely a hit is a real problem)
 *
 * kind and its parameters (read by scan_code.php):
 *   func         names[]                  global function calls
 *   func_prefix  prefixes[]               functions with a prefix (removed extensions)
 *   method       names[]                  ->name( or ::name(
 *   const        names[] / prefixes[]     global constants
 *   class_const  class, names[]/prefixes[]  Class::CONSTANT
 *   var          names[]                  special variables
 *   cast         casts[]                  cast spelling, e.g. (integer)
 *   new          classes[]                new Class(
 *   ini          names[], funcs[]?        ini_set/ini_get/ini_restore with a literal name (funcs limits the functions)
 *   special      check                    custom check implemented in scan_code.php
 * Optional argument filters for func/method: argc => ['<', 5], arg_token => [...],
 *   arg_string => ['index' => 0|'any', 'values' => [...]], arg_is_string => index
 * Optional for func/method: only_if_mentioned => 'Name' or ['a', 'b'] (the rule only applies in files that mention
 *   one of them, for generic names such as ->contains() or is_resource()).
 * Optional: rector => name of the Rector rule that fixes it (shown in the report).
 * Sources besides ref live in rules_evidence.php (keyed by id; every rule has an entry). Optional here:
 *   evidence => [['kind' => ..., 'title' => ..., 'url' => 'https://...'], ...] for more (references/evidence.md).
 */

const MIG = 'https://www.php.net/manual/en/';

return [
    // ---------------------------------------------------------------- 7.x
    ['id' => 'EXT-MYSQL', 'sev' => 'wajib', 'ver' => '7.0', 'conf' => 'tinggi', 'kind' => 'func_prefix', 'prefixes' => ['mysql_'],
        'title' => 'Ekstensi mysql_* sudah dihapus', 'fix' => 'Ganti ke PDO atau mysqli.', 'ref' => MIG . 'migration70.removed-exts-sapis.php'],
    ['id' => 'EXT-EREG', 'sev' => 'wajib', 'ver' => '7.0', 'conf' => 'tinggi', 'kind' => 'func', 'names' => ['ereg', 'eregi', 'ereg_replace', 'eregi_replace', 'split', 'spliti', 'sql_regcase'],
        'title' => 'Fungsi ereg/POSIX regex sudah dihapus', 'fix' => 'Ganti ke preg_match / preg_replace / preg_split / explode.', 'ref' => MIG . 'migration70.removed-exts-sapis.php'],
    ['id' => 'EXT-MCRYPT', 'sev' => 'wajib', 'ver' => '7.2', 'conf' => 'tinggi', 'kind' => 'func_prefix', 'prefixes' => ['mcrypt_', 'mdecrypt_'],
        'title' => 'Ekstensi mcrypt dihapus dari core (pindah ke PECL)', 'fix' => 'Ganti ke openssl_encrypt/openssl_decrypt atau sodium_*. Pastikan data lama tetap bisa didekripsi (mode & padding).', 'ref' => MIG . 'migration71.deprecated.php'],
    ['id' => 'EXT-74-REMOVED', 'sev' => 'wajib', 'ver' => '7.4', 'conf' => 'tinggi', 'kind' => 'func_prefix', 'prefixes' => ['wddx_', 'recode_', 'ibase_', 'fbird_'],
        'title' => 'Ekstensi wddx / recode / interbase dihapus dari core', 'fix' => 'Ganti ke JSON/serialize, iconv/mbstring, atau PDO_Firebird.', 'ref' => MIG . 'migration74.removed-extensions.php'],

    // ---------------------------------------------------------------- 8.0 dihapus
    ['id' => 'P80-REMOVED-FUNC', 'rector' => 'ReplaceEachAssignmentWithKeyCurrentRector', 'sev' => 'wajib', 'ver' => '8.0', 'conf' => 'tinggi', 'kind' => 'func',
        'names' => ['each', 'create_function', 'read_exif_data', 'hebrevc', 'convert_cyr_string', 'money_format', 'ezmlm_hash', 'restore_include_path',
            'get_magic_quotes_gpc', 'get_magic_quotes_runtime', 'fgetss', 'gzgetss', 'gmp_random', 'ldap_sort', 'ldap_control_paged_result',
            'ldap_control_paged_result_response', 'image2wbmp', 'png2wbmp', 'jpeg2wbmp', 'is_real', 'oci_internal_debug', 'ociinternaldebug',
            'mbereg', 'mberegi', 'mbereg_replace', 'mberegi_replace', 'mbsplit', 'mbereg_match', 'mbereg_search', 'mbereg_search_pos',
            'mbereg_search_regs', 'mbereg_search_init', 'mbereg_search_getregs', 'mbereg_search_getpos', 'mbereg_search_setpos', 'mbregex_encoding'],
        'title' => 'Fungsi sudah dihapus di PHP 8.0 (Call to undefined function)',
        'fix' => 'each() -> foreach; create_function() -> function(){}; is_real() -> is_float(); get_magic_quotes_*() -> hapus (selalu false); money_format() -> NumberFormatter; fgetss() -> fgets()+strip_tags().',
        'ref' => MIG . 'migration80.incompatible.php'],
    ['id' => 'P80-DEFINE-CI', 'sev' => 'wajib', 'ver' => '8.0', 'conf' => 'sedang', 'kind' => 'func', 'names' => ['define'], 'argc' => ['>=', 3],
        'title' => 'define() dengan argumen ke-3 (konstanta case-insensitive) tidak didukung lagi',
        'fix' => 'Hapus argumen ke-3 dan pastikan semua pemakaian konstanta memakai huruf besar/kecil yang sama persis.', 'ref' => MIG . 'migration80.incompatible.php'],
    ['id' => 'P80-PARSE-STR-1ARG', 'sev' => 'wajib', 'ver' => '8.0', 'conf' => 'tinggi', 'kind' => 'func', 'names' => ['parse_str', 'mb_parse_str'], 'argc' => ['==', 1],
        'title' => 'parse_str() tanpa argumen hasil sudah dihapus (ArgumentCountError)',
        'fix' => 'parse_str($str, $hasil); lalu pakai $hasil[...].', 'ref' => MIG . 'migration80.incompatible.php'],
    ['id' => 'P80-CRYPT-NOSALT', 'sev' => 'wajib', 'ver' => '8.0', 'conf' => 'tinggi', 'kind' => 'func', 'names' => ['crypt'], 'argc' => ['==', 1],
        'title' => 'crypt() tanpa salt sudah tidak boleh (ArgumentCountError)',
        'fix' => 'Untuk password pakai password_hash()/password_verify().', 'ref' => MIG . 'migration80.incompatible.php'],
    ['id' => 'P80-MKTIME-NOARG', 'sev' => 'wajib', 'ver' => '8.0', 'conf' => 'tinggi', 'kind' => 'func', 'names' => ['mktime', 'gmmktime'], 'argc' => ['==', 0],
        'title' => 'mktime()/gmmktime() wajib minimal satu argumen', 'fix' => 'Ganti ke time().', 'ref' => MIG . 'migration80.incompatible.php'],
    ['id' => 'P80-IMPLODE-REVERSED', 'rector' => 'ConsistentImplodeRector', 'sev' => 'wajib', 'ver' => '8.0', 'conf' => 'sedang', 'kind' => 'func', 'names' => ['implode', 'join'], 'argc' => ['==', 2], 'arg_is_string' => 1,
        'title' => 'implode($array, $glue) dengan urutan terbalik sudah dihapus (TypeError)',
        'fix' => 'Tukar urutan: implode($glue, $array).', 'ref' => MIG . 'migration80.incompatible.php'],
    ['id' => 'P80-AUTOLOAD-FUNC', 'sev' => 'wajib', 'ver' => '8.0', 'conf' => 'tinggi', 'kind' => 'special', 'check' => 'autoload_def',
        'title' => 'Fungsi __autoload() sudah tidak dipanggil lagi', 'fix' => 'Daftarkan lewat spl_autoload_register() atau pakai autoload Composer.', 'ref' => MIG . 'migration80.incompatible.php'],
    ['id' => 'P80-PHP4-CTOR', 'rector' => 'Php4ConstructorRector', 'sev' => 'wajib', 'ver' => '8.0', 'conf' => 'tinggi', 'kind' => 'special', 'check' => 'php4_ctor',
        'title' => 'Constructor gaya PHP 4 (method bernama sama dengan class) tidak lagi dipanggil otomatis',
        'fix' => 'Ganti nama method menjadi __construct(). Tanpa ini object dibuat tanpa inisialisasi, tanpa error.', 'ref' => MIG . 'migration80.incompatible.php'],
    ['id' => 'P80-CURLY-OFFSET', 'rector' => 'CurlyToSquareBracketArrayStringRector', 'sev' => 'wajib', 'ver' => '8.0', 'conf' => 'tinggi', 'kind' => 'special', 'check' => 'curly_offset',
        'title' => 'Akses offset dengan kurung kurawal $str{0} sudah dihapus (parse error)', 'fix' => 'Ganti ke $str[0].', 'ref' => MIG . 'migration80.incompatible.php'],
    ['id' => 'P80-REMOVED-CAST', 'sev' => 'wajib', 'ver' => '8.0', 'conf' => 'tinggi', 'kind' => 'special', 'check' => 'removed_cast',
        'title' => 'Cast (real) dan (unset) sudah dihapus (parse error)', 'fix' => '(real) -> (float); (unset)$x -> null.', 'ref' => MIG . 'migration80.incompatible.php'],
    ['id' => 'P80-PHP-ERRORMSG', 'sev' => 'wajib', 'ver' => '8.0', 'conf' => 'tinggi', 'kind' => 'var', 'names' => ['$php_errormsg'],
        'title' => '$php_errormsg tidak ada lagi (track_errors dihapus)', 'fix' => 'Pakai error_get_last().', 'ref' => MIG . 'migration80.incompatible.php'],
    ['id' => 'P80-REMOVED-CONST', 'sev' => 'wajib', 'ver' => '8.0', 'conf' => 'tinggi', 'kind' => 'const',
        'names' => ['INPUT_REQUEST', 'INPUT_SESSION', 'FILTER_SANITIZE_MAGIC_QUOTES', 'FILTER_FLAG_SCHEME_REQUIRED', 'FILTER_FLAG_HOST_REQUIRED',
            'INTL_IDNA_VARIANT_2003', 'MB_OVERLOAD_MAIL', 'MB_OVERLOAD_STRING', 'MB_OVERLOAD_REGEX', 'ASSERT_QUIET_EVAL', 'AI_IDN_ALLOW_UNASSIGNED', 'AI_IDN_USE_STD3_ASCII_RULES'],
        'title' => 'Konstanta sudah dihapus di PHP 8.0 (Undefined constant)',
        'fix' => 'FILTER_SANITIZE_MAGIC_QUOTES -> FILTER_SANITIZE_ADD_SLASHES; FILTER_FLAG_*_REQUIRED -> hapus (FILTER_VALIDATE_URL selalu mewajibkan scheme & host).', 'ref' => MIG . 'migration80.incompatible.php'],
    ['id' => 'P80-REMOVED-INI', 'sev' => 'perilaku', 'ver' => '7.1–8.1', 'conf' => 'tinggi', 'kind' => 'ini', 'funcs' => ['ini_set', 'ini_alter'],
        'names' => ['track_errors', 'mbstring.func_overload', 'assert.quiet_eval', 'pdo_odbc.db2_instance_name', 'log_errors_max_len', 'session.hash_function',
            'session.hash_bits_per_character', 'session.entropy_file', 'session.entropy_length', 'safe_mode', 'sql.safe_mode', 'mysqlnd.fetch_data_copy', 'opcache.consistency_checks'],
        'title' => 'Direktif INI sudah dihapus (ini_set/ini_get mengembalikan false, tanpa error)',
        'fix' => 'Tidak error, tapi setting-nya tidak berlaku lagi. Hapus pemanggilannya dan pastikan logika yang bergantung pada nilainya tetap benar.', 'ref' => MIG . 'migration80.incompatible.php'],
    ['id' => 'EXT-OLD-DB', 'sev' => 'wajib', 'ver' => '5.4/7.0', 'conf' => 'sedang', 'kind' => 'func_prefix', 'prefixes' => ['mssql_', 'sybase_', 'sqlite_'],
        'title' => 'Ekstensi mssql / sybase / sqlite (lama) sudah dihapus', 'fix' => 'Ganti ke PDO (pdo_sqlsrv, pdo_dblib, pdo_sqlite) atau SQLite3.', 'ref' => MIG . 'migration70.removed-exts-sapis.php'],
    ['id' => 'P80-REFLECTION-DEPR', 'sev' => 'deprecated', 'ver' => '8.0', 'conf' => 'sedang', 'kind' => 'method', 'names' => ['getClass', 'isArray', 'isCallable'],
        'title' => 'ReflectionParameter::getClass()/isArray()/isCallable() deprecated', 'fix' => 'Pakai ReflectionParameter::getType(). Abaikan jika object-nya bukan ReflectionParameter.', 'ref' => MIG . 'migration80.deprecated.php'],
    ['id' => 'P80-LIBXML-ENTITY', 'sev' => 'deprecated', 'ver' => '8.0', 'conf' => 'tinggi', 'kind' => 'func', 'names' => ['libxml_disable_entity_loader'],
        'title' => 'libxml_disable_entity_loader() deprecated', 'fix' => 'Hapus; libxml >= 2.9 sudah menonaktifkan external entity secara default.', 'ref' => MIG . 'migration80.deprecated.php'],
    ['id' => 'P80-ZIP-PROCEDURAL', 'sev' => 'deprecated', 'ver' => '8.0', 'conf' => 'tinggi', 'kind' => 'func',
        'names' => ['zip_open', 'zip_read', 'zip_close', 'zip_entry_name', 'zip_entry_open', 'zip_entry_read', 'zip_entry_close', 'zip_entry_filesize', 'zip_entry_compressedsize', 'zip_entry_compressionmethod'],
        'title' => 'API Zip prosedural deprecated', 'fix' => 'Pakai class ZipArchive.', 'ref' => MIG . 'migration80.deprecated.php'],
    ['id' => 'P80-PGSQL-ALIAS', 'sev' => 'deprecated', 'ver' => '8.0', 'conf' => 'tinggi', 'kind' => 'func',
        'names' => ['pg_errormessage', 'pg_numrows', 'pg_numfields', 'pg_cmdtuples', 'pg_fieldname', 'pg_fieldsize', 'pg_fieldtype', 'pg_fieldnum', 'pg_result',
            'pg_fieldprtlen', 'pg_fieldisnull', 'pg_freeresult', 'pg_getlastoid', 'pg_locreate', 'pg_lounlink', 'pg_loopen', 'pg_loclose', 'pg_loread',
            'pg_lowrite', 'pg_loreadall', 'pg_loimport', 'pg_loexport', 'pg_setclientencoding', 'pg_clientencoding'],
        'title' => 'Alias lama fungsi pg_* deprecated', 'fix' => 'Pakai nama baru, mis. pg_num_rows(), pg_fetch_result(), pg_last_error().', 'ref' => MIG . 'migration80.deprecated.php'],
    ['id' => 'P80-ENCHANT', 'sev' => 'deprecated', 'ver' => '8.0', 'conf' => 'tinggi', 'kind' => 'func',
        'names' => ['enchant_broker_set_dict_path', 'enchant_broker_get_dict_path', 'enchant_dict_add_to_personal', 'enchant_dict_is_in_session', 'enchant_broker_free', 'enchant_broker_free_dict'],
        'title' => 'Fungsi enchant lama deprecated', 'fix' => 'Lihat pengganti di catatan migrasi.', 'ref' => MIG . 'migration80.deprecated.php'],

    // ---------------------------------------------------------------- 8.0 perubahan perilaku
    ['id' => 'P80-CONCAT-PRECEDENCE', 'sev' => 'perilaku', 'ver' => '8.0', 'conf' => 'tinggi', 'kind' => 'special', 'check' => 'concat_arith',
        'title' => 'Prioritas operator "." vs "+"/"-" berubah', 'fix' => '"Total: " . $a + $b dulu = ("Total: " . $a) + $b, sekarang = "Total: " . ($a + $b). Beri kurung eksplisit.', 'ref' => MIG . 'migration80.incompatible.php'],
    ['id' => 'P80-LOOSE-CMP', 'sev' => 'perilaku', 'ver' => '8.0', 'conf' => 'rendah', 'kind' => 'special', 'check' => 'loose_cmp',
        'title' => 'Perbandingan longgar (==, !=, switch) dengan 0 atau string kosong berubah hasil',
        'fix' => '0 == "abc" dan 0 == "" dulu true, sekarang false. Cek apakah variabelnya bisa berisi string non-angka; pakai === atau cast eksplisit.', 'ref' => MIG . 'migration80.incompatible.php'],
    ['id' => 'P80-SUBSTR-FALSE', 'sev' => 'perilaku', 'ver' => '8.0', 'conf' => 'sedang', 'kind' => 'special', 'check' => 'substr_false',
        'title' => 'substr()/mb_substr() di luar batas sekarang mengembalikan "" bukan false', 'fix' => 'Cek === false tidak akan pernah true lagi. Ganti ke === "" atau cek panjang string.', 'ref' => MIG . 'migration80.incompatible.php'],
    ['id' => 'P80-IS-RESOURCE', 'sev' => 'perilaku', 'ver' => '8.0', 'conf' => 'sedang', 'kind' => 'func', 'names' => ['is_resource', 'get_resource_type'],
        // Only files that create one of the resources that became objects; file handles and DB results did not.
        'only_if_mentioned' => ['curl_', 'imagecreate', 'xml_parser_create', 'socket_create', 'socket_accept', 'openssl_', 'ftp_connect', 'ftp_ssl_connect',
            'finfo_open', 'ldap_connect', 'pg_connect', 'pg_pconnect', 'imap_open', 'dba_open', 'dba_popen', 'odbc_connect', 'odbc_pconnect', 'sem_get',
            'shm_attach', 'msg_get_queue', 'shmop_open', 'deflate_init', 'inflate_init', 'xmlwriter_open', 'enchant_broker', 'pspell_new', 'imagecreatefrom'],
        'title' => 'Banyak resource sekarang jadi object (curl, gd, xml, socket, openssl; 8.1: ftp, finfo, ldap, pgsql, imap; 8.4: dba, odbc)',
        'fix' => 'is_resource($ch) jadi false untuk CurlHandle, GdImage, dll. Ganti ke cek !== false.', 'ref' => MIG . 'migration80.incompatible.php'],
    ['id' => 'P80-PDO-ERRMODE', 'sev' => 'perilaku', 'ver' => '8.0', 'conf' => 'sedang', 'kind' => 'new', 'classes' => ['PDO'],
        'title' => 'PDO default ERRMODE_EXCEPTION (8.0); MySQL/SQLite mengembalikan int/float asli (8.1)',
        'fix' => 'Query gagal sekarang melempar PDOException, bukan return false. Angka hasil fetch bisa bertipe int, jadi cek === "1" bisa gagal.', 'ref' => MIG . 'migration81.incompatible.php'],
    ['id' => 'P80-SETLOCALE', 'sev' => 'perilaku', 'ver' => '8.0', 'conf' => 'rendah', 'kind' => 'func', 'names' => ['setlocale'],
        'title' => 'Locale default selalu "C"; float->string tidak lagi ikut locale; strtolower dll tidak ikut locale (8.2)',
        'fix' => 'Cek format angka/desimal dan konversi huruf yang bergantung pada setlocale().', 'ref' => MIG . 'migration82.incompatible.php'],
    ['id' => 'P80-ASSERT-STRING', 'sev' => 'perilaku', 'ver' => '8.0', 'conf' => 'tinggi', 'kind' => 'func', 'names' => ['assert'], 'arg_is_string' => 0,
        'title' => 'assert() dengan string tidak lagi dievaluasi sebagai kode', 'fix' => 'String non-kosong selalu lolos. Tulis sebagai ekspresi: assert($a > 0).', 'ref' => MIG . 'migration80.incompatible.php'],
    ['id' => 'P80-MT-SRAND', 'sev' => 'perilaku', 'ver' => '7.1', 'conf' => 'rendah', 'kind' => 'func', 'names' => ['mt_srand', 'srand'],
        'title' => 'Urutan angka acak dengan seed tetap berbeda dari PHP lama', 'fix' => 'Cek ulang logika yang bergantung pada urutan acak deterministik. Jangan pakai rand()/mt_rand() untuk OTP atau token; pakai random_int().', 'ref' => MIG . 'migration71.incompatible.php'],

    // ---------------------------------------------------------------- 8.1
    ['id' => 'P81-SERIALIZABLE', 'sev' => 'deprecated', 'ver' => '8.1', 'conf' => 'tinggi', 'kind' => 'special', 'check' => 'serializable',
        'title' => 'Implementasi Serializable tanpa __serialize()/__unserialize()', 'fix' => 'Tambahkan __serialize() dan __unserialize().', 'ref' => MIG . 'migration81.deprecated.php'],
    ['id' => 'P81-RETURN-TYPE', 'sev' => 'deprecated', 'ver' => '8.1', 'conf' => 'tinggi', 'kind' => 'special', 'check' => 'internal_return_type',
        'title' => 'Method yang meng-override interface bawaan (ArrayAccess, Iterator, Countable, JsonSerializable, ...) tanpa return type',
        'fix' => 'Tambahkan return type yang sesuai (mis. offsetGet(mixed $offset): mixed, count(): int) atau #[\ReturnTypeWillChange].', 'ref' => MIG . 'migration81.incompatible.php'],
    ['id' => 'P81-DEPR-FUNC', 'sev' => 'deprecated', 'ver' => '8.1', 'conf' => 'tinggi', 'kind' => 'func',
        'names' => ['strftime', 'gmstrftime', 'strptime', 'date_sunrise', 'date_sunset', 'mhash', 'mhash_keygen_s2k', 'mhash_count', 'mhash_get_block_size', 'mhash_get_hash_name', 'odbc_result_all'],
        'title' => 'Fungsi deprecated di PHP 8.1', 'fix' => 'strftime() -> date() atau IntlDateFormatter; mhash*() -> hash*(); date_sunrise/sunset -> date_sun_info().', 'ref' => MIG . 'migration81.deprecated.php'],
    ['id' => 'P81-DEPR-CONST', 'sev' => 'deprecated', 'ver' => '8.1', 'conf' => 'tinggi', 'kind' => 'const', 'names' => ['FILTER_SANITIZE_STRING', 'FILTER_SANITIZE_STRIPPED', 'FILE_BINARY', 'FILE_TEXT'],
        'title' => 'Konstanta deprecated di PHP 8.1', 'fix' => 'FILTER_SANITIZE_STRING -> htmlspecialchars() saat output, atau FILTER_UNSAFE_RAW + validasi sendiri.', 'ref' => MIG . 'migration81.deprecated.php'],
    ['id' => 'P81-PDO-FETCH-SERIALIZE', 'sev' => 'deprecated', 'ver' => '8.1', 'conf' => 'tinggi', 'kind' => 'class_const', 'class' => 'PDO', 'names' => ['FETCH_SERIALIZE'],
        'title' => 'PDO::FETCH_SERIALIZE deprecated', 'fix' => 'Fetch biasa lalu unserialize() manual.', 'ref' => MIG . 'migration81.deprecated.php'],
    ['id' => 'P81-HTML-FLAGS', 'sev' => 'perilaku', 'ver' => '8.1', 'conf' => 'sedang', 'kind' => 'func', 'names' => ['htmlspecialchars', 'htmlentities', 'htmlspecialchars_decode', 'html_entity_decode'], 'argc' => ['==', 1],
        'title' => 'Flag default htmlspecialchars() dkk menjadi ENT_QUOTES | ENT_SUBSTITUTE', 'fix' => 'Petik tunggal sekarang ikut di-escape jadi &#039;. Cek output yang dibandingkan/disimpan, atau tulis flag-nya eksplisit.', 'ref' => MIG . 'migration81.incompatible.php'],
    ['id' => 'P81-MYSQLI-EXCEPTION', 'sev' => 'perilaku', 'ver' => '8.1', 'conf' => 'sedang', 'kind' => 'func', 'names' => ['mysqli_connect', 'mysqli_init', 'mysqli_real_connect'],
        'title' => 'mysqli default melempar exception (MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT)', 'fix' => 'Kode yang cek "if (!$result)" tidak sampai jalan karena exception sudah dilempar. Tambah try/catch atau mysqli_report(MYSQLI_REPORT_OFF).', 'ref' => MIG . 'migration81.incompatible.php'],
    ['id' => 'P81-MYSQLI-EXCEPTION-OOP', 'sev' => 'perilaku', 'ver' => '8.1', 'conf' => 'sedang', 'kind' => 'new', 'classes' => ['mysqli'],
        'title' => 'mysqli default melempar exception (MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT)', 'fix' => 'Kode yang cek "if (!$result)" tidak sampai jalan karena exception sudah dilempar. Tambah try/catch atau mysqli_report(MYSQLI_REPORT_OFF).', 'ref' => MIG . 'migration81.incompatible.php'],

    // ---------------------------------------------------------------- 8.2
    ['id' => 'P82-UTF8-ENCODE', 'rector' => 'Utf8DecodeEncodeToMbConvertEncodingRector', 'sev' => 'deprecated', 'ver' => '8.2', 'conf' => 'tinggi', 'kind' => 'func', 'names' => ['utf8_encode', 'utf8_decode'],
        'title' => 'utf8_encode()/utf8_decode() deprecated', 'fix' => "mb_convert_encoding(\$s, 'UTF-8', 'ISO-8859-1') dan sebaliknya.", 'ref' => MIG . 'migration82.deprecated.php'],
    ['id' => 'P82-DOLLAR-BRACE', 'rector' => 'VariableInStringInterpolationFixerRector', 'sev' => 'deprecated', 'ver' => '8.2', 'conf' => 'tinggi', 'kind' => 'special', 'check' => 'dollar_brace',
        'title' => 'Interpolasi string "${var}" deprecated', 'fix' => 'Ganti ke "{$var}".', 'ref' => MIG . 'migration82.deprecated.php'],
    ['id' => 'P82-DYNAMIC-PROP', 'sev' => 'deprecated', 'ver' => '8.2', 'conf' => 'sedang', 'kind' => 'special', 'check' => 'dynamic_prop',
        'title' => 'Kemungkinan dynamic property (properti tidak dideklarasikan)', 'fix' => 'Deklarasikan properti di class, atau tambahkan #[\AllowDynamicProperties]. Temuan heuristik, cek manual.', 'ref' => MIG . 'migration82.deprecated.php'],
    ['id' => 'P82-RELATIVE-CALLABLE', 'sev' => 'deprecated', 'ver' => '8.2', 'conf' => 'sedang', 'kind' => 'special', 'check' => 'relative_callable',
        'title' => 'Callable relatif "self::method" / ["parent", "method"] deprecated', 'fix' => 'Pakai self::class . "::method", [self::class, "method"], atau first-class callable self::method(...).', 'ref' => MIG . 'migration82.deprecated.php'],
    ['id' => 'P82-MB-ENCODING', 'sev' => 'deprecated', 'ver' => '8.2', 'conf' => 'tinggi', 'kind' => 'func', 'names' => ['mb_convert_encoding', 'mb_check_encoding', 'mb_detect_encoding', 'mb_strlen', 'mb_substr', 'mb_convert_variables', 'mb_internal_encoding'],
        'arg_string' => ['index' => 'any', 'values' => ['HTML-ENTITIES', 'HTML', 'QPrint', 'Quoted-Printable', 'BASE64', 'UUENCODE']],
        'title' => 'Encoding mbstring QPrint/Base64/Uuencode/HTML-ENTITIES deprecated', 'fix' => "HTML-ENTITIES -> htmlentities()/mb_encode_numericentity(); BASE64 -> base64_encode(); QPrint -> quoted_printable_encode().", 'ref' => MIG . 'migration82.deprecated.php'],
    ['id' => 'P82-STR-SPLIT', 'sev' => 'perilaku', 'ver' => '8.2', 'conf' => 'rendah', 'kind' => 'func', 'names' => ['str_split'],
        'title' => 'str_split("") sekarang mengembalikan [] (dulu [""])', 'fix' => 'Cek kode yang mengandalkan hasil minimal satu elemen.', 'ref' => MIG . 'migration82.incompatible.php'],

    // ---------------------------------------------------------------- 8.3
    ['id' => 'P83-GET-CLASS-NOARG', 'rector' => 'RemoveGetClassGetParentClassNoArgsRector', 'sev' => 'deprecated', 'ver' => '8.3', 'conf' => 'tinggi', 'kind' => 'func', 'names' => ['get_class', 'get_parent_class'], 'argc' => ['==', 0],
        'title' => 'get_class()/get_parent_class() tanpa argumen deprecated', 'fix' => 'Pakai self::class / static::class / get_class($this).', 'ref' => MIG . 'migration83.deprecated.php'],
    ['id' => 'P83-ASSERT-OPTIONS', 'sev' => 'deprecated', 'ver' => '8.3', 'conf' => 'tinggi', 'kind' => 'func', 'names' => ['assert_options'],
        'title' => 'assert_options() deprecated', 'fix' => 'Atur lewat INI zend.assertions.', 'ref' => MIG . 'migration83.deprecated.php'],
    ['id' => 'P83-ASSERT-CONST', 'sev' => 'deprecated', 'ver' => '8.3', 'conf' => 'tinggi', 'kind' => 'const', 'names' => ['ASSERT_ACTIVE', 'ASSERT_BAIL', 'ASSERT_CALLBACK', 'ASSERT_EXCEPTION', 'ASSERT_WARNING'],
        'title' => 'Konstanta ASSERT_* deprecated', 'fix' => 'Atur lewat INI zend.assertions.', 'ref' => MIG . 'migration83.deprecated.php'],

    // ---------------------------------------------------------------- 8.4
    ['id' => 'P84-IMPLICIT-NULLABLE', 'rector' => 'ExplicitNullableParamTypeRector', 'sev' => 'deprecated', 'ver' => '8.4', 'conf' => 'tinggi', 'kind' => 'special', 'check' => 'implicit_nullable',
        'title' => 'Parameter nullable implisit (Type $x = null) deprecated', 'fix' => 'Tulis ?Type $x = null.', 'ref' => MIG . 'migration84.deprecated.php'],
    ['id' => 'P84-OPTIONAL-BEFORE-REQUIRED', 'rector' => 'OptionalParametersAfterRequiredRector', 'sev' => 'deprecated', 'ver' => '8.0/8.4', 'conf' => 'tinggi', 'kind' => 'special', 'check' => 'optional_before_required',
        'title' => 'Parameter opsional sebelum parameter wajib', 'fix' => 'Default-nya tidak berefek. Hapus default atau ubah urutan parameter.', 'ref' => MIG . 'migration84.deprecated.php'],
    ['id' => 'P84-BCRYPT-COST', 'sev' => 'perilaku', 'ver' => '8.4', 'conf' => 'tinggi', 'kind' => 'func', 'names' => ['password_hash', 'password_needs_rehash'],
        'argc' => ['<', 3], 'arg_token' => ['PASSWORD_DEFAULT', 'PASSWORD_BCRYPT'],
        'title' => 'Cost default bcrypt naik dari 10 ke 12: password_hash() lebih lambat, hash lama dianggap perlu rehash',
        'fix' => 'Hash baru memakai cost 12, sekitar 4x lebih lama (terukur ~60 ms jadi ~240 ms per hash), dan password_needs_rehash() mengembalikan true untuk hash cost 10, jadi hash lama ditulis ulang saat login. Ukur beban endpoint login/registrasi; tulis [\'cost\' => 10] secara eksplisit bila perilaku lama harus dipertahankan.',
        'ref' => MIG . 'migration84.other-changes.php'],
    ['id' => 'P84-USER-ERROR', 'sev' => 'deprecated', 'ver' => '8.4', 'conf' => 'tinggi', 'kind' => 'func', 'names' => ['trigger_error', 'user_error'], 'arg_token' => ['E_USER_ERROR'],
        'title' => 'trigger_error() dengan E_USER_ERROR deprecated', 'fix' => 'Lempar exception atau panggil exit().', 'ref' => MIG . 'migration84.deprecated.php'],
    ['id' => 'P84-DEPR-CONST', 'sev' => 'deprecated', 'ver' => '8.4', 'conf' => 'tinggi', 'kind' => 'const',
        'names' => ['E_STRICT', 'CURLOPT_BINARYTRANSFER', 'SUNFUNCS_RET_TIMESTAMP', 'SUNFUNCS_RET_STRING', 'SUNFUNCS_RET_DOUBLE', 'SID', 'MYSQLI_STORE_RESULT_COPY_DATA', 'DOM_PHP_ERR', 'SOAP_FUNCTIONS_ALL'],
        'prefixes' => ['MYSQLI_REFRESH_'],
        'title' => 'Konstanta deprecated di PHP 8.4', 'fix' => 'E_STRICT -> hapus dari error_reporting (sudah termasuk E_ALL); CURLOPT_BINARYTRANSFER -> hapus; SID -> pakai cookie session.', 'ref' => MIG . 'migration84.deprecated.php'],
    ['id' => 'P84-DEPR-FUNC', 'sev' => 'deprecated', 'ver' => '8.4', 'conf' => 'tinggi', 'kind' => 'func', 'names' => ['lcg_value', 'mysqli_ping', 'mysqli_kill', 'mysqli_refresh', 'xml_set_object'],
        'title' => 'Fungsi deprecated di PHP 8.4', 'fix' => 'lcg_value() -> (new Random\Randomizer)->getFloat(); mysqli_kill/refresh -> query SQL KILL/FLUSH; mysqli_ping -> hapus.', 'ref' => MIG . 'migration84.deprecated.php'],
    ['id' => 'P84-CSV-ESCAPE', 'rector' => 'AddEscapeArgumentRector', 'sev' => 'deprecated', 'ver' => '8.4', 'conf' => 'tinggi', 'kind' => 'func', 'names' => ['fputcsv', 'fgetcsv'], 'argc' => ['<', 5],
        'title' => 'fputcsv()/fgetcsv() tanpa argumen $escape eksplisit deprecated', 'fix' => "Tambahkan argumen escape eksplisit: '\\\\' supaya hasil sama persis dengan sekarang, atau '' (disarankan PHP) setelah data diuji.", 'ref' => MIG . 'migration84.deprecated.php'],
    ['id' => 'P84-CSV-ESCAPE-STR', 'rector' => 'AddEscapeArgumentRector', 'sev' => 'deprecated', 'ver' => '8.4', 'conf' => 'tinggi', 'kind' => 'func', 'names' => ['str_getcsv'], 'argc' => ['<', 4],
        'title' => 'str_getcsv() tanpa argumen $escape eksplisit deprecated', 'fix' => "Tambahkan argumen escape eksplisit: '\\\\' supaya hasil sama persis dengan sekarang, atau '' (disarankan PHP) setelah data diuji.", 'ref' => MIG . 'migration84.deprecated.php'],
    ['id' => 'P84-CSV-ESCAPE-SPL', 'rector' => 'AddEscapeArgumentRector', 'sev' => 'deprecated', 'ver' => '8.4', 'conf' => 'sedang', 'kind' => 'method', 'names' => ['fgetcsv', 'setCsvControl'], 'argc' => ['<', 3],
        'title' => 'SplFileObject::fgetcsv()/setCsvControl() tanpa $escape eksplisit deprecated', 'fix' => "Tambahkan argumen escape eksplisit: '\\\\' supaya hasil sama persis dengan sekarang, atau '' (disarankan PHP) setelah data diuji.", 'ref' => MIG . 'migration84.deprecated.php'],
    ['id' => 'P84-CSV-ESCAPE-SPL-PUT', 'rector' => 'AddEscapeArgumentRector', 'sev' => 'deprecated', 'ver' => '8.4', 'conf' => 'sedang', 'kind' => 'method', 'names' => ['fputcsv'], 'argc' => ['<', 4],
        'title' => 'SplFileObject::fputcsv() tanpa $escape eksplisit deprecated', 'fix' => "Tambahkan argumen escape eksplisit: '\\\\' supaya hasil sama persis dengan sekarang, atau '' (disarankan PHP) setelah data diuji.", 'ref' => MIG . 'migration84.deprecated.php'],
    ['id' => 'P84-STREAM-CTX-OPT', 'sev' => 'deprecated', 'ver' => '8.4', 'conf' => 'tinggi', 'kind' => 'func', 'names' => ['stream_context_set_option'], 'argc' => ['==', 2],
        'title' => 'stream_context_set_option() dengan 2 argumen deprecated', 'fix' => 'Pakai stream_context_set_options($ctx, $options).', 'ref' => MIG . 'migration84.deprecated.php'],
    ['id' => 'P84-SESSION-HANDLER', 'sev' => 'deprecated', 'ver' => '8.4', 'conf' => 'tinggi', 'kind' => 'func', 'names' => ['session_set_save_handler'], 'argc' => ['>', 2],
        'title' => 'session_set_save_handler() dengan banyak callback deprecated', 'fix' => 'Buat class yang implement SessionHandlerInterface, lalu session_set_save_handler($handler).', 'ref' => MIG . 'migration84.deprecated.php'],
    ['id' => 'P84-PG-2ARG', 'sev' => 'deprecated', 'ver' => '8.4', 'conf' => 'tinggi', 'kind' => 'func', 'names' => ['pg_fetch_result', 'pg_field_prtlen', 'pg_field_is_null'], 'argc' => ['==', 2],
        'title' => 'Signature 2 argumen pg_fetch_result() dkk deprecated', 'fix' => 'Isi argumen row dengan null: pg_fetch_result($r, null, $field).', 'ref' => MIG . 'migration84.deprecated.php'],
    ['id' => 'P84-LDAP-CONNECT', 'sev' => 'deprecated', 'ver' => '8.3/8.4', 'conf' => 'tinggi', 'kind' => 'func', 'names' => ['ldap_connect'], 'argc' => ['>=', 2],
        'title' => 'ldap_connect() dengan host & port terpisah deprecated', 'fix' => "Pakai satu URI: ldap_connect('ldap://host:389').", 'ref' => MIG . 'migration83.deprecated.php'],
    ['id' => 'P84-SESSION-INI', 'funcs' => ['ini_set', 'ini_alter'], 'sev' => 'deprecated', 'ver' => '8.4', 'conf' => 'sedang', 'kind' => 'ini',
        'names' => ['session.sid_length', 'session.sid_bits_per_character', 'session.use_only_cookies', 'session.use_trans_sid', 'session.trans_sid_tags', 'session.trans_sid_hosts', 'session.referer_check'],
        'title' => 'Mengubah INI session ini deprecated', 'fix' => 'Di 8.5.12 deprecation muncul jika nilainya berbeda dari default (session.sid_length/sid_bits_per_character selalu). Hapus ini_set-nya dan pakai nilai default.', 'ref' => MIG . 'migration84.deprecated.php'],
    ['id' => 'EXT-84-PECL', 'sev' => 'wajib', 'ver' => '8.4', 'conf' => 'tinggi', 'kind' => 'func_prefix', 'prefixes' => ['imap_', 'pspell_', 'oci_', 'ocilogon', 'ociparse', 'ociexecute', 'ocifetch'],
        'title' => 'Ekstensi IMAP / Pspell / OCI8 dikeluarkan dari core (pindah ke PECL)', 'fix' => 'Pasang ulang dari PECL (cek dukungan 8.5) atau ganti library, mis. webklex/php-imap.', 'ref' => MIG . 'migration84.removed-extensions.php'],
    ['id' => 'P84-MYSQLI-CONST', 'sev' => 'wajib', 'ver' => '8.4', 'conf' => 'tinggi', 'kind' => 'const',
        'names' => ['MYSQLI_SET_CHARSET_DIR', 'MYSQLI_STMT_ATTR_PREFETCH_ROWS', 'MYSQLI_CURSOR_TYPE_FOR_UPDATE', 'MYSQLI_CURSOR_TYPE_SCROLLABLE', 'MYSQLI_TYPE_INTERVAL'],
        'title' => 'Konstanta mysqli sudah dihapus di 8.4', 'fix' => 'Hapus pemakaiannya.', 'ref' => MIG . 'migration84.incompatible.php'],

    // ---------------------------------------------------------------- 8.5
    ['id' => 'P85-BACKTICK', 'rector' => 'ShellExecFunctionCallOverBackticksRector', 'sev' => 'deprecated', 'ver' => '8.5', 'conf' => 'tinggi', 'kind' => 'special', 'check' => 'backtick',
        'title' => 'Operator backtick `cmd` deprecated', 'fix' => 'Pakai shell_exec() (dan escapeshellarg untuk input).', 'ref' => MIG . 'migration85.deprecated.php'],
    ['id' => 'P85-CAST-NAME', 'rector' => 'RenameCastRector', 'sev' => 'deprecated', 'ver' => '8.5', 'conf' => 'tinggi', 'kind' => 'cast', 'casts' => ['(integer)', '(boolean)', '(double)', '(binary)'],
        'title' => 'Nama cast non-kanonik deprecated', 'fix' => '(integer) -> (int), (boolean) -> (bool), (double) -> (float), (binary) -> (string).', 'ref' => MIG . 'migration85.deprecated.php'],
    ['id' => 'P85-CASE-SEMICOLON', 'rector' => 'ColonAfterSwitchCaseRector', 'sev' => 'deprecated', 'ver' => '8.5', 'conf' => 'tinggi', 'kind' => 'special', 'check' => 'case_semicolon',
        'title' => 'case/default diakhiri titik koma deprecated', 'fix' => 'Ganti "case X;" menjadi "case X:".', 'ref' => MIG . 'migration85.deprecated.php'],
    ['id' => 'P85-FREE-FUNC', 'rector' => 'RemoveFuncCallRector', 'sev' => 'deprecated', 'ver' => '8.5', 'conf' => 'tinggi', 'kind' => 'func', 'names' => ['curl_close', 'curl_share_close', 'finfo_close', 'imagedestroy', 'xml_parser_free'],
        'title' => 'Fungsi "close/free" yang sudah tidak berefek sekarang deprecated', 'fix' => 'Hapus pemanggilannya; object dibebaskan otomatis. Pakai unset($x) jika perlu dibebaskan lebih awal.', 'ref' => MIG . 'migration85.deprecated.php'],
    ['id' => 'P85-DEPR-FUNC', 'sev' => 'deprecated', 'ver' => '8.5', 'conf' => 'tinggi', 'kind' => 'func', 'names' => ['mysqli_execute', 'socket_set_timeout', 'ldap_connect_wallet'],
        'title' => 'Fungsi deprecated di PHP 8.5', 'fix' => 'mysqli_execute() -> mysqli_stmt_execute(); socket_set_timeout() -> stream_set_timeout().', 'ref' => MIG . 'migration85.deprecated.php'],
    ['id' => 'P85-DIR-NOARG', 'sev' => 'deprecated', 'ver' => '8.5', 'conf' => 'tinggi', 'kind' => 'func', 'names' => ['readdir', 'rewinddir', 'closedir'], 'argc' => ['==', 0],
        'title' => 'readdir()/rewinddir()/closedir() tanpa handle deprecated', 'fix' => 'Selalu kirim handle dari opendir().', 'ref' => MIG . 'migration85.deprecated.php'],
    ['id' => 'P85-HTTP-RESPONSE-HEADER', 'sev' => 'deprecated', 'ver' => '8.5', 'conf' => 'tinggi', 'kind' => 'var', 'names' => ['$http_response_header'],
        'title' => 'Variabel $http_response_header deprecated', 'fix' => 'Pakai http_get_last_response_headers().', 'ref' => MIG . 'migration85.deprecated.php'],
    ['id' => 'P85-DEPR-CONST', 'sev' => 'deprecated', 'ver' => '8.5', 'conf' => 'tinggi', 'kind' => 'const', 'names' => ['DATE_RFC7231'], 'prefixes' => ['MHASH_'],
        'title' => 'Konstanta deprecated di PHP 8.5', 'fix' => 'DATE_RFC7231 -> format manual "D, d M Y H:i:s \\G\\M\\T"; MHASH_* -> nama algoritma hash().', 'ref' => MIG . 'migration85.deprecated.php'],
    ['id' => 'P85-RFC7231', 'sev' => 'deprecated', 'ver' => '8.5', 'conf' => 'tinggi', 'kind' => 'class_const', 'class' => '*', 'names' => ['RFC7231'],
        'title' => 'DateTimeInterface::RFC7231 deprecated', 'fix' => 'Format manual "D, d M Y H:i:s \\G\\M\\T".', 'ref' => MIG . 'migration85.deprecated.php'],
    ['id' => 'P85-PDO-DRIVER-CONST', 'rector' => 'RenameClassConstFetchRector', 'sev' => 'deprecated', 'ver' => '8.5', 'conf' => 'tinggi', 'kind' => 'class_const', 'class' => 'PDO',
        'prefixes' => ['MYSQL_ATTR_', 'PGSQL_ATTR_', 'PGSQL_TRANSACTION_', 'SQLITE_', 'DBLIB_ATTR_', 'FB_ATTR_', 'ODBC_'],
        'title' => 'Konstanta driver di class PDO deprecated', 'fix' => 'PDO::MYSQL_ATTR_X -> Pdo\Mysql::ATTR_X (begitu juga Pgsql, Sqlite, Dblib, Firebird, Odbc). Pdo\Mysql tersedia sejak 8.4.', 'ref' => MIG . 'migration85.deprecated.php'],
    ['id' => 'P85-PDO-DRIVER-METHOD', 'sev' => 'deprecated', 'ver' => '8.5', 'conf' => 'tinggi', 'kind' => 'method',
        'names' => ['pgsqlCopyFromArray', 'pgsqlCopyFromFile', 'pgsqlCopyToArray', 'pgsqlCopyToFile', 'pgsqlGetNotify', 'pgsqlGetPid', 'pgsqlLOBCreate', 'pgsqlLOBOpen', 'pgsqlLOBUnlink', 'sqliteCreateAggregate', 'sqliteCreateCollation', 'sqliteCreateFunction'],
        'title' => 'Method driver di class PDO deprecated', 'fix' => 'Pakai Pdo\Pgsql / Pdo\Sqlite (PDO::connect()) dan method barunya.', 'ref' => MIG . 'migration85.deprecated.php'],
    ['id' => 'P85-SET-ACCESSIBLE', 'sev' => 'deprecated', 'ver' => '8.5', 'conf' => 'sedang', 'kind' => 'method', 'names' => ['setAccessible'],
        'title' => 'Reflection*::setAccessible() deprecated (tidak berefek sejak 8.1)', 'fix' => 'Hapus pemanggilannya.', 'ref' => MIG . 'migration85.deprecated.php'],
    ['id' => 'P85-SPLOBJECTSTORAGE', 'sev' => 'deprecated', 'ver' => '8.5', 'conf' => 'rendah', 'kind' => 'method', 'names' => ['attach', 'detach', 'contains'], 'only_if_mentioned' => 'SplObjectStorage',
        'title' => 'SplObjectStorage::attach()/detach()/contains() deprecated', 'fix' => 'Jika object-nya SplObjectStorage: offsetSet()/offsetUnset()/offsetExists(). Abaikan jika class lain.', 'ref' => MIG . 'migration85.deprecated.php'],
    ['id' => 'P85-SLEEP-WAKEUP', 'sev' => 'info', 'ver' => '8.5', 'conf' => 'tinggi', 'kind' => 'special', 'check' => 'sleep_wakeup',
        'title' => '__sleep()/__wakeup() soft-deprecated', 'fix' => 'Belum memunculkan warning. Rencanakan pindah ke __serialize()/__unserialize().', 'ref' => MIG . 'migration85.deprecated.php'],
    ['id' => 'P85-INI', 'funcs' => ['ini_set', 'ini_alter'], 'sev' => 'deprecated', 'ver' => '8.5', 'conf' => 'tinggi', 'kind' => 'ini', 'names' => ['report_memleaks', 'intl.error_level'],
        'title' => 'Direktif INI deprecated di PHP 8.5', 'fix' => 'intl.error_level -> intl.use_exceptions atau cek error manual.', 'ref' => MIG . 'migration85.deprecated.php'],
    ['id' => 'P85-SIMPLEXML-XPATH', 'sev' => 'perilaku', 'ver' => '8.5', 'conf' => 'rendah', 'kind' => 'method', 'names' => ['xpath'],
        'title' => 'SimpleXMLElement::xpath() mengembalikan false (bukan []) jika ekspresi bukan node set', 'fix' => 'Pastikan hasil dicek sebelum di-foreach.', 'ref' => MIG . 'migration85.incompatible.php'],
];
