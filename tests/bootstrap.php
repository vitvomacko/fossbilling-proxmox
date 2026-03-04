<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Stub for \Box_Exception (not available outside FOSSBilling).
// FOSSBilling's real Box_Exception uses ($message, $previous = null, $code = 0),
// i.e. the 2nd argument is the previous Throwable and the 3rd is the error code —
// the inverse of PHP's built-in Exception constructor.
if (!class_exists('\Box_Exception')) {
    class Box_Exception extends \RuntimeException
    {
        public function __construct(string $message = '', mixed $previous = null, int $code = 0)
        {
            parent::__construct(
                $message,
                $code,
                ($previous instanceof \Throwable) ? $previous : null
            );
        }
    }
}

// Stub for BBTestCase (FOSSBilling framework test base class)
if (!class_exists('BBTestCase')) {
    class BBTestCase extends \PHPUnit\Framework\TestCase {}
}

/**
 * Simple DI container stub that supports both array access ($di['key'])
 * and property access ($di->key), mirroring Pimple's interface.
 */
class FakeDI implements ArrayAccess
{
    private array $data = [];

    public function offsetExists(mixed $offset): bool  { return isset($this->data[$offset]); }
    public function offsetGet(mixed $offset): mixed     { return $this->data[$offset] ?? null; }
    public function offsetSet(mixed $offset, mixed $value): void { $this->data[$offset] = $value; }
    public function offsetUnset(mixed $offset): void   { unset($this->data[$offset]); }

    // Allow property-style set for convenience in tests: $di->db = $mock
    public function __set(string $name, mixed $value): void  { $this->data[$name] = $value; }
    public function __get(string $name): mixed               { return $this->data[$name] ?? null; }
    public function __isset(string $name): bool              { return isset($this->data[$name]); }
}

// Load all module source traits/classes
require_once __DIR__ . '/../ProxmoxIPAM.php';
require_once __DIR__ . '/../ProxmoxServer.php';
require_once __DIR__ . '/../ProxmoxAuthentication.php';
require_once __DIR__ . '/../ProxmoxVM.php';
require_once __DIR__ . '/../ProxmoxTemplates.php';
