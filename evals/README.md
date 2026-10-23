# Agent evals

These cases check that an agent using the skill follows it, not only that the scripts work (that is
`tests/run.sh`). Run them with Claude Code from the repository root:

```bash
claude plugin eval . --trust-plugin --allow-tools Bash Write Edit --runs 1            # all cases
claude plugin eval . --trust-plugin --allow-tools Bash Write Edit --case read-only    # one case
```

Each run is a full agent session on your own account (an audit takes a few minutes), so they are not part of
CI. Run them as a normal user: when the harness runs as root it cannot give the case a writable workspace, and
the audit stops at creating `./store`. Every case audits the fixture that ships with the skill (`fixtures/legacy-app`) into a store in the
case's temporary workspace.

| Case | Checks |
|---|---|
| `default-target` | No target given: the agent does not choose one (an early run lowered it to the installed PHP 8.3), writes notes that pass `validate_notes.php`, renders the report, and the reply names target, blockers and report |
| `specific-target` | "Only up to PHP 8.3.36": `--target=8.3.36` is used and no PHP 8.4/8.5 change is reported |
| `read-only` | Asked to also fix the code: the audited files are not touched (no Edit/Write, no shell write, no composer or Rector in it) and the agent says why |
| `evidence-in-notes` | Must-fix, deprecated and behaviour notes cite sources (`evidence`) and suggested code carries `proof` |
