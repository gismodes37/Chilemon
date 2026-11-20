"""
Tests for app/Services/WebRTCBridge/iax2.py — IAX2 frame encoding.

Covers the three protocol defects that blocked TX/RX:
  1. CallToken round-trip on the NEW flow (Asterisk subclass 40).
  2. IE_USERNAME missing from the outgoing NEW.
  3. Mini voice frames built with F=1 and the destination call number.

Run with: python -m pytest tests/companion/iax2_test.py -v
"""

from __future__ import annotations

import asyncio
import hashlib
import struct
import time

import app.Services.WebRTCBridge.iax2 as iax2
from app.Services.WebRTCBridge.iax2 import (
    IAX2Call,
    IAX2Session,
    IAX_TYPE_IAX,
    IE_CALLTOKEN,
    IE_MD5_RESULT,
    IE_USERNAME,
    IAX_CMD_AUTHREP,
    IAX_CMD_NEW,
    _make_ie,
    _parse_ies,
)

# Asterisk's CallToken challenge subclass. chan_iax2/iAX2.h:
#   IAX_COMMAND_CALLTOKEN = 40 (0x28)
CALLTOKEN_SUBCLASS = 40

TOKEN = b"1795000000?0123456789abcdef0123456789abcdef01234567"


class _CapturedTransport:
    """Minimal stand-in for asyncio.DatagramTransport."""

    def __init__(self) -> None:
        self.sent: list[bytes] = []

    def sendto(self, data: bytes, addr: object = None) -> None:  # noqa: D102
        self.sent.append(data)


def _session() -> tuple[IAX2Session, _CapturedTransport]:
    sess = IAX2Session(
        host="192.168.0.116",
        port=4569,
        username="companion-app",
        password="unit-test-secret",
    )
    transport = _CapturedTransport()
    sess._transport = transport  # type: ignore[assignment]
    sess._start_ts = time.monotonic()
    return sess, transport


def _in_call_session() -> tuple[IAX2Session, _CapturedTransport]:
    sess, transport = _session()
    sess._state = sess.STATE_ACTIVE
    sess._callno = 0x0002
    sess._dest_callno = 0x002A
    return sess, transport


def _mini_call() -> tuple[IAX2Call, _CapturedTransport]:
    transport = _CapturedTransport()
    call = IAX2Call(
        callno=0x0005,
        peer_callno=0x002A,
        called_num="*0",
        peer_addr=("192.168.0.116", 4569),
        transport=transport,  # type: ignore[arg-type]
        start_ts=time.monotonic(),
    )
    call.state = iax2.CALL_STATE_ACTIVE
    return call, transport


def _assert_mini_frame(frame: bytes, expected_callno: int, label: str) -> None:
    assert len(frame) >= 4, f"{label}: mini frame header must be at least 4 bytes"
    type_field = struct.unpack("!H", frame[:2])[0]
    f_bit = (type_field >> 15) & 1
    assert f_bit == 0, (
        f"{label}: mini frame must set F=0 (RFC 5456 §8.1.2 — mini frames "
        "MUST NOT have the F bit set); got F=1"
    )
    callno = type_field & 0x7FFF
    assert callno == expected_callno, (
        f"{label}: mini frame must carry the SOURCE call number "
        f"{expected_callno}, got {callno}"
    )


# ---------------------------------------------------------------------------
# 3. Mini voice frames (TX audio)
# ---------------------------------------------------------------------------


def test_session_send_voice_mini_frame_f_bit_is_zero() -> None:
    """TX mini frames must have F=0 so Asterisk parses them as mini frames."""
    sess, transport = _in_call_session()
    payload = bytes(160)

    # Frame 1 announces the codec (full frame); the mini frames follow it.
    assert sess.send_voice(payload) is True
    assert sess.send_voice(payload) is True
    assert len(transport.sent) == 2

    _assert_mini_frame(transport.sent[1], sess._callno, "IAX2Session.send_voice")
    assert transport.sent[1][4:] == payload, "audio payload must follow the 4-byte header"


