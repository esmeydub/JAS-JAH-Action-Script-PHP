# JAS Diagnostic Engine

The JAS Diagnostic Engine turns rejected operations into stable, compact and
actionable incidents for humans and development-time agents. It does not weaken
contracts or bypass security. The component that detects a violation supplies
the context; the router does not guess the cause.

The design was motivated by an exploratory Gemini-assisted application session
reported by the project author. The agent initially spent substantial context
discovering why secure JAS boundaries rejected its code. Once constrained by the
JAS development protocol it produced a governed application. That experiment is
the design motivation only: no Gemini-generated implementation was copied into
the official engine.

## Flow

```text
validator/runtime
  -> DiagnosticException
  -> ErrorBoundary
  -> ExceptionMapper
  -> DiagnosticStore
  -> development or production reporter
```

Stable codes let tools identify a problem without interpreting changing prose:

| Code | Meaning | Authorized correction |
|---|---|---|
| `JAS-WEB-001` | HTML attribute outside the safe profile | use an allowlisted attribute or registered CSS class |
| `JAS-WEB-002` | unsafe HTML content | use JAS safe HTML primitives |
| `JAS-TYPE-001` | action input violates its contract | construct the declared input type |
| `JAS-TYPE-002` | handler output violates its contract | return the declared output type |
| `JAS-ACT-001` | declared action has no handler | register one governed handler |
| `JAS-CAP-001` | principal lacks the required capability | grant only the minimum capability through policy |
| `JAS-ROUTE-001` | request has no governed route | declare and connect the route |
| `JAS-PHP-001` | PHP file lacks strict types | add `declare(strict_types=1)` |
| `JAS-CORE-001` | sealed core changed | restore or explicitly review and reseal |
| `JAS-CORE-002` | unmapped runtime failure | inspect the local incident; do not expose internals |

Codes are append-only semantic contracts. A released code must not be reused for
a different meaning.

## Agent-efficient CLI

```bash
php bin/jas diagnose --last
php bin/jas diagnose --id JAS-20260717-A1B2C3D4E5F6
php bin/jas diagnose --code JAS-TYPE-001
php bin/jas diagnose --summary
```

The output is deterministic key/value text rather than JSON:

```text
CODE=JAS-WEB-001
COMPONENT=Html
ELEMENT=a
ATTRIBUTE=style
ACTION=REMOVE_INLINE_OR_EVENT_ATTRIBUTE
STATUS=REJECTED
```

An agent should apply the single authorized correction, rerun the failing check
and query the next incident only if rejection remains. It must not edit JAS core
files to make an application pass.

## Development and production

`DevelopmentDiagnosticReporter` exposes the code, sanitized context and one
correction. `ProductionDiagnosticReporter` exposes only a generic message and
incident ID. Neither reporter includes stack traces, request bodies, headers or
secrets.

Incidents are appended to `.jas/diagnostics/incidents.jasb` through the native
JAH/PHP serializer. Before persistence, keys and values related to passwords,
cookies, authorization, tokens, secrets, credentials, sessions and private keys
are redacted. Absolute server paths are reduced to a basename.

## Core integrity

```bash
php bin/jas core:seal
php bin/jas core:verify
```

The seal inventories `src/JAS`, `src/DataCore`, `bin/jas` and
`app/bootstrap.php`, then authenticates the manifest with a local 256-bit key.
Both files use mode `0600` under `.jas`. `core:verify` rejects missing, modified
and unexpected core files with `JAS-CORE-001`.

This protects against accidental or unauthorized application-development edits;
it is not protection against a host attacker who can read or replace both the
key and manifest. Legitimate JAS engine changes require review, the full suite
and an explicit reseal after installation.

## Verification

```bash
php tests/test_jas_diagnostics.php
php tests/run_all.php
```

Negative tests cover every initial code, redaction, development/production
separation, deterministic agent output, CLI exit status and core tampering.
