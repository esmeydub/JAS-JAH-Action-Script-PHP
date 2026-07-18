# JAS — JAH Action Script PHP

JAS is a 100% pure-PHP typed language and runtime layer. Its types, routines,
DataCore and official tooling contain no JavaScript or embedded foreign runtime.
External languages integrate out of process through JASB adapters, following the
same boundary as the C and C++ SDKs.

## A secure, typed application runtime for PHP

**Hackathon category:** Developer Tools
**Status:** JAS 2.0.0 — stable public API and deployable reference application
**Development workflow:** Codex SOL, Medium reasoning effort
**Build Week disclosure:** GPT-5.6 is part of the required hackathon evaluation,
not a JAS runtime dependency

JAS — JAH Action Script PHP is an organized, typed layer over PHP for building web
systems whose contracts, authorization, persistence and audit rules should not
depend on developer discipline alone. It targets the point where a normal PHP
application becomes difficult to sustain: many teams, many domains, long-lived
data, background work, permissions, recovery and regulatory evidence.

JAS provides a definition-first application model, a governed action runtime,
secure web primitives and DataCore, its native append-only database. The project
is written in PHP and intentionally makes **no OpenAI API calls at runtime**. AI
accelerated the engineering process; the resulting tool remains local,
inspectable and deterministic.

> JAS is not externally certified and should not be described as government-
> certified software. It implements security controls intended for serious
> enterprise and public-sector evaluation, but deployment still requires an
> independent threat model, audit and operational hardening.

## The problem

PHP is easy to start and difficult to govern at scale. Large applications often
accumulate implicit array shapes, authorization checks scattered across
controllers, direct database access, hidden cross-domain dependencies and
recovery procedures that exist only in a maintainer's memory.

JAS turns those concerns into explicit definitions:

- types are validated at action boundaries;
- domains own actions and declare dependencies;
- capabilities are checked before execution;
- audit and idempotency are runtime properties;
- DataCore is the single governed persistence entry point;
- full collection scans must be explicitly requested;
- SQL can be a migration bridge or read-only mirror, never a silent backdoor;
- backups, transactions and compaction have tested recovery paths.

## What works today

| Area | Implemented behavior |
|---|---|
| Application model | Types, domains, events, actions, capabilities and production validation |
| Runtime | Governed execution, object graphs, idempotency, WAL, outbox and recovery coordination |
| DataCore | Typed documents, encryption, integrity signatures, transactions and crash recovery |
| Enterprise data | Unique/compound/partial/range indexes, constraints, references and reversible migrations |
| Privacy | Per-subject encryption keys, cryptographic destruction evidence, retention and legal hold |
| SQL adoption | Signed outbox, PDO prepared statements, allowlists, versioned mirror and governed import |
| Continuity | Encrypted signed `.jahb` backups, empty-tree restore, retention and snapshot point-in-time |
| Web security | Governed router, CSRF, secure headers, rate limiting, safe HTML and forms |
| Scale foundations | Persistent queues, leases, backpressure, workers, sharding, quorum and fencing |
| Operations | Health probes, read-only secure panel, disk admission, retention and signed JASB telemetry export |
| Tooling | Generators, analyzer, Language Intelligence Engine, stable diagnostics, core sealing, health checks and generated documentation |
| Reference application | Eight governed domains, institutional login/RBAC, DataCore, isolated queues, audit and verified restore |

Development follows the phase gates in
[`JAS_MASTER_PLAN.md`](JAS_MASTER_PLAN.md). Phases 1–10, the external standard
LSP gate and Phase 9 internal security verification are implemented. Independent
cryptographic review and penetration testing remain explicitly pending and are
not represented as project certification.

## Architecture

```mermaid
flowchart LR
    Request[HTTP / worker / event] --> Contract[Typed JAS contract]
    Contract --> Guard[Capability and policy guard]
    Guard --> Action[Governed action runtime]
    Action --> DataCore[(DataCore)]
    Action --> Outbox[Signed outbox]
    DataCore --> Audit[Integrity and audit evidence]
    DataCore --> Backup[Encrypted JAH backup]
    Outbox --> SQL[(Optional SQL mirror)]
    SQL -. never authoritative .-> DataCore
```

DataCore is authoritative. The dotted SQL arrow represents an explicit security
boundary: arbitrary SQL changes are detected as divergence and are not imported.
The only inbound SQL path is a limited, allowlisted migration that requires two
different approvers and revalidates every row through DataCore contracts.

## Five-minute judge setup

### Requirements

- PHP 8.2 or newer
- Sodium extension
- PCNTL extension for workers and the complete test suite
- Linux is the currently verified platform
- No Composer, Node, npm, JavaScript or JSON artifacts are required or accepted

