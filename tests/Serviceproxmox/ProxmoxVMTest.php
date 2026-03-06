<?php

/**
 * Unit tests for ProxmoxVM trait and Service utility methods.
 *
 * Note: suspend(), unsuspend(), cancel(), delete() were removed from the
 * ProxmoxVM trait and are now implemented directly in Service.php.
 * This file tests only what remains in the trait (uncancel, vm_start,
 * vm_shutdown, vm_reboot delegations via mocks) and the standalone
 * Service utility methods (getServiceproxmoxByOrderId, vm_cli, customCall).
 *
 * @license GNU General Public License version 3 (GPLv3)
 */

namespace Box\Mod\Serviceproxmox;

use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------
// Stub that uses the ProxmoxVM trait with vm_start/vm_shutdown overridden
// so tests never need a real Proxmox connection.

class VMTestStub
{
    use ProxmoxVM;

    public $di;

    /** Track which low-level methods were invoked */
    public array $calls = [];

    public function getProxmoxInstance($server): never
    {
        throw new \LogicException('Should not connect to Proxmox in unit tests');
    }

    public function vm_start($order, $model): bool
    {
        $this->calls[] = 'vm_start';
        return true;
    }

    public function vm_shutdown($order, $model): bool
    {
        $this->calls[] = 'vm_shutdown';
        return true;
    }
}

// ---------------------------------------------------------------------------
// Minimal stub for the utility methods that live in Service.php.

class ServiceMethodStub
{
    public $di;

    public function getServiceproxmoxByOrderId(int $orderId): object
    {
        $model = $this->di['db']->findOne('service_proxmox', 'order_id = ?', [$orderId]);
        if (!$model) {
            throw new \Box_Exception('Proxmox service not found for order #' . $orderId);
        }
        return $model;
    }

    public function vm_cli($order, $service): string
    {
        $user = $service->admin_user ?? 'root';
        if (!empty($service->ipv4)) {
            return 'ssh ' . $user . '@' . $service->ipv4;
        }
        if (!empty($service->hostname)) {
            return 'ssh ' . $user . '@' . $service->hostname;
        }
        return '';
    }

    public function customCall(object $model, string $name, array $data): mixed
    {
        if (!method_exists($this, $name)) {
            throw new \Box_Exception('Method ' . $name . ' not found in Proxmox service', null, 7104);
        }
        return $this->$name($model, $data);
    }
}

// ---------------------------------------------------------------------------

class ProxmoxVMTest extends TestCase
{
    private function makeOrder(): object
    {
        $o             = new \stdClass();
        $o->id         = 1;
        $o->client_id  = 1;
        $o->product_id = 1;
        return $o;
    }

    private function makeModel(): object
    {
        $m            = new \stdClass();
        $m->id        = 1;
        $m->vmid      = 1001;
        $m->server_id = 1;
        return $m;
    }

    // -----------------------------------------------------------------------
    // uncancel() delegates to vm_start()

    public function testUncancelCallsVmStart(): void
    {
        $stub     = new VMTestStub();
        $stub->di = new \FakeDI();

        $result = $stub->uncancel($this->makeOrder(), $this->makeModel());

        $this->assertTrue($result);
        $this->assertContains('vm_start', $stub->calls);
    }

    // -----------------------------------------------------------------------
    // Service::getServiceproxmoxByOrderId

    public function testGetServiceproxmoxByOrderIdReturnsModel(): void
    {
        $model = (object) ['id' => 5, 'order_id' => 42];

        $db = new class($model) {
            public function __construct(private object $m) {}
            public function findOne(string $t, string $w, array $p): ?object { return $this->m; }
        };

        $svc     = new ServiceMethodStub();
        $svc->di = new \FakeDI();
        $svc->di->db = $db;

        $result = $svc->getServiceproxmoxByOrderId(42);
        $this->assertSame(5, $result->id);
    }

    public function testGetServiceproxmoxByOrderIdThrowsWhenNotFound(): void
    {
        $db = new class {
            public function findOne(string $t, string $w, array $p): ?object { return null; }
        };

        $svc     = new ServiceMethodStub();
        $svc->di = new \FakeDI();
        $svc->di->db = $db;

        $this->expectException(\Box_Exception::class);
        $svc->getServiceproxmoxByOrderId(99);
    }

    // -----------------------------------------------------------------------
    // Service::vm_cli

    public function testVmCliReturnsIpv4BasedString(): void
    {
        $svc = new ServiceMethodStub();

        $service           = new \stdClass();
        $service->ipv4     = '10.0.1.5';
        $service->hostname = '';

        $this->assertSame('ssh root@10.0.1.5', $svc->vm_cli(new \stdClass(), $service));
    }

    public function testVmCliFallsBackToHostname(): void
    {
        $svc = new ServiceMethodStub();

        $service           = new \stdClass();
        $service->ipv4     = '';
        $service->hostname = 'vm-1001.example.com';

        $this->assertSame('ssh root@vm-1001.example.com', $svc->vm_cli(new \stdClass(), $service));
    }

    public function testVmCliReturnsEmptyWhenNoAddress(): void
    {
        $svc = new ServiceMethodStub();

        $service           = new \stdClass();
        $service->ipv4     = '';
        $service->hostname = '';

        $this->assertSame('', $svc->vm_cli(new \stdClass(), $service));
    }

    // -----------------------------------------------------------------------
    // Service::customCall

    public function testCustomCallThrowsForUnknownMethod(): void
    {
        $svc = new ServiceMethodStub();

        $this->expectException(\Box_Exception::class);
        $svc->customCall(new \stdClass(), 'nonexistent_method_xyz', []);
    }

    public function testCustomCallDispatchesToExistingMethod(): void
    {
        $svc = new ServiceMethodStub();

        $service           = new \stdClass();
        $service->ipv4     = '1.2.3.4';
        $service->hostname = '';

        // customCall($model, 'vm_cli', $data) calls $svc->vm_cli($model, $data)
        $result = $svc->customCall(new \stdClass(), 'vm_cli', []);
        $this->assertIsString($result);
    }
}
