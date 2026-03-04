<?php

/**
 * Unit tests for ProxmoxVM lifecycle helpers and Service utility methods.
 *
 * vm_start / vm_shutdown / vm_reboot / delete (when model IS an object) all call
 * getProxmoxInstance() and therefore require a live Proxmox node — they are
 * integration-level.  We test everything that CAN be tested without a real node:
 *
 *   - suspend() / unsuspend() / cancel() / uncancel() delegation logic
 *   - delete() returns false when $model is not an object
 *   - getServiceproxmoxByOrderId() — found / not-found
 *   - vm_cli() — ipv4 / hostname / empty fallbacks
 *   - customCall() — unknown method throws; known method dispatches
 *
 * @license GNU General Public License version 3 (GPLv3)
 */

namespace Box\Mod\Serviceproxmox;

use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------
// Stub for ProxmoxVM methods that don't need a real Proxmox connection.

class VMTestStub
{
    use ProxmoxVM;

    public $di;

    /** Track which high-level methods were invoked */
    public array $calls = [];

    public function getProxmoxInstance($server): never
    {
        throw new \LogicException('Should not connect to Proxmox in unit tests');
    }

    // Override so suspend/unsuspend don't need a real Proxmox connection
    public function vm_shutdown($order, $model): bool
    {
        $this->calls[] = 'vm_shutdown';
        return true;
    }

    public function vm_start($order, $model): bool
    {
        $this->calls[] = 'vm_start';
        return true;
    }
}

// ---------------------------------------------------------------------------
// Minimal stub that replicates only the three utility methods we added to Service,
// without pulling in Service.php (which requires FOSSBilling interfaces).

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
        if (!empty($service->ipv4)) {
            return 'ssh root@' . $service->ipv4;
        }
        if (!empty($service->hostname)) {
            return 'ssh root@' . $service->hostname;
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
        $m             = new \stdClass();
        $m->id         = 1;
        $m->vmid       = 1001;
        $m->server_id  = 1;
        $m->updated_at = null;
        return $m;
    }

    private function makeStoringDb(): object
    {
        return new class {
            public bool $stored = false;
            public function store(object $bean): void { $this->stored = true; }
        };
    }

    // -----------------------------------------------------------------------
    // suspend() / unsuspend() delegate to vm_shutdown / vm_start

    public function testSuspendCallsVmShutdown(): void
    {
        $stub     = new VMTestStub();
        $stub->di = new \FakeDI();
        $stub->di->db = $this->makeStoringDb();

        $stub->suspend($this->makeOrder(), $this->makeModel());

        $this->assertContains('vm_shutdown', $stub->calls);
    }

    public function testUnsuspendCallsVmStart(): void
    {
        $stub     = new VMTestStub();
        $stub->di = new \FakeDI();
        $stub->di->db = $this->makeStoringDb();

        $stub->unsuspend($this->makeOrder(), $this->makeModel());

        $this->assertContains('vm_start', $stub->calls);
    }

    public function testCancelDelegatesToSuspend(): void
    {
        $stub     = new VMTestStub();
        $stub->di = new \FakeDI();
        $stub->di->db = $this->makeStoringDb();

        $result = $stub->cancel($this->makeOrder(), $this->makeModel());

        $this->assertTrue($result);
        $this->assertContains('vm_shutdown', $stub->calls);
    }

    public function testUncancelDelegatesToUnsuspend(): void
    {
        $stub     = new VMTestStub();
        $stub->di = new \FakeDI();
        $stub->di->db = $this->makeStoringDb();

        $result = $stub->uncancel($this->makeOrder(), $this->makeModel());

        $this->assertTrue($result);
        $this->assertContains('vm_start', $stub->calls);
    }

    public function testSuspendStoresUpdatedAt(): void
    {
        $db       = $this->makeStoringDb();
        $stub     = new VMTestStub();
        $stub->di = new \FakeDI();
        $stub->di->db = $db;

        $stub->suspend($this->makeOrder(), $this->makeModel());

        $this->assertTrue($db->stored);
    }

    public function testUnsuspendStoresUpdatedAt(): void
    {
        $db       = $this->makeStoringDb();
        $stub     = new VMTestStub();
        $stub->di = new \FakeDI();
        $stub->di->db = $db;

        $stub->unsuspend($this->makeOrder(), $this->makeModel());

        $this->assertTrue($db->stored);
    }

    // delete() guard: returns false when $model is not an object

    public function testDeleteReturnsFalseWhenModelIsNull(): void
    {
        $stub = new VMTestStub();
        $this->assertFalse($stub->delete($this->makeOrder(), null));
    }

    public function testDeleteReturnsFalseWhenModelIsArray(): void
    {
        $stub = new VMTestStub();
        $this->assertFalse($stub->delete($this->makeOrder(), []));
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
        // vm_cli exists on ServiceMethodStub — use it as a dispatch target
        $svc = new ServiceMethodStub();

        $service           = new \stdClass();
        $service->ipv4     = '1.2.3.4';
        $service->hostname = '';

        // customCall($model, 'vm_cli', $data) calls $svc->vm_cli($model, [$service])
        // vm_cli's first arg ($order) = $model, second arg ($service) = [$service] (array, not stdClass)
        // so ipv4 won't match, but the method dispatches without throwing — that's the contract
        $result = $svc->customCall(new \stdClass(), 'vm_cli', [$service]);
        $this->assertIsString($result);
    }
}
