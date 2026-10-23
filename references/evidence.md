# Evidence: sources a reader can check

Every `wajib`, `deprecated` and `perilaku` note in `notes.json` carries `evidence`, and every suggested code
change carries the `proof` of the run on the target PHP. The report links them, so anyone can open the source
instead of trusting the audit. `validate_notes.php` warns when a note has none.

```json
"evidence": [{"kind": "php-src", "title": "PHP 8.4 UPGRADING: bcrypt cost 10 -> 12",
              "url": "https://github.com/php/php-src/blob/PHP-8.4/UPGRADING",
              "quote": "The default value of the 'cost' option for PASSWORD_BCRYPT ... increased from '10' to '12'."}],
"code": [{"before": "...", "after": "...",
          "proof": {"php": "8.5.12", "run": "php check.php", "output": "$2y$12$ ... 239 ms"}}]
```

## Which sources

In this order of preference:

| kind | Source | Link to |
|---|---|---|
| `php.net` | php.net migration guide or function page | `https://www.php.net/manual/en/migration84.other-changes.php`, `.../function.password-hash.php` |
| `rfc` | The RFC that made the change | `https://wiki.php.net/rfc/<name>` |
| `php-src` | `UPGRADING` / `NEWS` of the release branch | `https://github.com/php/php-src/blob/PHP-8.4/UPGRADING` |
| `upgrade`, `changelog`, `release` | The library's own upgrade guide, changelog or release notes, **at a tag** | `https://github.com/<owner>/<repo>/blob/<tag>/UPGRADE.md` |
| `source` | The line of code that does it, at a tag | `https://github.com/<owner>/<repo>/blob/<tag>/src/File.php` |
| `issue` | The PR or issue that introduced it | `https://github.com/<owner>/<repo>/pull/<n>` |
| `cve`, `advisory` | Security advisory | GitHub advisory, NVD |
| `run` | What you ran yourself | describe it in `proof`; a `run` link may point to a gist or CI log |

- Only cite what you opened. Never build a URL from memory.
- Link files at a **tag** or a release branch, not `main`, so the link still says the same thing later.
- Quote a short exact sentence; the reader then knows what to look for on the page.
- php.net pages are built from `github.com/php/doc-en` (`appendices/migrationXY/*.xml`); when php.net is not
  reachable, read the same text there.
- Only `http(s)` URLs become links in the report.

## Checked sources for common changes

Each entry was read at the linked source and, where it can be run, run on the PHP versions named.

### bcrypt default cost 10 -> 12 (PHP 8.4)

- Sources: [php-src PHP-8.4 UPGRADING](https://github.com/php/php-src/blob/PHP-8.4/UPGRADING) ("The default
  value of the 'cost' option for PASSWORD_BCRYPT for password_hash() has been increased from '10' to '12'"),
  [RFC bcrypt_cost_2023](https://wiki.php.net/rfc/bcrypt_cost_2023),
  [php.net migration84 other changes](https://www.php.net/manual/en/migration84.other-changes.php).
- Run (`password_hash('secret', PASSWORD_DEFAULT)`): PHP 7.4.33 and 8.3.36 give `$2y$10$` in about 60 ms;
  8.4.27 and 8.5.12 give `$2y$12$` in about 240 ms. `password_needs_rehash($cost10Hash, PASSWORD_DEFAULT)` is
  `false` on 8.3 and `true` on 8.4+, so old hashes are rewritten at the next login when the app rehashes.
  Passwords longer than 72 bytes are still truncated by bcrypt on all of them.
- Scanner rule: `P84-BCRYPT-COST`.

### firebase/php-jwt 7.0: minimum key size

- Sources: [CHANGELOG 7.0.0](https://github.com/firebase/php-jwt/blob/v7.0.0/CHANGELOG.md) ("add key size
  validation ... This fix will cause keys with a size below the minimally allowed size to break"),
  [PR #613](https://github.com/firebase/php-jwt/pull/613),
  [`JWT::validateHmacKeyLength()`](https://github.com/firebase/php-jwt/blob/v7.0.0/src/JWT.php): the key must
  have at least as many bits as the algorithm (HS256 32 bytes, HS384 48, HS512 64); RSA keys at least 2048 bits.
- Run on PHP 8.5.12, `JWT::encode(['sub' => 1], 'short-secret', 'HS256')`: v6.11.1 returns a token; v7.2.1
  throws `DomainException: Provided key is too short`. A 32-byte key works on both.
- Library note in `scripts/library_notes.php` (shown for installed versions below 7.0).

### guzzlehttp/guzzle 7.0, monolog/monolog 2.0 and 3.0

- Sources: [Guzzle UPGRADING.md 6.0 to 7.0](https://github.com/guzzle/guzzle/blob/7.0.0/UPGRADING.md),
  [Monolog UPGRADE.md 2.0.0](https://github.com/Seldaek/monolog/blob/2.0.0/UPGRADE.md),
  [Monolog UPGRADE.md 3.0.0](https://github.com/Seldaek/monolog/blob/3.0.0/UPGRADE.md).
- Library notes in `scripts/library_notes.php`.

## Adding an entry

1. Read the change at its source; note the tag and a quote.
2. Run it on the versions before and after when it can be run (Docker `php:X.Y-cli` is fine).
3. Add it here, and as a scanner rule (`evidence` field, see `references/rules.md`) or a library note
   (`scripts/library_notes.php`) when it can be detected. Add a test.
