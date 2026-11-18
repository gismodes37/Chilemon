<?php
declare(strict_types=1);

namespace Tests\Services;

use App\Services\UdpRxProbe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UdpRxProbe::class)]
final class UdpRxProbeTest extends TestCase
{
    private string $dir;
    private string $snmpPath;
    private string $statePath;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'udp_rx_probe_' . uniqid('', true);
        mkdir($this->dir);
        $this->snmpPath  = $this->dir . DIRECTORY_SEPARATOR . 'snmp';
        $this->statePath = $this->dir . DIRECTORY_SEPARATOR . 'state.json';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    /**
     * Write a minimal /proc/net/snmp snapshot with the given InDatagrams value.
     */
    private function writeSnmp(int $inDatagrams): void
    {
        $header = "Ip: Forwarding DefaultTTL IpWithTTL McastRoutes DefaultTTL\n"
                . "Udp: InDatagrams NoPorts InErrors OutDatagrams RcvbufErrors InCsumErrors\n";
        $values = "Ip: 1 64 0 1 1 0\n"
                . sprintf("Udp: %d 0 0 22870 0 0\n", $inDatagrams);
        file_put_contents($this->snmpPath, $header . $values);
    }

    private function probe(float $threshold = UdpRxProbe::DEFAULT_THRESHOLD): UdpRxProbe
    {
        return new UdpRxProbe($this->snmpPath, $this->statePath, $threshold);
    }

    // --- parseInDatagrams -------------------------------------------------

    #[Test]
    public function parseInDatagramsReadsSecondUdpLine(): void
    {
        $raw = "Udp: InDatagrams NoPorts InErrors OutDatagrams RcvbufErrors InCsumErrors\n"
             . "Udp: 35067 0 0 22870 0 0\n";

        $this->assertSame(35067, UdpRxProbe::parseInDatagrams($raw));
    }

    #[Test]
    public function parseInDatagramsReturnsNullOnGarbage(): void
    {
        $this->assertNull(UdpRxProbe::parseInDatagrams('not a proc file at all'));
        $this->assertNull(UdpRxProbe::parseInDatagrams(''));
    }

    #[Test]
    public function parseInDatagramsReturnsNullWhenValuesLineMissing(): void
    {
        // Only the header line is present: the values line is what carries numbers.
        $raw = "Udp: InDatagrams NoPorts InErrors OutDatagrams\n";

        $this->assertNull(UdpRxProbe::parseInDatagrams($raw));
    }

    #[Test]
    public function parseInDatagramsIgnoresUdpLiteSection(): void
    {
        $raw = "UdpLite: InDatagrams NoPorts InErrors OutDatagrams\n"
             . "UdpLite: 99999 0 0 99999\n"
             . "Udp: InDatagrams NoPorts InErrors OutDatagrams\n"
             . "Udp: 42 0 0 7\n";

        $this->assertSame(42, UdpRxProbe::parseInDatagrams($raw));
    }

    // --- rate -------------------------------------------------------------

    #[Test]
    public function rateComputesPacketsPerSecond(): void
    {
        // 300 packets over 4 s = 75 pkt/s (voice-like burst)
        $this->assertSame(
            75.0,
            UdpRxProbe::rate(1000, 1000.0, 1300, 1004.0)
        );
    }

    #[Test]
    public function rateReturnsNullOnCounterReset(): void
    {
        // Counter went backwards (kernel reboot) — rate is undefined.
        $this->assertNull(UdpRxProbe::rate(3000, 1000.0, 100, 1004.0));
    }

    #[Test]
    public function rateReturnsNullWhenTimeDoesNotAdvance(): void
    {
        $this->assertNull(UdpRxProbe::rate(1000, 1004.0, 1300, 1004.0));
        $this->assertNull(UdpRxProbe::rate(1000, 0.0, 1300, 1004.0));
        $this->assertNull(UdpRxProbe::rate(null, 1000.0, 1300, 1004.0));
    }

