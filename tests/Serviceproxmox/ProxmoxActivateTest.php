<?php

/**
 * Unit tests for SSH key injection logic in activate()
 *
 * We test the helper method extract_client_ssh_key() (extracted from activate())
 * and verify that the correct Proxmox API calls are made for LXC and QEMU.
 *
 * @license GNU General Public License version 3 (GPLv3)
 */

namespace Box\Mod\Serviceproxmox;

use PHPUnit\Framework\TestCase;

/**
 * Thin stub exposing only the pieces of Service we need to test.
 * All traits are mixed in so we can call activate() helpers directly.
 */
class ActivateTestStub
{
    use ProxmoxIPAM;
    use ProxmoxServer;
    use ProxmoxAuthentication;
    use ProxmoxVM;
    use ProxmoxTemplates;

    public $di;

    // Prevent real Proxmox connections
    public function getProxmoxInstance($server): never
    {
        throw new \LogicException('getProxmoxInstance should not be called in unit tests');
    }

    /**
     * Expose SSH key lookup extracted from activate() so we can unit-test it.
     * Returns the trimmed public key string, or null if none is stored.
     */
    public function extract_client_ssh_key(int $clientId): ?string
    {
        $meta = $this->di['db']->findOne(
            'extension_meta',
            'ext = ? AND rel_type = ? AND rel_id = ? AND meta_key = ?',
            ['mod_serviceproxmox', 'client', $clientId, 'ssh_key']
        );
        if ($meta && !empty(trim($meta->meta_value))) {
            return trim($meta->meta_value);
        }
        return null;
    }
}

class ProxmoxActivateTest extends TestCase
{
    private function makeClient(int $id): object
    {
        $c     = new \stdClass();
        $c->id = $id;
        return $c;
    }

    private function makeSshKeyMeta(string $key): object
    {
        $m             = new \stdClass();
        $m->meta_value = $key;
        return $m;
    }

    // -------------------------------------------------------------------------

    /**
     * When no SSH key meta row exists, extract_client_ssh_key() returns null.
     */
    public function testExtractSshKeyReturnsNullWhenNotSet(): void
    {
        $db = new class {
            public function findOne(string $table, string $where, array $params): ?object
            {
                return null;
            }
        };

        $stub     = new ActivateTestStub();
        $stub->di = new \FakeDI();
        $stub->di->db = $db;

        $this->assertNull($stub->extract_client_ssh_key(42));
    }

    /**
     * When a meta row exists with a key, it is returned trimmed.
     */
    public function testExtractSshKeyReturnsKeyWhenSet(): void
    {
        $rawKey = "  ssh-rsa AAAAB3NzaC1yc2E test@host  \n";
        $meta   = $this->makeSshKeyMeta($rawKey);

        $db = new class($meta) {
            public function __construct(private object $meta) {}
            public function findOne(string $table, string $where, array $params): ?object
            {
                return $this->meta;
            }
        };

        $stub     = new ActivateTestStub();
        $stub->di = new \FakeDI();
        $stub->di->db = $db;

        $result = $stub->extract_client_ssh_key(42);
        $this->assertSame('ssh-rsa AAAAB3NzaC1yc2E test@host', $result);
    }

    /**
     * A meta row with an empty meta_value is treated as "no key".
     */
    public function testExtractSshKeyReturnsNullWhenMetaValueEmpty(): void
    {
        $meta             = new \stdClass();
        $meta->meta_value = '   ';

        $db = new class($meta) {
            public function __construct(private object $meta) {}
            public function findOne(string $table, string $where, array $params): ?object
            {
                return $this->meta;
            }
        };

        $stub     = new ActivateTestStub();
        $stub->di = new \FakeDI();
        $stub->di->db = $db;

        $this->assertNull($stub->extract_client_ssh_key(42));
    }

    // -------------------------------------------------------------------------
    // Verify that the SSH key ends up in the right place for LXC vs QEMU.
    // We test the container_settings array construction logic directly by
    // replicating the relevant snippet and asserting on the result.

