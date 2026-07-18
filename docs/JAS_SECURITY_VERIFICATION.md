# JAS security and failure verification

This document records the internal Phase 9 security gate used for JAS 2.0.0. It is
reproducible engineering evidence, not an external audit, penetration test,
certification or guarantee that a deployment is secure.

## Trust boundaries

| Boundary | Untrusted input | Enforced control | Residual assumption |
|---|---|---|---|
| HTTP to JAS Web | Headers, routes, forms and files | typed parsing, CSRF, allowlists, safe HTML, rate limits and upload custody | TLS and reverse-proxy hardening belong to deployment |
| Action runtime | Actor, payload and repeated request | contracts, capabilities, policy, idempotency and audit | application capabilities must be designed correctly |
| JAS to DataCore | Documents, transactions and queries | typed collections, integrity, locking, indexes and governed APIs | a compromised host can access process memory |
| SQL mirror/import | SQL rows and database state | prepared statements, allowlists, signed outbox, divergence detection and dual control | SQL is never authoritative |
| Node transport | Frames, peers, replay and network faults | bounded framing, authenticated encryption, identities, replay checks, quorum and fencing | production PKI, clocks and network policy remain operational duties |
| Editor to LSP bridge | JSON-RPC and workspace paths | external bridge limits, sandbox, authenticated JASB and read-only workspace | only verified platform packages are supported |

## Threats and mitigations

- Injection and cross-site scripting: literal definitions, prepared SQL,
  allowlisted HTML and form fuzzing reject executable input.
- Broken access control: roles are convenience groupings; capabilities and
  policy checks govern each sensitive action. Dual control protects selected
  critical operations.
- Data corruption and interrupted writes: append-only records, locks, WAL,
  checksums, backups and recovery tests preserve acknowledged state and reject
  malformed tails.
- Replay and impersonation: signed packets, timestamp windows, durable replay
  guards and node identities fail closed.
- Resource exhaustion: payload, frame, queue, query, disk and LSP concurrency
  limits apply before expensive work.
- Supply chain: the core has no Composer, Node or JavaScript dependency. The
  optional LSP artifact has reproducible builds, SBOM, hash, signature and CI
  provenance.
- Key compromise: purpose-derived keys and explicit key IDs permit rotation.
  Old keys must remain only for the approved decryption window; deleting one is
  intentionally irreversible.

## OWASP ASVS-oriented checklist

This is a control mapping, not a claim of ASVS certification.

| ASVS area | JAS evidence | Status |
|---|---|---|
| V1 Architecture | domains, governed runtime, this threat model | internally verified |
| V2 Authentication | institutional identity, MFA, recovery and device tests | internally verified |
| V3 Session management | secure cookies, expiry, rotation and revocation tests | internally verified |
| V4 Access control | capabilities, roles, delegation, separation and dual control | internally verified |
| V5 Validation | typed contracts, forms, uploads, safe HTML and fuzz tests | internally verified |
| V6 Cryptography | Sodium primitives, KeyRing rotation and encrypted envelopes | implemented; external cryptographic review pending |
| V7 Error/logging | safe HTTP errors, audit chains and telemetry boundaries | internally verified |
| V8 Data protection | field encryption, subject keys, retention and backups | internally verified |
| V9 Communications | authenticated JASB framing and replay protection | internally verified; deployment TLS/PKI pending |
| V10 Malicious code | literal parser and no execution of analyzed definitions | internally verified |
| V11 Business logic | contracts, idempotency, queues and dual control | internally verified |
| V12 Files | upload policy, scanning interface and custody audit | internally verified; scanner deployment required |
| V13 API | governed router, request limits and capability enforcement | internally verified |
| V14 Configuration | health checks, secure defaults and operational guidance | internally verified; target hardening required |

## Reproducible gate

Run:

```bash
php tests/test_jas_phase9.php
php tests/run_all.php
```

The Phase 9 test covers four concurrent DataCore writers, forced process death,
restart verification, fragmented and truncated transport data, frame limits, 1,000
key rotations/decryptions and 500 adversarial form submissions. Existing suite
tests add JASB fuzzing, transactions, backup tampering, SQL contamination,
identity abuse, queue recovery, LSP fuzzing and cluster fencing.

## External work required before critical deployment

An independent cryptographic design review and an authenticated penetration test
have not been performed. Their reports must identify scope, version, environment,
methodology, findings, remediation and retest. JAS must not label these internal
tests as an external report or universal certification. Critical deployments
also require their own threat model, ASVS verification, infrastructure review,
secret management, incident response and disaster exercise.