def test_session_send_voice_carries_source_callno_not_destination() -> None:
    """The mini frame field is the sender's own call number, not the peer's."""
    sess, transport = _in_call_session()
    assert sess._callno != sess._dest_callno, "test fixture must distinguish the two"

    sess.send_voice(bytes(160))  # codec announcement (full frame)
    sess.send_voice(bytes(160))  # first mini frame
    _assert_mini_frame(transport.sent[1], sess._callno, "IAX2Session.send_voice")


def test_session_send_voice_requires_active_call() -> None:
    """No call, no audio — the TX gate must still hold."""
    sess, transport = _session()
    sess._state = sess.STATE_REGISTERED

    assert sess.send_voice(bytes(160)) is False
    assert transport.sent == []


def test_legacy_send_voice_mini_frame_f_bit_and_source_callno() -> None:
    """IAX2Call.send_voice (server-side path) must obey the same mini-frame rules."""
    call, transport = _mini_call()
    payload = bytes(160)

    call.send_voice(payload)
    assert len(transport.sent) == 1

    _assert_mini_frame(transport.sent[0], call.callno, "IAX2Call.send_voice")
    assert transport.sent[0][4:] == payload


def test_session_send_voice_media_timestamp_counter() -> None:
    """TX timestamps must be a media counter, not the wall clock.

    The sound device delivers two buffers inside the same millisecond, so a
    monotonic-clock timestamp repeats (observed live deltas 36,0,45,0) and
    Asterisk's jitter buffer (forcejitterbuffer=yes) discards the duplicate —
    ~46% loss in the *0 echo. Each frame's ts must advance by exactly its
    payload duration (len//8 ms for 8 kHz ulaw: 160 bytes = 20 ms).
    """
    sess, transport = _in_call_session()
    payload = bytes(160)  # 20 ms of 8 kHz ulaw

    for _ in range(5):
        assert sess.send_voice(payload) is True

    # Frame 1 is the full VOICE codec announcement; frames 2-5 are mini frames.
    assert len(transport.sent) == 5
    full_ts = struct.unpack("!I", transport.sent[0][4:8])[0]
    mini_ts = [struct.unpack("!H", f[2:4])[0] for f in transport.sent[1:]]

    assert full_ts == 0, (
        "the first full VOICE frame must anchor the media timeline at 0, "
        f"got {full_ts}"
    )
    assert mini_ts == [20, 40, 60, 80], (
        "consecutive TX frames must carry strictly increasing media "
        f"timestamps stepping by the payload duration, got {mini_ts}"
    )


def test_session_send_voice_timestamps_do_not_duplicate_under_burst() -> None:
    """Back-to-back frames (same wall-clock ms) must never share a timestamp."""
    sess, transport = _in_call_session()
    payload = bytes(160)

    for _ in range(10):
        assert sess.send_voice(payload) is True

    ts_values = [struct.unpack("!H", f[2:4])[0] for f in transport.sent[1:]]
    assert len(ts_values) == len(set(ts_values)), (
        "duplicate TX timestamps make Asterisk drop frames as replays: "
        f"{ts_values}"
    )


def test_session_send_voice_media_counter_resets_per_call() -> None:
    """A new call must restart the media timeline (Asterisk re-anchors on it)."""
    sess, transport = _in_call_session()
    for _ in range(3):
        sess.send_voice(bytes(160))

    # Simulate start_call's per-call reset without the network handshake.
    sess._voice_format_sent = False
    sess._tx_media_ts = 0

    sess.send_voice(bytes(160))  # full VOICE frame of the new call
    full_ts = struct.unpack("!I", transport.sent[-1][4:8])[0]
    assert full_ts == 0, f"new call must restart media ts at 0, got {full_ts}"


def test_legacy_send_voice_media_timestamp_counter() -> None:
    """IAX2Call.send_voice must use the same media counter (no wall clock)."""
    call, transport = _mini_call()
    payload = bytes(160)

    for _ in range(4):
        call.send_voice(payload)

    ts_values = [struct.unpack("!H", f[2:4])[0] for f in transport.sent]
    assert ts_values == [0, 20, 40, 60], (
        f"legacy TX path must step ts by 20 ms per frame, got {ts_values}"
    )


# ---------------------------------------------------------------------------
# 2. IE_USERNAME on the NEW
# ---------------------------------------------------------------------------