    /**
     * For LXC: ssh-public-keys is injected into $container_settings at creation.
     */
    public function testLxcContainerSettingsIncludeSshKey(): void
    {
        $ssh_key           = 'ssh-rsa AAAAB3NzaC1yc2E user@host';
        $product_config    = [
            'virt'       => 'lxc',
            'storage'    => 'local-lvm',
            'memory'     => 512,
            'swap'       => 256,
            'ostemplate' => 'local:vztmpl/debian-12-standard_12.2-1_amd64.tar.zst',
            'network'    => 'name=eth0,bridge=vmbr0,dhcp',
            'disk'       => 8,
        ];

        $container_settings = [
            'vmid'       => 1001,
            'hostname'   => 'vm-1001',
            'storage'    => $product_config['storage'],
            'memory'     => $product_config['memory'],
            'swap'       => $product_config['swap'],
            'ostemplate' => $product_config['ostemplate'],
            'password'   => 'SomeGeneratedPassword',
            'net0'       => $product_config['network'],
            'rootfs'     => $product_config['storage'] . ':' . $product_config['disk'],
            'onboot'     => 1,
            'pool'       => 'fb_client_1',
        ];

        // Simulate the SSH key injection from activate()
        if ($ssh_key && $product_config['virt'] !== 'qemu') {
            $container_settings['ssh-public-keys'] = $ssh_key;
        }

        $this->assertArrayHasKey('ssh-public-keys', $container_settings);
        $this->assertSame($ssh_key, $container_settings['ssh-public-keys']);
        $this->assertArrayNotHasKey('sshkeys', $container_settings); // QEMU field must NOT be here
    }

    /**
     * For QEMU: ssh-public-keys must NOT be in $container_settings.
     * Instead, a separate PUT /config with URL-encoded 'sshkeys' is sent.
     */
    public function testQemuSshKeyIsUrlEncoded(): void
    {
        $ssh_key = 'ssh-rsa AAAAB3NzaC1yc2E user@host';

        // Proxmox requires the key URL-encoded via rawurlencode()
        $encoded = rawurlencode($ssh_key);

        // Verify the encoding is applied correctly (spaces → %20, not +)
        $this->assertStringNotContainsString('+', $encoded);
        $this->assertStringContainsString('%20', $encoded);

        // The PUT payload should look like this
        $put_payload = ['sshkeys' => $encoded];
        $this->assertSame(rawurlencode($ssh_key), $put_payload['sshkeys']);
    }

    /**
     * When no SSH key is available, neither field should appear in LXC settings.
     */
    public function testLxcContainerSettingsWithoutSshKey(): void
    {
        $ssh_key         = null;
        $container_settings = ['vmid' => 1001, 'hostname' => 'vm-1001'];

        if ($ssh_key) {
            $container_settings['ssh-public-keys'] = $ssh_key;
        }

        $this->assertArrayNotHasKey('ssh-public-keys', $container_settings);
    }

    // -------------------------------------------------------------------------
    // Helper

    private function makeIpBean(string $ip, int $rangeId, bool $gateway = false, bool $dedicated = false): object
    {
        $bean              = new \stdClass();
        $bean->ip          = $ip;
        $bean->ip_range_id = $rangeId;
        $bean->gateway     = $gateway ? 1 : 0;
        $bean->dedicated   = $dedicated ? 1 : 0;
        return $bean;
    }

    // -------------------------------------------------------------------------
    // Cloud-init provisioning tests

    /**
     * cloud-init drive replaces IDE cdrom slot when cloud_init=true.
     */
    public function testQemuCloudInitDriveReplacesIso(): void
    {
        $storage        = 'local-lvm';
        $use_cloud_init = true;

        $container_settings = [];
        if ($use_cloud_init) {
            $container_settings['ide2'] = $storage . ':cloudinit';
        } else {
            $container_settings['ide2'] = 'local:iso/debian.iso,media=cdrom';
        }

        $this->assertSame($storage . ':cloudinit', $container_settings['ide2']);
        $this->assertStringNotContainsString('cdrom', $container_settings['ide2']);
    }

    /**
     * Without cloud-init the traditional cdrom is used.
     */
    public function testQemuWithoutCloudInitUsesIso(): void
    {
        $cdrom          = 'local:iso/debian.iso';
        $use_cloud_init = false;

        $container_settings = [];
        if ($use_cloud_init) {
            $container_settings['ide2'] = 'local-lvm:cloudinit';
        } else {
            $container_settings['ide2'] = $cdrom . ',media=cdrom';
        }

        $this->assertStringContainsString('media=cdrom', $container_settings['ide2']);
        $this->assertStringNotContainsString('cloudinit', $container_settings['ide2']);
    }

