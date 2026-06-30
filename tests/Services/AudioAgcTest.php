<?php
declare(strict_types=1);

namespace Tests\Services;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for RMS AGC implementation in audio.py.
 *
 * Tests the Python-based AGC logic by invoking the module via subprocess
 * with known test ulaw frames and verifying the output RMS levels.
 *
 * These are integration-level tests: they verify that:
 *   1. AGC boosts quiet frames (below -18 dBFS) toward target
 *   2. Silence gate discards frames below -40 dBFS
 *   3. RX_VOLUME=3.0 gain is applied as minimum floor
 *   4. The server sends rate=8000 for native 8 kHz playback
 */
#[CoversNothing]
final class AudioAgcTest extends TestCase
{
    private const AUDIO_PY = ROOT_PATH . '/app/Services/WebRTCBridge/audio.py';
    private const SERVER_PY = ROOT_PATH . '/app/Services/WebRTCBridge/server.py';

    // ------------------------------------------------------------------
    //  Python subprocess helpers
    // ------------------------------------------------------------------

    /**
     * Run a Python expression that imports audio.py constants and prints results.
     *
     * @param string $expression Python expression to evaluate.
     * @return string stdout from Python.
     */
    private static function runPythonExpression(string $expression): string
    {
        $script = sprintf(
            'import sys; sys.path.insert(0, %s); %s',
            var_export(ROOT_PATH, true),
            $expression
        );
        $cmd = sprintf('python3 -c %s 2>&1', escapeshellarg($script));
        $output = shell_exec($cmd);
        if ($output === null) {
            throw new \RuntimeException('Failed to execute Python: ' . $cmd);
        }
        return trim($output);
    }

    /**
     * Run a Python test script against audio.py and return output.
     *
     * @param string $ulawHex Hex-encoded ulaw payload (20ms frame at 8kHz = 160 bytes).
     * @param string $pyCode  Python code snippet that processes $ulawHex and prints result.
     * @return string stdout from Python.
     */
    private static function runAudioPipeline(string $ulawHex, string $pyCode): string
    {
        $script = sprintf(
            'import sys; sys.path.insert(0, %s); '
            . 'from app.Services.WebRTCBridge.audio import rx_process, ulaw_to_pcm, pcm_to_ulaw, rms_agc, '
            . 'RX_VOLUME, AGC_TARGET_DBFS, AGC_BOOST_THRESHOLD_DBFS, AGC_GATE_DBFS, AGC_MAX_BOOST; '
            . 'import math; import struct; '
            . 'test_ulaw = bytes.fromhex(%s); '
            . '%s',
            var_export(ROOT_PATH, true),
            var_export($ulawHex, true),
            $pyCode
        );
        $cmd = sprintf('python3 -c %s 2>&1', escapeshellarg($script));
        $output = shell_exec($cmd);
        if ($output === null) {
            throw new \RuntimeException('Failed to execute Python: ' . $cmd);
        }
        return trim($output);
    }

    /**
     * Compute RMS in dBFS from float32 PCM data (hex-encoded).
     * Calls Python directly (not via runAudioPipeline).
     *
     * @param string $float32Hex Hex-encoded float32 PCM data.
     * @return float RMS level in dBFS.
     */
    private static function computeFloat32RmsDb(string $float32Hex): float
    {
        $script = sprintf(
            'import math; import struct; '
            . 'f32 = bytes.fromhex(%s); '
            . 'samples = len(f32) // 4; '
            . 'fmt = "<" + str(samples) + "f"; '
            . 'floats = struct.unpack(fmt, f32); '
            . 'sum_sq = sum(s*s for s in floats); '
            . 'rms = math.sqrt(sum_sq / samples); '
            . 'if rms > 0: db = 20.0 * math.log10(rms / 1.0); '
            . 'else: db = -100.0; '
            . 'print("{:.2f}".format(db))',
            var_export($float32Hex, true)
        );
        $cmd = sprintf('python3 -c %s 2>&1', escapeshellarg($script));
        $output = shell_exec($cmd);
        if ($output === null) {
            throw new \RuntimeException('Failed to execute Python: ' . $cmd);
        }
        return (float) trim($output);
    }