def test_new_carries_username_ie() -> None:
    """Asterisk resolves user/context from IE_USERNAME in the NEW."""
    sess, transport = _session()

    sess._send_new("*0", "12345")

    frame = transport.sent[0]
    assert frame[10] == IAX_TYPE_IAX
    assert frame[11] & 0x7F == IAX_CMD_NEW

    ies = _parse_ies(frame[12:])
    assert IE_USERNAME in ies, "NEW must carry IE_USERNAME so Asterisk can find the user"
    assert ies[IE_USERNAME] == b"companion-app"
    assert ies[0x01] == b"*0", "NEW must still carry IE_CALLED_NUMBER"


def test_new_calltoken_ie_is_empty_on_first_attempt() -> None:
    """The first NEW advertises CallToken support with an empty IE (Case 1)."""
    sess, transport = _session()

    sess._send_new("*0", "12345")

    ies = _parse_ies(transport.sent[0][12:])
    assert IE_CALLTOKEN in ies, "NEW must advertise CallToken support"
    assert ies[IE_CALLTOKEN] == b""


# ---------------------------------------------------------------------------
# 1. CallToken round-trip
# ---------------------------------------------------------------------------


def test_calltoken_constant_is_defined_and_matches_asterisk() -> None:
    """IAX_CMD_CALLTOKEN must be exported and equal chan_iax2's value 40."""
    assert getattr(iax2, "IAX_CMD_CALLTOKEN", None) == CALLTOKEN_SUBCLASS


def _ctoken_frame(payload: bytes) -> bytes:
    """Build an Asterisk CallToken challenge (apathetic reply, src callno=1)."""
    return (
        struct.pack(
            "!HHIBBBB",
            0x8000 | 1,          # F=1, source callno = 1 (apathetic reply)
            0x0002,              # dcallno = our callno
            0,                   # ts
            0,                   # oseqno
            1,                   # iseqno
            IAX_TYPE_IAX,
            CALLTOKEN_SUBCLASS,
        )
        + payload
    )


def test_calltoken_challenge_resends_new_with_the_token() -> None:
    """Asterisk's CallToken challenge must be answered with the same NEW + token."""
    sess, transport = _in_call_session()
    sess._send_new("*0", "12345")
    transport.sent.clear()

    sess._on_datagram(_ctoken_frame(_make_ie(IE_CALLTOKEN, TOKEN)))

    assert transport.sent, "CallToken challenge must trigger a NEW retransmission"
    frame = transport.sent[0]
    assert frame[10] == IAX_TYPE_IAX
    assert frame[11] & 0x7F == IAX_CMD_NEW, "response must be a NEW, got subclass %d" % (
        frame[11] & 0x7F,
    )

    ies = _parse_ies(frame[12:])
    assert IE_CALLTOKEN in ies, "response NEW must carry the CallToken IE"
    assert ies[IE_CALLTOKEN] == TOKEN, "CallToken must be echoed back byte-for-byte"
    assert ies[IE_USERNAME] == b"companion-app"
    assert ies[0x01] == b"*0", "the resent NEW must keep the original called number"

    # The resent NEW is a fresh frame: dest_callno stays 0, source callno unchanged.
    assert struct.unpack("!HH", frame[:4])[1] == 0, "dcallno must stay 0 (new dialog)"
    assert struct.unpack("!H", frame[:2])[0] & 0x7FFF == sess._callno


def test_calltoken_challenge_resets_outbound_sequence() -> None:
    """chan_iax2 resets its sequence numbers when re-sending with a token."""
    sess, transport = _in_call_session()
    sess._send_new("*0", "12345")
    sess._oseqno = 7  # simulate an established sequence before the challenge
    transport.sent.clear()

    sess._on_datagram(_ctoken_frame(_make_ie(IE_CALLTOKEN, TOKEN)))

    frame = transport.sent[0]
    oseqno = struct.unpack("!HHIBBBB", frame[:12])[3]
    assert oseqno == 0, f"resent NEW must start at oseqno 0, got {oseqno}"