    // --- isRxActive: file / state handling --------------------------------

    #[Test]
    public function isRxActiveFalseWhenSnmpFileMissing(): void
    {
        $this->assertFalse($this->probe()->isRxActive(1000.0));
        $this->assertFileDoesNotExist($this->statePath);
    }

    #[Test]
    public function isRxActiveInitializesStateAndReturnsFalse(): void
    {
        $this->writeSnmp(1000);

        $this->assertFalse($this->probe()->isRxActive(1000.0));
        $this->assertFileExists($this->statePath);
    }

    #[Test]
    public function isRxActiveRecoversFromCorruptStateFile(): void
    {
        $this->writeSnmp(1000);
        file_put_contents($this->statePath, '{not json');

        $this->assertFalse($this->probe()->isRxActive(1000.0));

        // State must have been rewritten as valid JSON.
        $state = json_decode((string) file_get_contents($this->statePath), true);
        $this->assertIsArray($state);
    }

    // --- isRxActive: threshold decisions ----------------------------------

    #[Test]
    public function isRxActiveDetectsVoiceBurstRate(): void
    {
        $this->writeSnmp(1000);
        $probe = $this->probe();
        $this->assertFalse($probe->isRxActive(1000.0));

        // 600 packets in 4 s = 150 pkt/s (measured voice: 72-82 pkt/s)
        $this->writeSnmp(1600);
        $this->assertTrue($probe->isRxActive(1004.0));
    }

    #[Test]
    public function isRxActiveIgnoresKeepaliveRate(): void
    {
        $this->writeSnmp(1000);
        $probe = $this->probe();
        $this->assertFalse($probe->isRxActive(1000.0));

        // 4 packets in 4 s = 1 pkt/s (measured idle/keepalive: ~1 pkt/s)
        $this->writeSnmp(1004);
        $this->assertFalse($probe->isRxActive(1004.0));
    }

    #[Test]
    public function isRxActiveRespectsCustomThreshold(): void
    {
        $this->writeSnmp(1000);
        $probe = $this->probe(200.0); // stricter than voice
        $this->assertFalse($probe->isRxActive(1000.0));

        $this->writeSnmp(1600); // 150 pkt/s < 200 pkt/s
        $this->assertFalse($probe->isRxActive(1004.0));
    }

    #[Test]
    public function isRxActiveReturnsStoredRxWhenWindowTooShort(): void
    {
        $this->writeSnmp(1000);
        $probe = $this->probe();
        $probe->isRxActive(1000.0);

        $this->writeSnmp(1600);
        $this->assertTrue($probe->isRxActive(1004.0)); // rx established

        // 0.5 s later the window is below the minimum: reuse the stored verdict
        // instead of dividing by an unreliable interval.
        $this->writeSnmp(1601);
        $this->assertTrue($probe->isRxActive(1004.5));
    }

    #[Test]
    public function isRxActiveIgnoresBurstInsideMinimumWindow(): void
    {
        $this->writeSnmp(1000);
        $probe = $this->probe();
        $probe->isRxActive(1000.0); // stored rx = false

        // A burst lands inside a 0.4 s window: too short to trust, keep false.
        $this->writeSnmp(1600);
        $this->assertFalse($probe->isRxActive(1000.4));
    }

    #[Test]
    public function isRxActiveTreatsCounterResetAsNoRx(): void
    {
        $this->writeSnmp(5000);
        $probe = $this->probe();
        $this->assertFalse($probe->isRxActive(1000.0));

        // Counter dropped to 10 (kernel restart): rate undefined -> false,
        // and the state must be re-anchored to the new counter value.
        $this->writeSnmp(10);
        $this->assertFalse($probe->isRxActive(1004.0));

        // Subsequent burst from the new baseline must be detected again.
        $this->writeSnmp(610);
        $this->assertTrue($probe->isRxActive(1008.0));
    }
}
