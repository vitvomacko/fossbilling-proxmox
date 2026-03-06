<?php

/**
 * Unit tests for ProxmoxIPAM trait (allocate_ip)
 *
 * @license GNU General Public License version 3 (GPLv3)
 */

namespace Box\Mod\Serviceproxmox;

use PHPUnit\Framework\TestCase;

/**
 * Minimal stub that uses only the ProxmoxIPAM trait so we can test
 * its logic in isolation without FOSSBilling's full framework.
 */
class IPAMTestStub
{
    use ProxmoxIPAM;

    public $di;
}

class ProxmoxIPAMTest extends TestCase
{
    private function makeIpBean(string $ip, int $rangeId, bool $gateway = false, bool $dedicated = false): object
    {
        $bean            = new \stdClass();
        $bean->ip        = $ip;
        $bean->ip_range_id = $rangeId;
        $bean->gateway   = $gateway ? 1 : 0;
        $bean->dedicated = $dedicated ? 1 : 0;
        return $bean;
    }

    private function makeRangeBean(string $gateway): object
    {
        $bean          = new \stdClass();
        $bean->gateway = $gateway;
        return $bean;
    }

    // -------------------------------------------------------------------------

    /**
     * When the pool is empty, allocate_ip() must return null.
     */
    public function testAllocateIpReturnsNullWhenPoolIsEmpty(): void
    {
        $stub     = new IPAMTestStub();
        $stub->di = $this->buildDi($this->buildDbMock([], []));

        $this->assertNull($stub->allocate_ip());
    }

    /**
     * Gateway IPs must be skipped; the first non-gateway IP is returned.
     */
    public function testAllocateIpSkipsGatewayEntries(): void
    {
        $gateway = $this->makeIpBean('10.0.0.1', 1, true);
        $usable  = $this->makeIpBean('10.0.0.2', 1, false);
        $range   = $this->makeRangeBean('10.0.0.1');

        $stub     = new IPAMTestStub();
        $stub->di = $this->buildDi($this->buildDbMock([], [$gateway, $usable], $range));

        $result = $stub->allocate_ip();

        $this->assertNotNull($result);
        $this->assertSame('10.0.0.2', $result['ip']);
        $this->assertSame('10.0.0.1', $result['gateway']);
    }

    /**
     * IPs already assigned to active services must be skipped.
     */
    public function testAllocateIpSkipsAlreadyAssignedIPs(): void
    {
        $ip1  = $this->makeIpBean('10.0.0.10', 1);
        $ip2  = $this->makeIpBean('10.0.0.11', 1);
        $range = $this->makeRangeBean('10.0.0.1');

        $stub     = new IPAMTestStub();
        $stub->di = $this->buildDi($this->buildDbMock(['10.0.0.10'], [$ip1, $ip2], $range));

        $result = $stub->allocate_ip();

        $this->assertNotNull($result);
        $this->assertSame('10.0.0.11', $result['ip']);
    }

    /**
     * When all IPs are assigned, return null.
     */
    public function testAllocateIpReturnsNullWhenAllAssigned(): void
    {
        $ip1 = $this->makeIpBean('10.0.0.10', 1);
        $ip2 = $this->makeIpBean('10.0.0.11', 1);

        $stub     = new IPAMTestStub();
        $stub->di = $this->buildDi($this->buildDbMock(['10.0.0.10', '10.0.0.11'], [$ip1, $ip2]));

        $this->assertNull($stub->allocate_ip());
    }

    /**
     * allocate_ip() returns the first available IP (predictable order).
     */
    public function testAllocateIpReturnsFirstAvailable(): void
    {
        $ip1  = $this->makeIpBean('10.0.0.10', 1);
        $ip2  = $this->makeIpBean('10.0.0.11', 1);
        $range = $this->makeRangeBean('10.0.0.1');

        $stub     = new IPAMTestStub();
        $stub->di = $this->buildDi($this->buildDbMock([], [$ip1, $ip2], $range));

        $result = $stub->allocate_ip();

        $this->assertSame('10.0.0.10', $result['ip']);
        $this->assertSame('10.0.0.1',  $result['gateway']);
    }

    // -------------------------------------------------------------------------
    // Helpers

    private function buildDi(object $db): \FakeDI
    {
        $di     = new \FakeDI();
        $di->db = $db;
        return $di;
    }

    private function buildDbMock(array $assigned, array $candidates, ?object $range = null): object
    {
        $db = new class($assigned, $candidates, $range) {
            public function __construct(
                private array $assigned,
                private array $candidates,
                private ?object $range,
            ) {}

            public function getAll(string $sql): array
            {
                return array_map(fn($ip) => ['ipv4' => $ip], $this->assigned);
            }

            public function find(string $table, string $where = ''): array
            {
                return $this->candidates;
            }

            public function load(string $table, mixed $id): ?object
            {
                return $this->range;
            }
        };

        return $db;
    }
}