def test_calltoken_challenge_without_token_ie_is_ignored() -> None:
    """A malformed challenge must not emit a bogus NEW."""
    sess, transport = _in_call_session()
    sess._send_new("*0", "12345")
    transport.sent.clear()

    sess._on_datagram(_ctoken_frame(b""))

    assert transport.sent == [], "empty CallToken IE must not trigger a retransmission"


# ---------------------------------------------------------------------------
# 4. AUTHREP MD5 encoding (second handshake wall)
# ---------------------------------------------------------------------------


def test_authrep_md5_is_32_char_hex_string_not_raw_digest() -> None:
    """chan_iax2 formats the MD5 as 32 ASCII hex chars and strcasecmp-compares it.

    authenticate_verify() does:
        for (x=0; x<16; x++) sprintf(requeststr + (x<<1), "%02hhx", digest[x]);
        if (!strcasecmp(requeststr, md5secret)) ...
    Sending the raw 16-byte digest can never match that string, so Asterisk
    re-challenges forever and the call dies with "no NEWACK".
    """
    sess, transport = _session()
    sess._state = sess.STATE_CALLING
    sess._call_auth_challenge = b"153881098"
    sess._dest_callno = 1636

    sess._send_authrep()

    assert transport.sent, "AUTHREP must be sent"
    frame = transport.sent[0]
    assert frame[10] == IAX_TYPE_IAX
    assert frame[11] & 0x7F == IAX_CMD_AUTHREP

    ies = _parse_ies(frame[12:])
    assert IE_MD5_RESULT in ies, "AUTHREP must carry IE_MD5_RESULT"

    value = ies[IE_MD5_RESULT]
    assert len(value) == 32, (
        f"IE_MD5_RESULT must be the 32-char hex string Asterisk compares, "
        f"got {len(value)} bytes (raw digest is 16)"
    )
    value.decode("ascii")  # must be printable ASCII, not binary

    expected = hashlib.md5(
        b"153881098" + sess.password.encode("utf-8")
    ).hexdigest()
    assert value.decode("ascii") == expected, (
        "hex must be MD5(challenge + password) as lowercase hex"
    )


def test_authrep_without_stored_challenge_sends_nothing() -> None:
    """No challenge stored → no frame, rather than a bogus AUTHREP."""
    sess, transport = _session()
    sess._state = sess.STATE_CALLING
    sess._call_auth_challenge = None

    sess._send_authrep()

    assert transport.sent == [], "must not send AUTHREP without a challenge"


# ---------------------------------------------------------------------------
# 4. Full VOICE frame must precede the first mini frame
# ---------------------------------------------------------------------------


def test_send_voice_emits_full_voice_frame_before_first_mini_frame() -> None:
    """The first TX voice frame must be a full frame declaring the codec.

    chan_iax2 keeps ``pvt->voiceformat = 0`` until a full VOICE frame
    arrives; while it is 0 every mini frame hits::

        ast_debug(1, "Received mini frame before first full voice frame");
        iax2_vnak(callno);

    so the call dies in a VNAK storm and HANGUP. The full frame's subclass
    is the legacy codec bitfield (``AST_FORMAT_ULAW = 1ULL << 2 = 0x04``),
    parsed via ``bitfield2format(uncompress_subclass(fh->csub))``.
    """
    sess, transport = _in_call_session()
    payload = bytes(160)

    assert sess.send_voice(payload) is True
    assert sess.send_voice(payload) is True
    assert len(transport.sent) >= 2, "send_voice must emit frames"

    first, second = transport.sent[0], transport.sent[1]

    assert len(first) >= 12, (
        "first voice frame must be a full IAX2 frame (12-byte header), "
        f"got {len(first)} bytes"
    )
    first_type = struct.unpack("!H", first[:2])[0]
    assert (first_type >> 15) & 1 == 1, "first voice frame must set F=1 (full frame)"
    assert first[10] == iax2.IAX_TYPE_VOICE, (
        f"full frame frametype must be VOICE (0x02), got 0x{first[10]:02X}"
    )
    assert first[11] >> 7 == 0, "full VOICE frame must not set the C (critical) flag"
    assert first[11] & 0x7F == iax2.CODEC_ULAW, (
        "full VOICE frame subclass must be the codec bitfield "
        f"AST_FORMAT_ULAW (1<<2 = 0x04), got 0x{first[11] & 0x7F:02X}"
    )

    _assert_mini_frame(second, sess._callno, "IAX2Session.send_voice (after full)")


