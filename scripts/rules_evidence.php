<?php
/**
 * Sources for every scanner rule, beyond the php.net migration page in "ref": the php-src UPGRADING entry of
 * the release that made the change (quoted exactly, so a reader can find it) and the RFCs that entry cites.
 * scan_code.php adds them to each rule; the report links them next to php.net. Keyed by rule id; tests check
 * that every rule has an entry, and .github/workflows/links.yml checks weekly that every quote is still there.
 */
return [
    'EXT-MYSQL' => [
        ['kind' => 'php-src', 'title' => 'PHP 7.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-7.0/UPGRADING', 'quote' => 'ext/mysql'],
        ['kind' => 'rfc', 'title' => 'RFC: removal_of_dead_sapis_and_exts', 'url' => 'https://wiki.php.net/rfc/removal_of_dead_sapis_and_exts'],
        ['kind' => 'rfc', 'title' => 'RFC: remove_deprecated_functionality_in_php7', 'url' => 'https://wiki.php.net/rfc/remove_deprecated_functionality_in_php7'],
    ],
    'EXT-EREG' => [
        ['kind' => 'php-src', 'title' => 'PHP 7.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-7.0/UPGRADING', 'quote' => 'ext/ereg'],
        ['kind' => 'rfc', 'title' => 'RFC: removal_of_dead_sapis_and_exts', 'url' => 'https://wiki.php.net/rfc/removal_of_dead_sapis_and_exts'],
        ['kind' => 'rfc', 'title' => 'RFC: remove_deprecated_functionality_in_php7', 'url' => 'https://wiki.php.net/rfc/remove_deprecated_functionality_in_php7'],
    ],
    'EXT-MCRYPT' => [
        ['kind' => 'php-src', 'title' => 'PHP 7.2 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-7.2/UPGRADING', 'quote' => 'The deprecated mcrypt extension has been moved to PECL.'],
    ],
    'EXT-74-REMOVED' => [
        ['kind' => 'php-src', 'title' => 'PHP 7.4 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-7.4/UPGRADING', 'quote' => 'The interbase extension has been moved to PECL.'],
        ['kind' => 'php-src', 'title' => 'PHP 7.4 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-7.4/UPGRADING', 'quote' => 'The recode extension has been moved to PECL.'],
        ['kind' => 'php-src', 'title' => 'PHP 7.4 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-7.4/UPGRADING', 'quote' => 'The WDDX extension has been deprecated and moved to PECL.'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecate-and-remove-ext-interbase', 'url' => 'https://wiki.php.net/rfc/deprecate-and-remove-ext-interbase'],
        ['kind' => 'rfc', 'title' => 'RFC: unbundle_recode', 'url' => 'https://wiki.php.net/rfc/unbundle_recode'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecate-and-remove-ext-wddx', 'url' => 'https://wiki.php.net/rfc/deprecate-and-remove-ext-wddx'],
    ],
    'P80-REMOVED-FUNC' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.0/UPGRADING', 'quote' => 'Removed each().'],
    ],
    'P80-DEFINE-CI' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.0/UPGRADING', 'quote' => 'Removed the ability to define case-insensitive constants.'],
    ],
    'P80-PARSE-STR-1ARG' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.0/UPGRADING', 'quote' => 'parse_str() can no longer be used without specifying a result array.'],
    ],
    'P80-CRYPT-NOSALT' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.0/UPGRADING', 'quote' => 'Removed deprecated DES fallback in crypt().'],
    ],
    'P80-MKTIME-NOARG' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.0/UPGRADING', 'quote' => 'mktime() and gmmktime() now require at least one argument.'],
    ],
    'P80-IMPLODE-REVERSED' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.0/UPGRADING', 'quote' => 'Calling implode() with parameters in a reverse order ($pieces, $glue) is no longer supported.'],
    ],
    'P80-AUTOLOAD-FUNC' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.0/UPGRADING', 'quote' => 'Removed ability to specify an autoloader using an __autoload() function.'],
    ],
    'P80-PHP4-CTOR' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.0/UPGRADING', 'quote' => 'Methods with the same name as the class are no longer interpreted as constructors.'],
    ],
    'P80-CURLY-OFFSET' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.0/UPGRADING', 'quote' => 'Removed support for deprecated curly braces for offset access'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecate_curly_braces_array_access', 'url' => 'https://wiki.php.net/rfc/deprecate_curly_braces_array_access'],
    ],
    'P80-REMOVED-CAST' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.0/UPGRADING', 'quote' => 'Removed (unset) cast.'],
    ],
    'P80-PHP-ERRORMSG' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.0/UPGRADING', 'quote' => 'Removed track_errors ini directive.'],
    ],
    'P80-REMOVED-CONST' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.0/UPGRADING', 'quote' => 'The INPUT_REQUEST and INPUT_SESSION source for filter_input() etc have been removed.'],
    ],
    'P80-REMOVED-INI' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.0/UPGRADING', 'quote' => 'Removed track_errors ini directive.'],
        ['kind' => 'php-src', 'title' => 'PHP 8.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.0/UPGRADING', 'quote' => 'The mbstring.func_overload directive has been removed.'],
    ],
    'EXT-OLD-DB' => [
        ['kind' => 'php-src', 'title' => 'PHP 7.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-7.0/UPGRADING', 'quote' => 'ext/mssql'],
        ['kind' => 'php-src', 'title' => 'PHP 7.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-7.0/UPGRADING', 'quote' => 'ext/sybase_ct'],
        ['kind' => 'rfc', 'title' => 'RFC: removal_of_dead_sapis_and_exts', 'url' => 'https://wiki.php.net/rfc/removal_of_dead_sapis_and_exts'],
    ],
    'P80-REFLECTION-DEPR' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.0/UPGRADING', 'quote' => 'ReflectionParameter::getClass(), ReflectionParameter::isArray(), and ReflectionParameter::isCallable() are deprecated.'],
    ],
    'P80-LIBXML-ENTITY' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.0/UPGRADING', 'quote' => 'libxml_disable_entity_loader() has been deprecated.'],
    ],
    'P80-ZIP-PROCEDURAL' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.0/UPGRADING', 'quote' => 'The procedural API of Zip is deprecated.'],
    ],
    'P80-PGSQL-ALIAS' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.0/UPGRADING', 'quote' => 'Function aliases in the pgsql extension have been deprecated.'],
    ],
    'P80-ENCHANT' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.0/UPGRADING', 'quote' => 'enchant_dict_add_to_personal, use enchant_dict_add instead'],
    ],
    'P80-CONCAT-PRECEDENCE' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.0/UPGRADING', 'quote' => 'The precedence of the concatenation operator has changed relative to bitshifts and addition as well as subtraction.'],
        ['kind' => 'rfc', 'title' => 'RFC: concatenation_precedence', 'url' => 'https://wiki.php.net/rfc/concatenation_precedence'],
    ],
    'P80-LOOSE-CMP' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.0/UPGRADING', 'quote' => 'Non-strict comparisons between numbers and non-numeric strings now work by casting the number to string and comparing the strings.'],
        ['kind' => 'rfc', 'title' => 'RFC: string_to_number_comparison', 'url' => 'https://wiki.php.net/rfc/string_to_number_comparison'],
    ],
    'P80-SUBSTR-FALSE' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.0/UPGRADING', 'quote' => 'substr(), mb_substr(), iconv_substr() and grapheme_substr() now consistently clamp out-of-bounds offsets to the string boundary.'],
    ],
    'P80-IS-RESOURCE' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.0/UPGRADING', 'quote' => 'curl_init() will now return a CurlHandle object rather than a resource.'],
    ],
    'P80-PDO-ERRMODE' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.0/UPGRADING', 'quote' => 'The default error handling mode has been changed from "silent" to "exceptions".'],
        ['kind' => 'rfc', 'title' => 'RFC: pdo_default_errmode', 'url' => 'https://wiki.php.net/rfc/pdo_default_errmode'],
    ],
    'P80-SETLOCALE' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.0/UPGRADING', 'quote' => 'The default locale on startup is now always "C".'],
    ],
    'P80-ASSERT-STRING' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.0/UPGRADING', 'quote' => 'assert() will no longer evaluate string arguments, instead they will be treated like any other argument.'],
    ],
    'P80-MT-SRAND' => [
        ['kind' => 'php-src', 'title' => 'PHP 7.1 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-7.1/UPGRADING', 'quote' => 'Fixes to random number generators mean that mt_rand() now produces a different sequence of outputs to previous versions.'],
    ],
    'P81-SERIALIZABLE' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.1 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.1/UPGRADING', 'quote' => 'Implementing the Serializable interface without also implementing __serialize() and __unserialize() has been deprecated.'],
        ['kind' => 'rfc', 'title' => 'RFC: phase_out_serializable', 'url' => 'https://wiki.php.net/rfc/phase_out_serializable'],
    ],
    'P81-RETURN-TYPE' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.1 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.1/UPGRADING', 'quote' => 'Most non-final internal methods now require overriding methods to declare a compatible return type, otherwise a deprecated notice is emitted during inheritance validation.'],
        ['kind' => 'rfc', 'title' => 'RFC: internal_method_return_types', 'url' => 'https://wiki.php.net/rfc/internal_method_return_types'],
    ],
    'P81-DEPR-FUNC' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.1 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.1/UPGRADING', 'quote' => 'The strftime() and gmstrftime() functions have been deprecated in favor of date()/DateTime::format() (for locale-independent formatting) or IntlDateFormatter::format() (for locale-dependent formatting).'],
    ],
    'P81-DEPR-CONST' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.1 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.1/UPGRADING', 'quote' => 'The FILTER_SANITIZE_STRING and FILTER_SANITIZE_STRIPPED filters have been deprecated.'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecations_php_8_1', 'url' => 'https://wiki.php.net/rfc/deprecations_php_8_1'],
    ],
    'P81-PDO-FETCH-SERIALIZE' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.1 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.1/UPGRADING', 'quote' => 'The PDO::FETCH_SERIALIZE mode has been deprecated.'],
        ['kind' => 'rfc', 'title' => 'RFC: phase_out_serializable', 'url' => 'https://wiki.php.net/rfc/phase_out_serializable'],
    ],
    'P81-NULL-TO-INTERNAL' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.1 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.1/UPGRADING', 'quote' => 'Passing null to a non-nullable argument of a built-in function is deprecated.'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecate_null_to_scalar_internal_arg', 'url' => 'https://wiki.php.net/rfc/deprecate_null_to_scalar_internal_arg'],
    ],
    'P81-HTML-FLAGS' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.1 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.1/UPGRADING', 'quote' => 'htmlspecialchars(), htmlentities(), htmlspecialchars_decode(), html_entity_decode() and get_html_translation_table() now use ENT_QUOTES | ENT_SUBSTITUTE rather than ENT_COMPAT by default.'],
    ],
    'P81-MYSQLI-EXCEPTION' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.1 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.1/UPGRADING', 'quote' => 'To keep the old behavior, use mysqli_report(MYSQLI_REPORT_OFF);'],
        ['kind' => 'rfc', 'title' => 'RFC: mysqli_default_errmode', 'url' => 'https://wiki.php.net/rfc/mysqli_default_errmode'],
    ],
    'P81-MYSQLI-EXCEPTION-OOP' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.1 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.1/UPGRADING', 'quote' => 'To keep the old behavior, use mysqli_report(MYSQLI_REPORT_OFF);'],
        ['kind' => 'rfc', 'title' => 'RFC: mysqli_default_errmode', 'url' => 'https://wiki.php.net/rfc/mysqli_default_errmode'],
    ],
    'P82-UTF8-ENCODE' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.2 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.2/UPGRADING', 'quote' => 'utf8_encode() and utf8_decode() have been deprecated.'],
    ],
    'P82-DOLLAR-BRACE' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.2 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.2/UPGRADING', 'quote' => 'The "${var}" and "${expr}" style string interpolations are deprecated and will be removed in PHP 9.'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecate_dollar_brace_string_interpolation', 'url' => 'https://wiki.php.net/rfc/deprecate_dollar_brace_string_interpolation'],
    ],
    'P82-DYNAMIC-PROP' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.2 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.2/UPGRADING', 'quote' => 'Creation of dynamic properties is deprecated, unless the class opts in by using the #[AllowDynamicProperties] attribute.'],
    ],
    'P82-RELATIVE-CALLABLE' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.2 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.2/UPGRADING', 'quote' => 'Callables that are not accepted by the $callable() syntax (but are accepted by call_user_func) are deprecated.'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecate_partially_supported_callables', 'url' => 'https://wiki.php.net/rfc/deprecate_partially_supported_callables'],
        ['kind' => 'rfc', 'title' => 'RFC: partially-supported-callables-expand-deprecation-notices', 'url' => 'https://wiki.php.net/rfc/partially-supported-callables-expand-deprecation-notices'],
    ],
    'P82-MB-ENCODING' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.2 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.2/UPGRADING', 'quote' => 'Use of QPrint, Base64, Uuencode, and HTML-ENTITIES \'text encodings\' is deprecated for all Mbstring functions.'],
    ],
    'P82-STR-SPLIT' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.2 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.2/UPGRADING', 'quote' => 'str_split() returns an empty array for an empty string now.'],
    ],
    'P83-GET-CLASS-NOARG' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.3 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.3/UPGRADING', 'quote' => 'Calling get_class() and get_parent_class() without arguments is now deprecated.'],
    ],
    'P83-ASSERT-OPTIONS' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.3 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.3/UPGRADING', 'quote' => 'The assert_options() function is now deprecated.'],
    ],
    'P83-ASSERT-CONST' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.3 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.3/UPGRADING', 'quote' => 'The ASSERT_ACTIVE, ASSERT_BAIL, ASSERT_CALLBACK, ASSERT_EXCEPTION, and ASSERT_WARNING constants have been deprecated.'],
        ['kind' => 'rfc', 'title' => 'RFC: assert-string-eval-cleanup', 'url' => 'https://wiki.php.net/rfc/assert-string-eval-cleanup'],
    ],
    'P84-IMPLICIT-NULLABLE' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.4 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.4/UPGRADING', 'quote' => 'Implicitly nullable parameter types are now deprecated.'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecate-implicitly-nullable-types', 'url' => 'https://wiki.php.net/rfc/deprecate-implicitly-nullable-types'],
    ],
    'P84-OPTIONAL-BEFORE-REQUIRED' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.0 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.0/UPGRADING', 'quote' => 'Declaring a required parameter after an optional one is deprecated.'],
    ],
    'P84-BCRYPT-COST' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.4 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.4/UPGRADING', 'quote' => 'The default value of the \'cost\' option for PASSWORD_BCRYPT for password_hash() has been increased from \'10\' to \'12\'.'],
        ['kind' => 'rfc', 'title' => 'RFC: bcrypt_cost_2023', 'url' => 'https://wiki.php.net/rfc/bcrypt_cost_2023'],
    ],
    'P84-USER-ERROR' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.4 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.4/UPGRADING', 'quote' => 'Passing E_USER_ERROR to trigger_error() is now deprecated.'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecations_php_8_4', 'url' => 'https://wiki.php.net/rfc/deprecations_php_8_4'],
    ],
    'P84-DEPR-CONST' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.4 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.4/UPGRADING', 'quote' => 'The E_STRICT constant was deprecated and its corresponding error level was removed.'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecations_php_8_4', 'url' => 'https://wiki.php.net/rfc/deprecations_php_8_4'],
    ],
    'P84-DEPR-FUNC' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.4 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.4/UPGRADING', 'quote' => 'lcg_value() is deprecated, as the function is broken in multiple ways.'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecations_php_8_4', 'url' => 'https://wiki.php.net/rfc/deprecations_php_8_4'],
    ],
    'P84-CSV-ESCAPE' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.4 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.4/UPGRADING', 'quote' => 'Using the default value for $escape parameter of: - fputcsv() - fgetcsv() - str_getcsv() is now deprecated.'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecations_php_8_4', 'url' => 'https://wiki.php.net/rfc/deprecations_php_8_4'],
    ],
    'P84-CSV-ESCAPE-STR' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.4 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.4/UPGRADING', 'quote' => 'Using the default value for $escape parameter of: - fputcsv() - fgetcsv() - str_getcsv() is now deprecated.'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecations_php_8_4', 'url' => 'https://wiki.php.net/rfc/deprecations_php_8_4'],
    ],
    'P84-CSV-ESCAPE-SPL' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.4 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.4/UPGRADING', 'quote' => 'Using the default value for $escape parameter of: - SplFileObject::setCsvControl() - SplFileObject::fputcsv() - SplFileObject::fgetcsv() is now deprecated.'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecations_php_8_4', 'url' => 'https://wiki.php.net/rfc/deprecations_php_8_4'],
    ],
    'P84-CSV-ESCAPE-SPL-PUT' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.4 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.4/UPGRADING', 'quote' => 'Using the default value for $escape parameter of: - SplFileObject::setCsvControl() - SplFileObject::fputcsv() - SplFileObject::fgetcsv() is now deprecated.'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecations_php_8_4', 'url' => 'https://wiki.php.net/rfc/deprecations_php_8_4'],
    ],
    'P84-STREAM-CTX-OPT' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.4 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.4/UPGRADING', 'quote' => 'Calling stream_context_set_option() with 2 arguments is deprecated.'],
    ],
    'P84-SESSION-HANDLER' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.4 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.4/UPGRADING', 'quote' => 'Calling session_set_save_handler() with more than 2 arguments is deprecated.'],
    ],
    'P84-PG-2ARG' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.4 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.4/UPGRADING', 'quote' => 'Calling pg_fetch_result() with 2 arguments is deprecated.'],
    ],
    'P84-LDAP-CONNECT' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.3 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.3/UPGRADING', 'quote' => 'Calling ldap_connect() with separate hostname and port is deprecated.'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecations_php_8_3', 'url' => 'https://wiki.php.net/rfc/deprecations_php_8_3'],
    ],
    'P84-SESSION-INI' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.4 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.4/UPGRADING', 'quote' => 'Changing the INI settings session.sid_length and session.sid_bits_per_character is deprecated.'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecations_php_8_4', 'url' => 'https://wiki.php.net/rfc/deprecations_php_8_4'],
    ],
    'EXT-84-PECL' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.4 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.4/UPGRADING', 'quote' => 'The IMAP extension has been unbundled and moved to PECL.'],
        ['kind' => 'php-src', 'title' => 'PHP 8.4 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.4/UPGRADING', 'quote' => 'The OCI8 extension has been unbundled and moved to PECL.'],
        ['kind' => 'php-src', 'title' => 'PHP 8.4 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.4/UPGRADING', 'quote' => 'The pspell extension has been unbundled and moved to PECL.'],
        ['kind' => 'rfc', 'title' => 'RFC: unbundle_imap_pspell_oci8', 'url' => 'https://wiki.php.net/rfc/unbundle_imap_pspell_oci8'],
    ],
    'P84-MYSQLI-CONST' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.4 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.4/UPGRADING', 'quote' => 'The unused and undocumented constant MYSQLI_SET_CHARSET_DIR has been removed.'],
    ],
    'P85-BACKTICK' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.5 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.5/UPGRADING', 'quote' => 'The backtick operator as an alias for shell_exec() has been deprecated.'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecations_php_8_5', 'url' => 'https://wiki.php.net/rfc/deprecations_php_8_5'],
    ],
    'P85-CAST-NAME' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.5 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.5/UPGRADING', 'quote' => 'Non-canonical cast names (boolean), (integer), (double), and (binary) have been deprecated, use (bool), (int), (float), and (string) respectively.'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecations_php_8_5', 'url' => 'https://wiki.php.net/rfc/deprecations_php_8_5'],
    ],
    'P85-CASE-SEMICOLON' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.5 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.5/UPGRADING', 'quote' => 'Terminating case statements with a semicolon instead of a colon has been deprecated.'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecations_php_8_5', 'url' => 'https://wiki.php.net/rfc/deprecations_php_8_5'],
    ],
    'P85-FREE-FUNC' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.5 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.5/UPGRADING', 'quote' => 'The curl_close() function has been deprecated, as CurlHandle objects are freed automatically.'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecations_php_8_5', 'url' => 'https://wiki.php.net/rfc/deprecations_php_8_5'],
    ],
    'P85-DEPR-FUNC' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.5 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.5/UPGRADING', 'quote' => 'The mysqli_execute() alias function has been deprecated.'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecations_php_8_5', 'url' => 'https://wiki.php.net/rfc/deprecations_php_8_5'],
    ],
    'P85-DIR-NOARG' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.5 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.5/UPGRADING', 'quote' => 'Passing null to to readdir(), rewinddir(), and closedir() to use the last opened directory has been deprecated.'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecations_php_8_5', 'url' => 'https://wiki.php.net/rfc/deprecations_php_8_5'],
    ],
    'P85-HTTP-RESPONSE-HEADER' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.5 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.5/UPGRADING', 'quote' => 'The locally predefined variable $http_response_header is deprecated.'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecations_php_8_5', 'url' => 'https://wiki.php.net/rfc/deprecations_php_8_5'],
    ],
    'P85-DEPR-CONST' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.5 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.5/UPGRADING', 'quote' => 'The DATE_RFC7231 and DateTimeInterface::RFC7231 constants have been deprecated.'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecations_php_8_5', 'url' => 'https://wiki.php.net/rfc/deprecations_php_8_5'],
    ],
    'P85-RFC7231' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.5 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.5/UPGRADING', 'quote' => 'The DATE_RFC7231 and DateTimeInterface::RFC7231 constants have been deprecated.'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecations_php_8_5', 'url' => 'https://wiki.php.net/rfc/deprecations_php_8_5'],
    ],
    'P85-PDO-DRIVER-CONST' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.5 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.5/UPGRADING', 'quote' => 'Driver specific constants in the PDO class have been deprecated.'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecations_php_8_5', 'url' => 'https://wiki.php.net/rfc/deprecations_php_8_5'],
    ],
    'P85-PDO-DRIVER-METHOD' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.5 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.5/UPGRADING', 'quote' => 'Driver specific methods in the PDO class have been deprecated.'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecations_php_8_5', 'url' => 'https://wiki.php.net/rfc/deprecations_php_8_5'],
    ],
    'P85-SET-ACCESSIBLE' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.5 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.5/UPGRADING', 'quote' => 'The setAccessible() methods of various Reflection objects have been deprecated, as those no longer have an effect.'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecations_php_8_5', 'url' => 'https://wiki.php.net/rfc/deprecations_php_8_5'],
    ],
    'P85-SPLOBJECTSTORAGE' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.5 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.5/UPGRADING', 'quote' => 'The SplObjectStorage::contains(), SplObjectStorage::attach(), and SplObjectStorage::detach() methods have been deprecated in favour of SplObjectStorage::offsetExists(), SplObjectStorage::offsetSet(), and SplObjectStorage::offsetUnset() respectively.'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecations_php_8_5', 'url' => 'https://wiki.php.net/rfc/deprecations_php_8_5'],
    ],
    'P85-SLEEP-WAKEUP' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.5 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.5/UPGRADING', 'quote' => 'The __sleep() and __wakeup() magic methods have been soft-deprecated.'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecations_php_8_5', 'url' => 'https://wiki.php.net/rfc/deprecations_php_8_5'],
        ['kind' => 'rfc', 'title' => 'RFC: soft-deprecate-sleep-wakeup', 'url' => 'https://wiki.php.net/rfc/soft-deprecate-sleep-wakeup'],
    ],
    'P85-INI' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.5 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.5/UPGRADING', 'quote' => 'The report_memleaks INI directive has been deprecated.'],
        ['kind' => 'rfc', 'title' => 'RFC: deprecations_php_8_5', 'url' => 'https://wiki.php.net/rfc/deprecations_php_8_5'],
    ],
    'P85-SIMPLEXML-XPATH' => [
        ['kind' => 'php-src', 'title' => 'PHP 8.5 UPGRADING (php-src)', 'url' => 'https://github.com/php/php-src/blob/PHP-8.5/UPGRADING', 'quote' => 'Passing an XPath expression that returns something other than a node set to SimpleXMLElement::xpath() will now emit a warning and return false, instead of silently failing and returning an empty array.'],
    ],
];