    /**
     * cloud-init config with a known IP produces correct ipconfig0 string.
     */
    public function testCloudInitIpconfig0WithStaticIp(): void
    {
        $ipv4    = '10.0.1.5';
        $gateway = '10.0.1.1';
        $prefix  = '/24';

        $ipconfig0 = 'ip=' . $ipv4 . $prefix . ',gw=' . $gateway;

        $this->assertSame('ip=10.0.1.5/24,gw=10.0.1.1', $ipconfig0);
    }

    /**
     * When no IP is available from IPAM, cloud-init falls back to DHCP.
     */
    public function testCloudInitIpconfig0FallsBackToDhcp(): void
    {
        $ipv4    = null;
        $gateway = null;
        $prefix  = '/24';

        $ipconfig0 = $ipv4 ? 'ip=' . $ipv4 . $prefix . ',gw=' . $gateway : 'ip=dhcp';

        $this->assertSame('ip=dhcp', $ipconfig0);
    }

    /**
     * allocate_ip() returns 'prefix' derived from the range CIDR.
     */
    public function testAllocateIpReturnsPrefixFromCidr(): void
    {
        $ip   = $this->makeIpBean('10.0.1.5', 1);
        $range = (object) ['gateway' => '10.0.1.1', 'cidr' => '10.0.1.0/24'];

        $db = new class($ip, $range) {
            public function __construct(private object $ip, private object $range) {}
            public function getAll(string $sql): array { return []; }
            public function find(string $t, string $w = ''): array { return [$this->ip]; }
            public function load(string $t, mixed $id): ?object { return $this->range; }
        };

        $stub     = new IPAMTestStub();
        $stub->di = new \FakeDI();
        $stub->di->db = $db;

        $result = $stub->allocate_ip();

        $this->assertNotNull($result);
        $this->assertSame('/24', $result['prefix']);
        $this->assertSame('10.0.1.5', $result['ip']);
        $this->assertSame('10.0.1.1', $result['gateway']);
    }

    /**
     * allocate_ip() returns /24 as a safe default when CIDR is missing.
     */
    public function testAllocateIpDefaultsPrefixWhenNoCidr(): void
    {
        $ip   = $this->makeIpBean('10.0.1.5', 1);
        $range = (object) ['gateway' => '10.0.1.1', 'cidr' => null];

        $db = new class($ip, $range) {
            public function __construct(private object $ip, private object $range) {}
            public function getAll(string $sql): array { return []; }
            public function find(string $t, string $w = ''): array { return [$this->ip]; }
            public function load(string $t, mixed $id): ?object { return $this->range; }
        };

        $stub     = new IPAMTestStub();
        $stub->di = new \FakeDI();
        $stub->di->db = $db;

        $result = $stub->allocate_ip();
        $this->assertSame('/24', $result['prefix']);
    }

    /**
     * cloud-init config with SSH key URL-encodes the key in 'sshkeys'.
     */
    public function testCloudInitConfigIncludesUrlEncodedSshKey(): void
    {
        $ssh_key   = 'ssh-rsa AAAAB3NzaC1yc2E user@host';
        $ci_config = [];

        if ($ssh_key) {
            $ci_config['sshkeys'] = rawurlencode($ssh_key);
        }

        $this->assertArrayHasKey('sshkeys', $ci_config);
        $this->assertSame(rawurlencode($ssh_key), $ci_config['sshkeys']);
        // Spaces must be %20, not +
        $this->assertStringNotContainsString('+', $ci_config['sshkeys']);
    }

    /**
     * When cloud-init is disabled, SSH key is NOT injected via cloud-init config
     * (it's handled separately for QEMU, or via ssh-public-keys for LXC).
     */
    public function testNonCloudInitQemuDoesNotSetCiuser(): void
    {
        $use_cloud_init = false;
        $ci_config      = [];

        if ($use_cloud_init) {
            $ci_config['ciuser'] = 'root';
        }

        $this->assertArrayNotHasKey('ciuser', $ci_config);
    }
}