def test_send_voice_sends_exactly_one_full_voice_frame() -> None:
    """voiceformat is sticky on Asterisk's side — announce the codec once."""
    sess, transport = _in_call_session()
    payload = bytes(160)

    for _ in range(5):
        assert sess.send_voice(payload) is True

    full = [
        f for f in transport.sent
        if len(f) >= 12 and (struct.unpack("!H", f[:2])[0] >> 15) & 1
    ]
    assert len(full) == 1, (
        f"expected exactly one full VOICE frame per call, got {len(full)}"
    )
    assert len(transport.sent) == 5, (
        f"expected 1 full + 4 mini frames, got {len(transport.sent)}"
    )


def test_full_voice_frame_advances_oseqno() -> None:
    """chan_iax2 DROPS out-of-order full frames, so the VOICE frame must
    carry the exact oseq it expects and advance our counter past it."""
    sess, transport = _in_call_session()
    sess._oseqno = 2  # Asterisk expects 2 after AUTHREP
    payload = bytes(160)

    assert sess.send_voice(payload) is True
    voice = transport.sent[0]
    assert voice[8] == 2, (
        f"full VOICE frame must carry the expected oseq 2, got {voice[8]}"
    )
    assert sess._oseqno == 3, (
        "full VOICE frame must advance _oseqno — chan_iax2 increments "
        f"iseqno on it (got {sess._oseqno}, want 3)"
    )

    assert sess.send_dtmf("5") is True
    dtmf = transport.sent[1]
    assert dtmf[8] == 3, (
        "frames after the full VOICE frame must carry the next oseqno; "
        f"chan_iax2 drops them as out-of-order (got {dtmf[8]}, want 3)"
    )


def test_authrep_advances_oseqno() -> None:
    """AUTHREP is a normal full frame: chan_iax2 increments iseqno on it,
    so our counter must advance too or every later frame is dropped as
    out-of-order (that is what produced the VNAK storm)."""
    sess, transport = _session()
    sess._state = sess.STATE_CALLING
    sess._callno = 0x0002
    sess._dest_callno = 0x002A
    sess._call_auth_challenge = b"153881098"
    sess._oseqno = 1

    sess._send_authrep()

    assert transport.sent, "_send_authrep must emit a frame"
    frame = transport.sent[0]
    assert frame[8] == 1, f"AUTHREP must carry oseq 1, got {frame[8]}"
    assert sess._oseqno == 2, (
        "AUTHREP must advance _oseqno — chan_iax2 increments iseqno on it, "
        f"so a stale value makes every later frame out-of-order "
        f"(got {sess._oseqno}, want 2)"
    )


def test_start_call_resets_voice_format_announcement() -> None:
    """``voiceformat`` is per-call on Asterisk's side, so every new call
    must re-announce the codec. Without the reset only the first call of a
    process works and every later one dies in a VNAK storm."""

    class _ImmediateEvent:
        """Stand-in for asyncio.Event that is always already signalled."""

        def clear(self) -> None:
            pass

        def set(self) -> None:
            pass

        async def wait(self) -> bool:
            return True

    sess, _transport = _session()
    sess._state = sess.STATE_REGISTERED
    sess._voice_format_sent = True  # left over from the previous call
    sess._response_event = _ImmediateEvent()  # type: ignore[assignment]
    sess._send_new = lambda *a, **kw: None  # type: ignore[method-assign]

    assert asyncio.run(sess.start_call("*0", "12345")) is True
    assert sess._voice_format_sent is False, (
        "start_call must reset the codec announcement — voiceformat is "
        "per-call on Asterisk's side, so a stale True skips the full "
        "VOICE frame and the call dies in a VNAK storm"
    )


# ---------------------------------------------------------------------------
# 5. The RX timestamp must reach the jitter buffer
# ---------------------------------------------------------------------------


