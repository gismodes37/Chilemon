"""Behavioral regression for S9: last-WS disconnect must unkey before grace.

Source-pattern tests can pass on a baseline if a regex spans unrelated code.
This probe extracts the real ``finally`` blocks with the AST and executes them
against fakes, so the ordering and the DTMF payload are observed, not guessed.

Run with: python -m pytest tests/companion/ptt_disconnect_order_test.py -q
"""

from __future__ import annotations

import ast
import asyncio
import textwrap
from pathlib import Path
from unittest.mock import AsyncMock, MagicMock

ROOT = Path(__file__).resolve().parents[2]
BRIDGE = ROOT / "app" / "Services" / "WebRTCBridge" / "server.py"
WS = ROOT / "companion" / "ws_server.py"


def _finally_block(path: Path, cls_name: str, fn_name: str):
    source = path.read_text(encoding="utf-8")
    cls = next(
        n
        for n in ast.parse(source).body
        if isinstance(n, ast.ClassDef) and n.name == cls_name
    )
    fn = next(
        n
        for n in cls.body
        if isinstance(n, ast.AsyncFunctionDef) and n.name == fn_name
    )
    tries = [n for n in ast.walk(fn) if isinstance(n, ast.Try) and n.finalbody]
    return (
        textwrap.dedent(
            "\n".join(ast.get_source_segment(source, n) for n in tries[-1].finalbody)
        ),
        source,
        cls,
    )


def test_bridge_last_ws_disconnect_unkeys_before_grace() -> None:
    fin, source, cls = _finally_block(BRIDGE, "WebRTCBridgeApp", "handle_ws")
    unkey = next(
        n
        for n in cls.body
        if isinstance(n, ast.AsyncFunctionDef) and n.name == "_ptt_unkey"
    )
    ns = {"asyncio": asyncio, "logger": MagicMock()}
    exec(textwrap.dedent(ast.get_source_segment(source, unkey)), ns)
    exec("async def _seq(self, ws):\n" + textwrap.indent(fin, "    "), ns)

    class Bridge:
        _ptt_unkey = ns["_ptt_unkey"]

        def __init__(self) -> None:
            self._ws_peers = set()
            self._ws_grace_period = 30.0
            self._ptt_active = True
            self._active_call = MagicMock()
            self._active_call.callno = 1
            self._cancel_reoriginate = MagicMock()
            self._start_grace_period = MagicMock()
            self._broadcast_status = AsyncMock()

    bridge = Bridge()
    asyncio.run(ns["_seq"](bridge, MagicMock()))

    bridge._active_call.send_dtmf.assert_called_once_with("#")
    assert bridge._ptt_active is False
    bridge._start_grace_period.assert_called_once()


def test_companion_last_peer_disconnect_forces_unkey() -> None:
    fin, _, _ = _finally_block(WS, "WSServer", "_handle_ws")
    ns = {"logger": MagicMock(), "peer": "probe"}
    exec("async def _seq(self, ws):\n" + textwrap.indent(fin, "    "), ns)

    ws = MagicMock()

    class Companion:
        def __init__(self) -> None:
            self._peers = {ws}
            self.on_ptt_unkey = AsyncMock()

    companion = Companion()
    asyncio.run(ns["_seq"](companion, ws))

    companion.on_ptt_unkey.assert_awaited_once()
