<?php

/**
 * Unit tests for ProxmoxServer trait (find_empty, find_access)
 *
 * @license GNU General Public License version 3 (GPLv3)
 */

namespace Box\Mod\Serviceproxmox;

use PHPUnit\Framework\TestCase;

/**
 * Minimal stub that uses only the ProxmoxServer trait.
 */
class ServerTestStub
{
    use ProxmoxServer;

    public $di;

    // find_empty calls find_access indirectly; stub it out to avoid side effects.
    public function getProxmoxInstance($server): never
    {
        throw new \LogicException('getProxmoxInstance should not be called in unit tests');
    }
}

class ProxmoxServerTest extends TestCase
{
    private function makeServer(int $id, int $cpuCores, int $cpuAllocated, int $ram, int $ramAllocated, bool $active = true): object
    {
        $s                    = new \stdClass();
        $s->id                = $id;
        $s->cpu_cores         = $cpuCores;
        $s->cpu_cores_allocated = $cpuAllocated;
        $s->ram               = $ram;
        $s->ram_allocated     = $ramAllocated;
        $s->active            = $active ? 1 : 0;
        return $s;
    }

    private function makeProduct(string $filling = 'fill', string $group = 'default'): object
    {
        $p         = new \stdClass();
        $p->config = json_encode(['group' => $group, 'filling' => $filling]);
        return $p;
    }

    private function makeStub(array $servers): ServerTestStub
    {
        $config = [
            'cpu_overprovisioning' => 0,
            'ram_overprovisioning' => 0,
            'avoid_overprovision'  => false,
        ];

        $db = new class($servers) {
            public function __construct(private array $servers) {}
            public function find(string $table, string $cond = ''): array { return $this->servers; }
        };

        $modConfig = new class($config) {
            public function __construct(private array $cfg) {}
            public function __invoke(string $mod): array { return $this->cfg; }
        };

        $stub        = new ServerTestStub();
        $stub->di    = new \FakeDI();
        $stub->di->db         = $db;
        $stub->di->mod_config = $modConfig;
        return $stub;
    }

    // -------------------------------------------------------------------------

    /**
     * No servers → must return null.
     */
    public function testFindEmptyReturnsNullWhenNoServers(): void
    {
        $stub = $this->makeStub([]);
        $this->assertNull($stub->find_empty($this->makeProduct()));
    }

    /**
     * A single completely empty server must still be returned (fill strategy).
     * This was the original bug: ratio=0 was never > $server_ratio=0, so null was returned.
     */
    public function testFindEmptyReturnsSingleEmptyServer(): void
    {
        $server = $this->makeServer(id: 1, cpuCores: 4, cpuAllocated: 0, ram: 8192, ramAllocated: 0);
        $stub   = $this->makeStub([$server]);

        $this->assertSame(1, $stub->find_empty($this->makeProduct('fill')));
    }

    /**
     * Fill strategy: should return the more-loaded server (consolidation).
     */
    public function testFindEmptyFillStrategyPicksMostLoaded(): void
    {
        $loaded = $this->makeServer(id: 1, cpuCores: 4, cpuAllocated: 3, ram: 8192, ramAllocated: 6000);
        $empty  = $this->makeServer(id: 2, cpuCores: 4, cpuAllocated: 0, ram: 8192, ramAllocated: 0);

        $stub = $this->makeStub([$loaded, $empty]);

        $this->assertSame(1, $stub->find_empty($this->makeProduct('fill')));
    }

    /**
     * Spread strategy: should return the least-loaded server.
     */
    public function testFindEmptySpreadStrategyPicksLeastLoaded(): void
    {
        $loaded = $this->makeServer(id: 1, cpuCores: 4, cpuAllocated: 3, ram: 8192, ramAllocated: 6000);
        $empty  = $this->makeServer(id: 2, cpuCores: 4, cpuAllocated: 0, ram: 8192, ramAllocated: 0);

        $stub = $this->makeStub([$loaded, $empty]);

        $this->assertSame(2, $stub->find_empty($this->makeProduct('spread')));
    }

    /**
     * Servers with zero capacity (misconfigured) must be skipped to avoid division by zero.
     */
    public function testFindEmptySkipsServerWithZeroCapacity(): void
    {
        $broken = $this->makeServer(id: 1, cpuCores: 0, cpuAllocated: 0, ram: 0, ramAllocated: 0);
        $valid  = $this->makeServer(id: 2, cpuCores: 4, cpuAllocated: 1, ram: 8192, ramAllocated: 1024);

        $stub = $this->makeStub([$broken, $valid]);

        $this->assertSame(2, $stub->find_empty($this->makeProduct('fill')));
    }

    /**
     * avoid_overprovision=true: skip servers with ratio > 1.
     */
    public function testFindEmptySkipsOverprovisionedServerWhenFlagSet(): void
    {
        $overloaded = $this->makeServer(id: 1, cpuCores: 4, cpuAllocated: 5, ram: 8192, ramAllocated: 8192);
        $ok         = $this->makeServer(id: 2, cpuCores: 4, cpuAllocated: 2, ram: 8192, ramAllocated: 4096);

        $config = [
            'cpu_overprovisioning' => 0,
            'ram_overprovisioning' => 0,
            'avoid_overprovision'  => true,
        ];

        $db = new class([$overloaded, $ok]) {
            public function __construct(private array $servers) {}
            public function find(string $table, string $cond = ''): array { return $this->servers; }
        };

        $modConfig = fn(string $mod): array => $config;

        $stub        = new ServerTestStub();
        $stub->di    = new \FakeDI();
        $stub->di->db         = $db;
        $stub->di->mod_config = $modConfig;

        $this->assertSame(2, $stub->find_empty($this->makeProduct('fill')));
    }

    // -------------------------------------------------------------------------
    // find_access tests

    public function testFindAccessPrefersHostname(): void
    {
        $server           = new \stdClass();
        $server->hostname = 'pve.example.com';
        $server->ipv4     = '10.0.0.1';
        $server->ipv6     = '::1';

        $stub = new ServerTestStub();
        $this->assertSame('pve.example.com', $stub->find_access($server));
    }

    public function testFindAccessFallsBackToIpv4(): void
    {
        $server           = new \stdClass();
        $server->hostname = '';
        $server->ipv4     = '10.0.0.1';
        $server->ipv6     = '';

        $stub = new ServerTestStub();
        $this->assertSame('10.0.0.1', $stub->find_access($server));
    }

    public function testFindAccessFallsBackToIpv6(): void
    {
        $server           = new \stdClass();
        $server->hostname = '';
        $server->ipv4     = '';
        $server->ipv6     = '2001:db8::1';

        $stub = new ServerTestStub();
        $this->assertSame('2001:db8::1', $stub->find_access($server));
    }

    public function testFindAccessThrowsWhenNoAddressAvailable(): void
    {
        $server           = new \stdClass();
        $server->hostname = '';
        $server->ipv4     = '';
        $server->ipv6     = '';
        $server->id       = 99;

        $stub = new ServerTestStub();
        $this->expectException(\Box_Exception::class);
        $stub->find_access($server);
    }
}