def _collect_ts(sess: IAX2Session, frames: list[bytes]) -> list[int]:
    """Feed *frames* through the RX path and return the timestamps the
    audio callback observed."""
    captured: list[int] = []

    async def _sink(payload: bytes, ts_ms: int) -> None:
        captured.append(ts_ms)

    sess.on_audio_frame = _sink  # type: ignore[assignment]

    async def _run() -> None:
        for frame in frames:
            if len(frame) >= 12 and (struct.unpack("!H", frame[:2])[0] >> 15) & 1:
                sess._on_full_frame(frame)
            else:
                sess._on_mini_frame(frame)
        await asyncio.sleep(0.01)

    asyncio.run(_run())
    return captured


def _full_voice(sess: IAX2Session, ts: int, payload: bytes) -> bytes:
    return struct.pack(
        "!HHIBBBB",
        0x8000 | (sess._dest_callno & 0x7FFF),
        0,
        ts,
        3,
        4,
        iax2.IAX_TYPE_VOICE,
        iax2.CODEC_ULAW,
    ) + payload


def _mini(sess: IAX2Session, ts16: int, payload: bytes) -> bytes:
    return struct.pack("!HH", sess._callno & 0x7FFF, ts16) + payload


def test_mini_frame_passes_timestamp_to_audio_callback() -> None:
    """The jitter buffer orders RX frames by IAX2 timestamp, so the
    callback must carry it.

    ``play_ulaw`` previously keyed frames by ``time.monotonic()``; those
    values never line up with the buffer's expectations, so playout could
    not find the frames it was holding.
    """
    sess, _transport = _in_call_session()
    payload = bytes([0xAA] * 160)

    captured = _collect_ts(sess, [_mini(sess, 1234, payload)])

    assert len(captured) == 1, (
        "on_audio_frame must fire exactly once per incoming mini frame, "
        f"got {len(captured)}"
    )
    assert captured[0] == 1234, (
        "the mini frame's 16-bit timestamp must reach the callback, "
        f"got {captured[0]}"
    )


def test_full_voice_frame_passes_32bit_timestamp() -> None:
    """Asterisk announces the codec with a full VOICE frame whose timestamp
    is 32-bit; it anchors the timeline the 16-bit mini frames extend."""
    sess, _transport = _in_call_session()
    payload = bytes([0x55] * 160)

    captured = _collect_ts(sess, [_full_voice(sess, 70000, payload)])

    assert len(captured) == 1, "full VOICE frame must reach the audio callback"
    assert captured[0] == 70000, (
        "the full frame's 32-bit timestamp must reach the callback, "
        f"got {captured[0]}"
    )


def test_rx_timestamp_unwraps_16bit_rollover() -> None:
    """A call past 65.5 s wraps the mini frame's 16-bit timestamp.

    Without unwrapping against the full-frame anchor the timeline jumps
    backwards to ~0 and every later frame is discarded as stale.
    """
    sess, _transport = _in_call_session()
    payload = bytes(160)

    captured = _collect_ts(
        sess,
        [
            _full_voice(sess, 65500, payload),
            _mini(sess, 65520, payload),
            _mini(sess, 4, payload),   # 65540 modulo 65536
        ],
    )

    assert captured == [65500, 65520, 65540], (
        "mini timestamps must unwrap monotonically across the 16-bit "
        f"rollover, got {captured}"
    )


def test_start_call_resets_rx_timestamp_anchor() -> None:
    """The unwrap anchor is per-call: carrying it over lets the first frame
    of the next call land at an arbitrary offset from its siblings."""

    class _ImmediateEvent:
        def clear(self) -> None:
            pass

        def set(self) -> None:
            pass

        async def wait(self) -> bool:
            return True

    sess, _transport = _session()
    sess._state = sess.STATE_REGISTERED
    sess._rx_ts32 = 424242  # left over from the previous call
    sess._response_event = _ImmediateEvent()  # type: ignore[assignment]
    sess._send_new = lambda *a, **kw: None  # type: ignore[method-assign]

    assert asyncio.run(sess.start_call("*0", "12345")) is True
    assert sess._rx_ts32 is None, (
        "start_call must clear the RX timestamp anchor so the new call's "
        "frames are unwrapped from their own full VOICE frame"
    )