Building the optional external LSP bridge from source requires a C++20 compiler,
RapidJSON headers and OpenSSL 3 development files. The verified Linux x86-64
package is statically linked and does not require those build dependencies on
the target; PHP/JAS remain its explicit runtime. The PHP/DataCore core remains
pure PHP and JASB.

```bash
git clone https://github.com/esmeydub/JAS-JAH-Action-Script-PHP.git
cd JAS-JAH-Action-Script-PHP
cp .env.example .env
php bin/jas health
php tests/run_all.php
```

Expected final line:

```text
JAS SUITE: PASS
```

To build and verify the external standard-LSP compatibility bridge:

```bash
make -C sdk/cpp/lsp test
sdk/cpp/lsp/jas-lsp-bridge "$(command -v php)" "$PWD/bin/jas" "$PWD"
tests/test_jas_lsp_distribution.sh
```

The executable uses stdio `Content-Length` framing for editors and starts the
PHP semantic service through fixed arguments without a shell. JSON-RPC is
confined to this C++ process; PHP and DataCore receive only authenticated JASB.
Distribution, client-profile compatibility, verification and installation are
documented in [`docs/JAS_LSP_DISTRIBUTION.md`](docs/JAS_LSP_DISTRIBUTION.md).

The suite includes positive, negative, concurrency, crash-recovery and tamper
tests. Its fuzz stage performs 500 valid round trips and rejects 500 corrupted
payloads.

The Phase 9 gate additionally exercises concurrent DataCore writers, forced
process death and restart, truncated transport, key rotation under load and
adversarial forms. Its threat model and OWASP ASVS-oriented control map are in
[`docs/JAS_SECURITY_VERIFICATION.md`](docs/JAS_SECURITY_VERIFICATION.md).

### Run the minimal web example

```bash
php -S 127.0.0.1:8080 examples/social_network.php
```

Open:

```text
http://127.0.0.1:8080/publicacion?id=POST-1
```

The example defines its types, domains, action contract, required capability,
audit behavior, handler and safe HTML response using public JAS APIs.

### Run the complete JAS 2.0 reference portal

```bash
cd examples/reference_portal
export JAS_ROOT="$OLDPWD"
export PORTAL_MASTER_KEY="$(openssl rand -base64 48)"
export PORTAL_IDENTITY_PEPPER="$(openssl rand -hex 48)"
export PORTAL_ADMIN_PASSWORD='reemplace-por-un-secreto-largo'
php bin/install.php
unset PORTAL_ADMIN_PASSWORD
php -S 127.0.0.1:8080 -t public
```

The portal uses form input and `text/plain` responses, not JSON. Installation,
routes, operations and disaster recovery are documented in
[`examples/reference_portal/README.md`](examples/reference_portal/README.md).

## Suggested three-minute demo path

1. Run `php bin/jas health` to show JAS 2.0 and the local pure-PHP runtime.
2. Run `php bin/jas analyze examples/reference_portal` to validate its definitions.
3. Show its eight domains and governed action contracts.
4. Run `php tests/test_jas_reference_portal.php` to demonstrate login, RBAC,
   publication, moderation, feed, messaging, queues, audit, load and restore.
5. Show the SQL attack tests: malicious SQL values remain data and SQL changes
   do not contaminate DataCore.

## Codex SOL workflow and the role of GPT-5.6

The repository work documented here was performed in **Codex SOL** with
**Medium reasoning effort**. Codex SOL was the engineering workflow used to
inspect the inherited PHP prototype, build a normative phase plan, refactor
duplicated runtime concepts, implement missing security and recovery paths,
generate adversarial tests, execute the suite and document measured
limitations.

OpenAI Build Week requires the submission to explain its relationship with
**GPT-5.6**. For JAS, GPT-5.6 belongs to the hackathon's development-time review
and evaluation requirement; it is not embedded in the product and is not a
runtime dependency. This README does not relabel the Codex SOL work as a
GPT-5.6 session. A separate GPT-5.6 review or demo and its `/feedback` Session ID
must be reported in the submission only after that session has actually been
completed.

Important human-directed decisions were preserved throughout the work:

- the project is JAS — JAH Action Script PHP, not a generic PHP framework;
- no JSON or JavaScript artifacts exist in the engine;
- no external AI connector belongs in the JAS runtime;
- DataCore remains the source of truth;
- SQL Mirror exists to reduce adoption anxiety, but SQL remains untrusted;
- security and organization must be enforced by definitions and APIs;
- benchmarks must publish actual results, even when DataCore loses.

