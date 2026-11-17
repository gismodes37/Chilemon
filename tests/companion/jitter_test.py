"""
Tests for companion/jitter_buffer.py — RX playout ordering.

The live *0 echo showed Asterisk's RX timestamps stepping 20 ms and 40 ms
interleaved (it stamps from its own receive clock, which mirrors our bursty
Windows audio callback), never a clean +20 ms. A walk that requires an exact
+20 ms hit therefore misses roughly a third of the frames, the buffer fills
to its maximum and _trim_buffer discards the rest.

Run with: python -m pytest tests/companion/jitter_test.py -v
"""

from __future__ import annotations

from companion.jitter_buffer import AudioFrame, JitterBuffer

# Measured live from the *0 echo call (companion_stdout.log):
#   RX mini ts: 100, 140, 160, 200, 220, 260, 280, ...
#   deltas:     40, 20, 40, 20, 40, 20
IRREGULAR_TS = (100, 140, 160, 200, 220, 260, 280)


def _frame(ts: int) -> AudioFrame:
    """One 20 ms ulaw frame whose payload is tagged with its own timestamp."""
    return AudioFrame(
        timestamp_ms=ts,
        payload=bytes([ts % 256]) * 160,
        received_at=0.0,
        sequence=ts,
    )


def test_pop_returns_every_frame_for_irregular_timestamps() -> None:
    """Playout must consume each buffered frame in timestamp order.

    Requiring an exact ``last + 20 ms`` match starves the playout: only the
    frames that happen to land on the grid are played and the rest are
    trimmed, which is what produced frames_played=208 against
    frames_received=903 on a live call.
    """
    jb = JitterBuffer()
    for ts in IRREGULAR_TS:
        jb.push(_frame(ts))

    played = [jb.pop() for _ in range(len(IRREGULAR_TS))]

    expected = [_frame(ts).payload for ts in IRREGULAR_TS]
    assert played == expected, (
        "every buffered frame must play once, in timestamp order; "
        f"played {len([p for p in played if p in expected])} of "
        f"{len(expected)} real frames"
    )

    stats = jb.get_stats()
    assert stats["frames_plc"] == 0, (
        f"no concealment should be needed when every frame is buffered, "
        f"got {stats['frames_plc']} PLC frames"
    )


def test_pop_orders_out_of_order_arrivals() -> None:
    """Timestamp order, not arrival order, decides what plays next."""
    jb = JitterBuffer()
    for ts in (160, 100, 140):
        jb.push(_frame(ts))

    played = [jb.pop() for _ in range(3)]

    assert played == [_frame(ts).payload for ts in (100, 140, 160)], (
        "frames must play in timestamp order regardless of arrival order"
    )


def test_push_drops_duplicate_timestamps() -> None:
    """A retransmitted frame shares its timestamp and must not play twice."""
    jb = JitterBuffer()
    jb.push(_frame(100))
    jb.push(_frame(100))

    assert jb.pop() == _frame(100).payload
    stats = jb.get_stats()
    assert stats["frames_dropped"] == 1, (
        f"the duplicate must be counted as dropped, got {stats['frames_dropped']}"
    )


def test_pop_conceals_when_buffer_underruns() -> None:
    """With nothing buffered the playout still advances, using PLC."""
    jb = JitterBuffer()
    jb.push(_frame(100))

    assert jb.pop() == _frame(100).payload

    concealed = jb.pop()
    assert concealed is not None, "an underrun must return concealment, not None"
    assert concealed == _frame(100).payload, "PLC repeats the last good frame"

    stats = jb.get_stats()
    assert stats["frames_plc"] >= 1, "the underrun must be counted as PLC"
