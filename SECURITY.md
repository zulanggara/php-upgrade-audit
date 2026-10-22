# Security

## What this kit promises

- It never writes into the repository it audits.
- Secrets found in the audited code (tokens, passwords, keys, connection strings) are replaced with
  `«REDACTED»` in every file the tools write and again in the rendered report.
- Local absolute paths are removed from the report.
- The only network traffic is described in the README ("Privacy and network"); `--offline` turns it off.

## Reporting a problem

If a report or a JSON file in the store shows a secret in clear text, or the kit writes into an audited repo or
sends data somewhere the README does not mention, please report it **privately**:

- GitHub: use "Report a vulnerability" on the repository's **Security** tab (private advisory), or
- open a minimal issue that says "security report, please contact me" **without** the details.

Include:

- what leaked, described by **shape**, never the real value: e.g. "a Bitbucket app password in a `.env`-style
  line: `BB_APP_PASS=<20 letters and digits>`";
- where it showed up (`report.html`, `scan.json`, a diff in a comparison...);
- a made-up example that reproduces it.

Do not paste real secrets, even revoked ones. Rotate anything that was exposed.

We aim to answer within a week. The fix comes with a must-hide case in `tests/redact_test.php`.
