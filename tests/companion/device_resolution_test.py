"""Tests for audio device resolution — OS default first, explicit pin optional."""

from __future__ import annotations

from types import SimpleNamespace

import pytest

from companion import audio
from companion.audio import AudioEngine


def _dev(name: str, inp: int = 0, out: int = 0, rate: int = 44100, api: int = 0) -> dict:
    return {
        "name": name,
        "max_input_channels": inp,
        "max_output_channels": out,
        "default_samplerate": rate,
        "hostapi": api,
    }


class _IndexablePair:
    """Mimics sounddevice._InputOutputPair: indexable but not tuple/list."""

    def __init__(self, first: int, second: int) -> None:
        self._values = (first, second)

    def __getitem__(self, index: int) -> int:
        return self._values[index]


class FakeSoundDevice:
    def __init__(self, devices: list[dict], default: tuple[int, int]) -> None:
        self._devices = devices
        self.default = SimpleNamespace(device=_IndexablePair(*default))

    def query_devices(self, index=None):
        if index is None:
            return self._devices
        return self._devices[index]

    def query_hostapis(self):
        return [{"name": "MME"}, {"name": "Windows WDM-KS"}]


@pytest.fixture
def fake_sd(monkeypatch):
    devices = [
        _dev("Microsoft Sound Mapper - Input", inp=2),
        _dev("Headset Mic", inp=1),
        _dev("Microsoft Sound Mapper - Output", out=2),
        _dev("Headset Speakers", out=2),
        _dev("Headset Hands-Free", inp=1, out=1, rate=16000, api=1),
    ]
    fake = FakeSoundDevice(devices, default=(1, 3))
    monkeypatch.setattr(audio, "sd", fake)
    return fake


def test_empty_name_uses_os_default_not_index_zero(fake_sd) -> None:
    """Fresh installs follow the OS default, never the Microsoft mapper at id 0."""
    assert AudioEngine._resolve_device("", is_input=True) == 1
    assert AudioEngine._resolve_device("", is_input=False) == 3


def test_missing_name_falls_back_to_os_default(fake_sd) -> None:
    """A wrong configured name must not break audio."""
    assert AudioEngine._resolve_device("No Such Mic", is_input=True) == 1
    assert AudioEngine._resolve_device("No Such Speaker", is_input=False) == 3


def test_explicit_numeric_index_is_honored(fake_sd) -> None:
    assert AudioEngine._resolve_device("2", is_input=False) == 2
    assert AudioEngine._resolve_device("3", is_input=False) == 3


def test_invalid_numeric_index_falls_back_to_default(fake_sd) -> None:
    assert AudioEngine._resolve_device("99", is_input=True) == 1
    assert AudioEngine._resolve_device("2", is_input=True) == 1  # output-only device


def test_name_substring_prefers_stereo_over_hands_free(fake_sd) -> None:
    """'Headset' matches mic, speaker and hands-free; pick the right direction."""
    assert AudioEngine._resolve_device("Headset", is_input=True) == 1
    assert AudioEngine._resolve_device("Headset", is_input=False) == 3


def test_scalar_default_is_accepted(monkeypatch) -> None:
    """Some platforms report one default ID instead of an input/output pair."""
    fake = FakeSoundDevice(
        [_dev("D0", inp=1, out=1), _dev("D1", inp=1, out=1)], default=(0, 0)
    )
    fake.default = SimpleNamespace(device=1)
    monkeypatch.setattr(audio, "sd", fake)
    assert AudioEngine._resolve_device("", is_input=True) == 1
    assert AudioEngine._resolve_device("", is_input=False) == 1


def test_no_sounddevice_returns_zero(monkeypatch) -> None:
    monkeypatch.setattr(audio, "sd", None)
    assert AudioEngine._resolve_device("", is_input=True) == 0
