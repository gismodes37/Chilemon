# Archive Report: Bridge IAX2 Direction Reversal

**Date**: 2026-06-21
**Change**: `bridge-reversal`
**Archive Path**: `openspec/changes/archive/2026-06-21-bridge-reversal/`

## Summary

The bridge was flipped from IAX2 client to IAX2 server. Asterisk originates calls via AMI `Originate` with `Async: true`, bypassing ASL3's dropped-callno-0 filter. IAX2 frame parsers and audio pipeline are reused — only initiation direction changed.

## Task Completion

All **15 tasks** across 4 phases are marked `[x]` and verified:

| Phase | Tasks | Status |
|-------|-------|--------|
| Phase 1: Foundation (constants + AMI client skeleton) | 1.1–1.3 | ✅ Complete |
| Phase 2: Core — IAX2 Server Mode (IAX2Call, IAX2Server) | 2.1–2.4 | ✅ Complete |
| Phase 3: Integration — AMI + server.py Wiring | 3.1–3.4 | ✅ Complete |
| Phase 4: Asterisk Configuration (documented) | 4.1–4.4 | ✅ Complete |

## Artifact Lineage

| Artifact | Engram ID | File |
|----------|-----------|------|
| Proposal | #45 | `proposal.md` |
| Spec (ami-integration + webrtc-bridge) | #46 | `specs/ami-integration/spec.md`, `specs/webrtc-bridge/spec.md` |
| Design | #47 | `design.md` |
| Tasks | #48 | `tasks.md` |
| Apply Progress | #49 | (engram only) |
| Verify Report | #52 | `verify-report.md` |
| Archive Report | *(this)* | `archive-report.md` |

## Spec Sync

Both spec domains are NEW (no baseline existed):
- `specs/ami-integration/spec.md` → `openspec/specs/ami-integration/spec.md` ✅
- `specs/webrtc-bridge/spec.md` → `openspec/specs/webrtc-bridge/spec.md` ✅

## Verification Result

**PASS WITH WARNINGS** — All 15 tasks complete, all spec requirements implemented in source, all design decisions followed. The sole warning is absence of runtime test evidence (no Python test framework in project).

## Deviations from Design

1. **IAX_CMD_ACCEPT = 0x0E** — Same as PONG per RFC 5456, distinguished by call context. No ASL3 header change needed.
2. **Dynamic channel** — Uses `config.asl_node` env var instead of hardcoded `61916`.
3. **DTMF support** — Implemented immediately (design had it as future).
4. **Full AMI implementation** — Phase 1.3 delivered full client, not skeleton.

## Files Changed

| File | Action | Lines |
|------|--------|-------|
| `app/Services/WebRTCBridge/ami_client.py` | Created | 434 |
| `app/Services/WebRTCBridge/iax2.py` | Modified | +475/-2 |
| `app/Services/WebRTCBridge/server.py` | Modified | -12/+65 |
| `app/Services/WebRTCBridge/__init__.py` | Modified | +18 |
| `openspec/changes/bridge-reversal/asterisk-config.md` | Created | 115 |

## Next Steps

- Manual runtime validation against live ASL3 node before deployment
- Consider adding pytest with basic unit tests for `AMIClient._parse_ami_message()` and `IAX2Call` frame builders