    /**
     * Get the max absolute sample value from float32 PCM data (hex-encoded).
     *
     * @param string $float32Hex Hex-encoded float32 PCM data.
     * @return float Maximum absolute sample value.
     */
    private static function computeFloat32MaxAbs(string $float32Hex): float
    {
        $script = sprintf(
            'import math; import struct; '
            . 'f32 = bytes.fromhex(%s); '
            . 'samples = len(f32) // 4; '
            . 'fmt = "<" + str(samples) + "f"; '
            . 'floats = struct.unpack(fmt, f32); '
            . 'max_abs = max(abs(s) for s in floats); '
            . 'print("{:.10f}".format(max_abs))',
            var_export($float32Hex, true)
        );
        $cmd = sprintf('python3 -c %s 2>&1', escapeshellarg($script));
        $output = shell_exec($cmd);
        if ($output === null) {
            throw new \RuntimeException('Failed to execute Python: ' . $cmd);
        }
        return (float) trim($output);
    }

    // ------------------------------------------------------------------
    //  AGC Test Data Generators
    // ------------------------------------------------------------------

    /**
     * Generate a ulaw frame at a specific RMS dBFS level.
     * Uses pcm_to_ulaw to encode a sine tone at the desired amplitude.
     *
     * @param float $targetDbFS Desired RMS level in dBFS.
     * @return string Hex-encoded ulaw payload (160 bytes @ 8kHz, 20ms frame).
     */
    private static function generateUlawFrame(float $targetDbFS): string
    {
        $result = self::runPythonExpression(sprintf(
            'from app.Services.WebRTCBridge.audio import pcm_to_ulaw; '
            . 'import math; import struct; '
            . 'amplitude = 32768.0 * (10.0 ** (%f / 20.0)); '
            . 'samples = 160; '
            . 'data = [int(amplitude * math.sin(2 * math.pi * 440 * i / 8000)) for i in range(samples)]; '
            . 'pcm = struct.pack("<" + str(samples) + "h", *data); '
            . 'ulaw = pcm_to_ulaw(pcm); '
            . 'print(ulaw.hex())',
            $targetDbFS
        ));
        return $result;
    }

    // ------------------------------------------------------------------
    //  Tests
    // ------------------------------------------------------------------

    #[Test]
    public function rxVolumeIsSetTo3Point0(): void
    {
        $value = self::runPythonExpression(
            'from app.Services.WebRTCBridge.audio import RX_VOLUME; print(RX_VOLUME)'
        );
        $this->assertSame('3.0', $value, 'RX_VOLUME should be 3.0 (was ' . $value . ')');
    }

    #[Test]
    public function agcConstantsAreDefined(): void
    {
        $result = self::runPythonExpression(
            'from app.Services.WebRTCBridge.audio import AGC_TARGET_DBFS, AGC_BOOST_THRESHOLD_DBFS, AGC_GATE_DBFS, AGC_MAX_BOOST; '
            . 'print(AGC_TARGET_DBFS, AGC_BOOST_THRESHOLD_DBFS, AGC_GATE_DBFS, AGC_MAX_BOOST)'
        );
        $parts = explode(' ', $result);
        $this->assertCount(4, $parts);
        $this->assertSame('-12.0', $parts[0], 'AGC_TARGET_DBFS');
        $this->assertSame('-18.0', $parts[1], 'AGC_BOOST_THRESHOLD_DBFS');
        $this->assertSame('-40.0', $parts[2], 'AGC_GATE_DBFS');
        $this->assertSame('10.0', $parts[3], 'AGC_MAX_BOOST');
    }

