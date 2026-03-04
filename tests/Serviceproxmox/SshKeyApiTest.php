<?php

/**
 * Unit tests for SSH key CRUD logic (save_client_ssh_key, get, delete)
 * and Client API validation rules.
 *
 * @license GNU General Public License version 3 (GPLv3)
 */

namespace Box\Mod\Serviceproxmox;

use PHPUnit\Framework\TestCase;

/**
 * Stub that exposes Service SSH key methods without the full FOSSBilling DI.
 */
class SshKeyServiceStub
{
    use ProxmoxIPAM;
    use ProxmoxServer;
    use ProxmoxAuthentication;
    use ProxmoxVM;
    use ProxmoxTemplates;

    public $di;

    public function getProxmoxInstance($server): never
    {
        throw new \LogicException('should not be called');
    }

    // Expose Service SSH key methods directly
    public function save_client_ssh_key(int $clientId, string $key): void
    {
        $meta = $this->di['db']->findOne(
            'extension_meta',
            'ext = ? AND rel_type = ? AND rel_id = ? AND meta_key = ?',
            ['mod_serviceproxmox', 'client', $clientId, 'ssh_key']
        );

        if (!$meta) {
            $meta             = $this->di['db']->dispense('extension_meta');
            $meta->ext        = 'mod_serviceproxmox';
            $meta->rel_type   = 'client';
            $meta->rel_id     = $clientId;
            $meta->meta_key   = 'ssh_key';
            $meta->created_at = date('Y-m-d H:i:s');
        }

        $meta->meta_value = $key;
        $meta->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($meta);
    }

    public function get_client_ssh_key(int $clientId): ?string
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

    public function delete_client_ssh_key(int $clientId): void
    {
        $meta = $this->di['db']->findOne(
            'extension_meta',
            'ext = ? AND rel_type = ? AND rel_id = ? AND meta_key = ?',
            ['mod_serviceproxmox', 'client', $clientId, 'ssh_key']
        );

        if ($meta) {
            $this->di['db']->trash($meta);
        }
    }
}

class SshKeyApiTest extends TestCase
{
    // -------------------------------------------------------------------------
    // SSH key format validation (mirrors Client API logic)

    private function validateSshKey(string $key): bool
    {
        $key = trim($key);
        $allowed = ['ssh-rsa', 'ssh-ed25519', 'ssh-ecdsa', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521', 'sk-ssh-ed25519', 'sk-ecdsa-sha2-nistp256'];
        $valid = false;
        foreach ($allowed as $prefix) {
            if (str_starts_with($key, $prefix . ' ')) {
                $valid = true;
                break;
            }
        }
        if (!$valid) return false;
        $parts = explode(' ', $key);
        return count($parts) >= 2 && strlen($parts[1]) >= 20;
    }

    public function testValidRsaKeyPassesValidation(): void
    {
        $key = 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABgQC7 user@host';
        $this->assertTrue($this->validateSshKey($key));
    }

    public function testValidEd25519KeyPassesValidation(): void
    {
        $key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl user@host';
        $this->assertTrue($this->validateSshKey($key));
    }

    public function testValidEcdsaKeyPassesValidation(): void
    {
        $key = 'ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYAAABBBEmKSENjQEezOmxkZMy user@host';
        $this->assertTrue($this->validateSshKey($key));
    }

    public function testPrivateKeyFailsValidation(): void
    {
        $key = '-----BEGIN OPENSSH PRIVATE KEY----- AAAA...';
        $this->assertFalse($this->validateSshKey($key));
    }

    public function testRandomStringFailsValidation(): void
    {
        $this->assertFalse($this->validateSshKey('not a key at all'));
    }

    public function testKeyWithoutDataPartFailsValidation(): void
    {
        $this->assertFalse($this->validateSshKey('ssh-rsa'));
    }

    public function testKeyWithTooShortDataFailsValidation(): void
    {
        $this->assertFalse($this->validateSshKey('ssh-rsa short'));
    }

    public function testLeadingWhitespaceIsStrippedBeforeValidation(): void
    {
        $key = '  ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl user@host  ';
        $this->assertTrue($this->validateSshKey($key));
    }

    // -------------------------------------------------------------------------
    // Service CRUD tests

    private function makeDbMock(bool $hasExisting = false, string $existingKey = ''): object
    {
        return new class($hasExisting, $existingKey) {
            public bool $stored   = false;
            public bool $trashed  = false;
            public ?object $dispensed = null;

            public function __construct(
                private bool $hasExisting,
                private string $existingKey,
            ) {}

            public function findOne(string $table, string $where, array $params): ?object
            {
                if ($this->hasExisting) {
                    $m             = new \stdClass();
                    $m->meta_value = $this->existingKey;
                    return $m;
                }
                return null;
            }

            public function dispense(string $table): object
            {
                $this->dispensed = new \stdClass();
                return $this->dispensed;
            }

            public function store(object $bean): void  { $this->stored  = true; }
            public function trash(object $bean): void  { $this->trashed = true; }
        };
    }

    public function testSaveKeyCreatesNewMetaWhenNoneExists(): void
    {
        $db   = $this->makeDbMock(false);
        $stub = new SshKeyServiceStub();
        $stub->di     = new \FakeDI();
        $stub->di->db = $db;

        $stub->save_client_ssh_key(1, 'ssh-ed25519 AAAA...');

        $this->assertTrue($db->stored);
        $this->assertNotNull($db->dispensed);
    }

    public function testSaveKeyUpdatesExistingMeta(): void
    {
        $db   = $this->makeDbMock(true, 'ssh-rsa OLDKEY...');
        $stub = new SshKeyServiceStub();
        $stub->di     = new \FakeDI();
        $stub->di->db = $db;

        $stub->save_client_ssh_key(1, 'ssh-ed25519 NEWKEY...');

        $this->assertTrue($db->stored);
        $this->assertNull($db->dispensed); // no dispense = updated existing
    }

    public function testGetKeyReturnsNullWhenNotSet(): void
    {
        $db   = $this->makeDbMock(false);
        $stub = new SshKeyServiceStub();
        $stub->di     = new \FakeDI();
        $stub->di->db = $db;

        $this->assertNull($stub->get_client_ssh_key(1));
    }

    public function testGetKeyReturnsTrimmedKey(): void
    {
        $db   = $this->makeDbMock(true, "  ssh-ed25519 AAAA...  \n");
        $stub = new SshKeyServiceStub();
        $stub->di     = new \FakeDI();
        $stub->di->db = $db;

        $result = $stub->get_client_ssh_key(1);
        $this->assertSame('ssh-ed25519 AAAA...', $result);
    }

    public function testDeleteKeyCallsTrash(): void
    {
        $db   = $this->makeDbMock(true, 'ssh-ed25519 AAAA...');
        $stub = new SshKeyServiceStub();
        $stub->di     = new \FakeDI();
        $stub->di->db = $db;

        $stub->delete_client_ssh_key(1);

        $this->assertTrue($db->trashed);
    }

    public function testDeleteKeyDoesNothingWhenNoKeyExists(): void
    {
        $db   = $this->makeDbMock(false);
        $stub = new SshKeyServiceStub();
        $stub->di     = new \FakeDI();
        $stub->di->db = $db;

        $stub->delete_client_ssh_key(1); // must not throw

        $this->assertFalse($db->trashed);
    }
}
