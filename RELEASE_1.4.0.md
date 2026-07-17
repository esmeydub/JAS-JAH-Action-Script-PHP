# JAS 1.4.0 — diagnostic engine

JAS 1.4.0 introduces stable, secure diagnostics designed to reduce correction
time and context consumption for human developers and development-time agents.

## Added

- stable diagnostic codes for safe HTML, contracts, actions, capabilities,
  routes, strict PHP types and core integrity;
- structured exceptions created at the component that detects the violation;
- an exception mapper and optional web error boundary;
- separate development and production reporters;
- append-only native JAS diagnostic storage with mandatory secret redaction;
- deterministic compact output through `php bin/jas diagnose`;
- authenticated core inventory through `core:seal` and `core:verify`;
- negative tests for codes, context, HTTP status, redaction, CLI and tampering.

## Compatibility

Existing exception messages remain stable where migrated so applications and
tests depending on those messages continue to work. The Router error boundary is
optional; existing construction remains valid. JAS core remains pure PHP and the
diagnostic journal uses the native JAH/PHP serializer rather than JSON.

## Security boundary

Production responses contain only a generic message and incident ID. The local
seal detects accidental core changes, but does not protect a host where an
attacker can replace both the seal key and manifest.