Codex was especially useful for maintaining a large cross-cutting change set:
transaction visibility, recovery ordering, signed journals, index behavior,
governed SQL migration, backup integrity and their negative tests were reviewed
as one system rather than isolated files.

### SOL protocols included in the repository

- [`AGENTS.md`](AGENTS.md) is the development protocol for creating, reviewing
  and extending JAS applications with verified APIs and proportional security.
- [`LEARN_JAS_WITH_SOL.md`](LEARN_JAS_WITH_SOL.md) is the interactive teaching
  protocol for learning JAS from the installed version, examples and tests.

Both protocols treat the checked-out runtime and its executable tests as the
technical source of truth. They guide development and learning only: neither SOL
nor an AI service is required by JAS applications at runtime.

## Measured evidence

Benchmarks are reproducible and are not universal performance claims.

### DataCore versus SQLite — local microbenchmark

PHP 8.4.22 on Linux, 2,000 records and 1,000 indexed reads:

| Engine | Write | 1,000 reads | Process CPU | Incremental peak | Disk |
|---|---:|---:|---:|---:|---:|
| DataCore | 0.926700 s | 280.437432 ms | 1.200917 s | 2.00 MiB | 4.66 MiB |
| SQLite | 0.008646 s | 3.243097 ms | 0.006756 s | 0 B observed by PHP | 361.93 KiB |

SQLite wins this microbenchmark. The measurement exposed a repeated index journal
read; caching reduced DataCore's 1,000 reads from about 1,623 ms to about 280 ms.
The full methodology and caveats are in
[`docs/DATACORE_BENCHMARKS.md`](docs/DATACORE_BENCHMARKS.md).

### Backup and restore — local microbenchmark

For 5,000 records: create 0.068148 s, verify 0.031700 s and restore
0.109296 s. The restored DataCore lookup passed. See
[`docs/DATACORE_BACKUP.md`](docs/DATACORE_BACKUP.md).

## Useful commands

```bash
php bin/jas health
php bin/jas disk:status
php bin/jas retention:run --force
curl --fail http://127.0.0.1/health/live
curl --fail http://127.0.0.1/health/ready
curl -H "Authorization: Bearer $JAS_OPERATIONS_TOKEN" http://127.0.0.1/operations
php bin/jas test
php bin/jas make:project /tmp/jas-demo "JAS Demo"
php bin/jas analyze /tmp/jas-demo
php bin/jas diagnose --last
php bin/jas core:verify
php bin/jas audit:verify
php bin/jas events:verify
php benchmarks/datacore_sql.php 2000
php benchmarks/datacore_backup.php 5000
```

La ruta completa, desde el proyecto vacío hasta documentación y verificación de
compatibilidad, está en [Crear una aplicación JAS funcional](docs/JAS_GETTING_STARTED.md).

## Repository guide

- `src/JAS/` — typed definitions, runtime, security, web, queues and cluster primitives
- `src/DataCore/` — database, transactions, indexes, SQL Mirror and continuity
- `examples/social_network.php` — smallest complete governed web example
- `examples/reference_portal/` — deployable JAS 2.0 institutional reference portal
- `tests/` — executable security, recovery, fuzz and integration evidence
- `benchmarks/` — reproducible local measurements
- `docs/` — subsystem and operational documentation
- `sdk/` — experimental C and C++ protocol SDKs
- `JAS_MASTER_PLAN.md` — ordered completion plan and phase evidence
- `docs/API_STATUS.md` and `docs/JAS_2_0_MIGRATION.md` — frozen API and upgrade contract

## Security model and limitations

- The engine uses native JAH/PHP and JASB formats and rejects JSON/JavaScript artifacts.
- Secrets belong in `.env`; the file is ignored by Git.
- Sensitive DataCore fields can be encrypted and cannot be exposed to SQL Mirror.
- Signed evidence detects alteration; it does not prevent host-level compromise.
- Snapshot point-in-time currently has snapshot granularity, not per-WAL-event precision.
- New systems use encrypted DataCore institutional identity; the older flat-file
  `AuthStore` remains only as a compatibility provider.
- Production deployment requires key management, least-privilege filesystem
  accounts, monitoring, external review and disaster exercises for the target environment.

Security architecture is documented in
[`docs/DATACORE_DATABASE.md`](docs/DATACORE_DATABASE.md),
[`docs/DATACORE_SQL_MIRROR.md`](docs/DATACORE_SQL_MIRROR.md) and
[`JAS_ARCHITECTURE.md`](JAS_ARCHITECTURE.md).

Human- and agent-efficient failure handling, redaction and core sealing are
documented in [`docs/JAS_DIAGNOSTICS.md`](docs/JAS_DIAGNOSTICS.md).

## License

MIT — see [`LICENSE`](LICENSE).