    #[Test]
    public function quietFrameAt30DbFsGetsBoosted(): void
    {
        // Generate a ulaw frame at -30 dBFS RMS
        $ulawHex = self::generateUlawFrame(-30.0);
        $this->assertNotEmpty($ulawHex);

        // Run through rx_process() which applies AGC + gain
        $result = self::runAudioPipeline($ulawHex,
            'result = rx_process(test_ulaw); print(result.hex())'
        );
        $this->assertNotEmpty($result);

        // Output RMS should be well above -18 dBFS (AGC boosted it)
        $outputRmsDb = self::computeFloat32RmsDb($result);
        $this->assertGreaterThan(
            -18.0,
            $outputRmsDb,
            'AGC should boost -30 dBFS frame above -18 dBFS (got ' . $outputRmsDb . ' dBFS)'
        );
    }

    #[Test]
    public function veryQuietFrameAt50DbFsGetsGated(): void
    {
        // Generate a ulaw frame at -50 dBFS (well below -40 dBFS gate)
        // At -50 dBFS the amplitude is extremely low — near silence
        $ulawHex = self::generateUlawFrame(-50.0);

        // Run through rx_process()
        $resultHex = self::runAudioPipeline($ulawHex,
            'result = rx_process(test_ulaw); print(result.hex())'
        );

        // Output should be silence (all zeros) because -50 dBFS is below gate
        $maxSample = self::computeFloat32MaxAbs($resultHex);
        $this->assertSame(
            0.0,
            $maxSample,
            'Frame at -50 dBFS should be gated to silence (max sample = ' . $maxSample . ')'
        );
    }

    #[Test]
    public function serverSendsRate8000(): void
    {
        // Verify server.py sends rate:8000 in the audio WS message
        $content = file_get_contents(self::SERVER_PY);
        $this->assertNotFalse($content);

        // Check that "rate": 8000 appears in the audio message construction
        $this->assertStringContainsString(
            '"rate": 8000',
            $content,
            'server.py must send rate=8000 in audio WS messages'
        );
    }

    #[Test]
    public function gainFloorAppliesAfterAgc(): void
    {
        // A frame at exactly -18 dBFS should pass through without AGC boost
        // (it's at the threshold) but still have RX_VOLUME=3.0 gain applied
        $ulawHex = self::generateUlawFrame(-18.0);

        $resultHex = self::runAudioPipeline($ulawHex,
            'result = rx_process(test_ulaw); print(result.hex())'
        );

        // Output RMS should be amplified by 3.0x on float32
        // Input at -18 dBFS → float32 RMS ≈ 0.126 → after 3x = 0.378 → ≈ -8.4 dBFS
        $outputRmsDb = self::computeFloat32RmsDb($resultHex);
        $this->assertGreaterThan(
            -12.0,
            $outputRmsDb,
            'AGC + 3.0x gain should push -18 dBFS frame well above -12 dBFS (got ' . $outputRmsDb . ' dBFS)'
        );
    }

    #[Test]
    public function agcBoostsFrameAt25DbFsTowardTarget(): void
    {
        // Frame at -25 dBFS should get AGC boost toward -12 dBFS target
        $ulawHex = self::generateUlawFrame(-25.0);

        $resultHex = self::runAudioPipeline($ulawHex,
            'result = rx_process(test_ulaw); print(result.hex())'
        );

        $outputRmsDb = self::computeFloat32RmsDb($resultHex);

        // After AGC + 3.0x gain, should be above -12 dBFS
        $this->assertGreaterThan(
            -12.0,
            $outputRmsDb,
            'AGC should boost -25 dBFS frame well above -12 dBFS (got ' . $outputRmsDb . ' dBFS)'
        );

        // Also verify it didn't clip to death (max sample should be reasonable)
        $maxAbs = self::computeFloat32MaxAbs($resultHex);
        $this->assertLessThanOrEqual(
            1.0,
            $maxAbs,
            'Output should not exceed [-1.0, 1.0] range (got ' . $maxAbs . ')'
        );
    }
}
