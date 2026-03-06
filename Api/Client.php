<?php

/**
 * Proxmox module for FOSSBilling
 *
 * @author   FOSSBilling (https://www.fossbilling.org) & Anuril (https://github.com/anuril)
 * @license  GNU General Public License version 3 (GPLv3)
 *
 * This software may contain code previously used in the BoxBilling project.
 * Copyright BoxBilling, Inc 2011-2021
 * Original Author: Scitch (https://github.com/scitch)
 *
 * This source file is subject to the GNU General Public License version 3 (GPLv3) that is bundled
 * with this source code in the file LICENSE.
 * This Module has been written originally by Scitch (https://github.com/scitch) and has been forked from the original BoxBilling Module.
 * It has been rewritten extensively.
 */

namespace Box\Mod\Serviceproxmox\Api;

class Client extends \Api_Abstract
{
    /**
     * Universal dispatcher for custom service methods.
     * Verifies order ownership before delegating to Service->customCall().
     */
    public function __call($name, $arguments)
    {
        if (!isset($arguments[0])) {
            throw new \Box_Exception('API call is missing arguments', null, 7103);
        }

        $data = $arguments[0];

        if (empty($data['order_id'])) {
            throw new \Box_Exception('Order ID is missing', null, 7103);
        }

        $order = $this->di['db']->findOne('client_order', "id=:id", [':id' => $data['order_id']]);
        if (!$order || $order->client_id != $this->getIdentity()->id) {
            throw new \Box_Exception('Order not found', null, 7104);
        }

        $model = $this->getService()->getServiceproxmoxByOrderId($data['order_id']);

        return $this->getService()->customCall($model, $name, $data);
    }

    // -------------------------------------------------------------------------
    // Shared ownership check helper
    // -------------------------------------------------------------------------

    private function loadOrderAndService(array $data): array
    {
        $this->di['validator']->checkRequiredParamsForArray(['order_id' => 'Order ID is missing'], $data);

        $order = $this->di['db']->findOne('client_order', "id=:id", [':id' => $data['order_id']]);
        if (!$order || $order->client_id != $this->getIdentity()->id) {
            throw new \Box_Exception('Order not found');
        }

        $service = $this->di['db']->findOne('service_proxmox', "order_id=:id", [':id' => $data['order_id']]);
        if (!$service) {
            throw new \Box_Exception('Proxmox service not found');
        }

        return [$order, $service];
    }

    // -------------------------------------------------------------------------
    // VM information
    // -------------------------------------------------------------------------

    /**
     * Return current VM status and connection details.
     *
     * @return array ['server', 'username', 'cli', 'status']
     */
    public function vm_get($data): array
    {
        [$order, $service] = $this->loadOrderAndService($data);

        $server = $this->di['db']->findOne('service_proxmox_server', 'id=:id', [':id' => $service->server_id]);
        if (!$server) {
            return [];
        }

        $vm_info = $this->getService()->vm_info($order, $service);

        return [
            'server'   => $server->hostname,
            'username' => $service->admin_user ?? 'root',
            'cli'      => $this->getService()->vm_cli($order, $service),
            'status'   => $vm_info['status'],
        ];
    }

    /**
     * Return Proxmox server details (non-sensitive fields only).
     *
     * @return array ['name', 'hostname', 'port']
     */
    public function server_get($data): array
    {
        [$order, $service] = $this->loadOrderAndService($data);

        $server = $this->di['db']->findOne('service_proxmox_server', 'id=:id', [':id' => $service->server_id]);
        if (!$server) {
            return [];
        }

        return [
            'name'     => $server->name,
            'hostname' => $server->hostname ?? $server->ipv4,
            'port'     => $server->port,
        ];
    }

    /**
     * Return the service_proxmox record fields visible to the client.
     *
     * @return array ['vmid', 'hostname', 'ipv4', 'config', 'status']
     */
    public function get_proxmox_service($data): array
    {
        [$order, $service] = $this->loadOrderAndService($data);

        return [
            'vmid'     => $service->vmid,
            'hostname' => $service->hostname,
            'ipv4'     => $service->ipv4,
            'config'   => $service->config,
            'status'   => $service->status,
            'username' => $service->admin_user ?? 'root',
        ];
    }

    // -------------------------------------------------------------------------
    // VM controls
    // -------------------------------------------------------------------------

    /**
     * Dispatch a power-management action (start / shutdown / reboot).
     *
     * @param array $data ['order_id', 'method' => 'start'|'shutdown'|'reboot']
     */
    public function vm_manage($data): bool
    {
        $this->di['validator']->checkRequiredParamsForArray(
            ['order_id' => 'Order ID is missing', 'method' => 'Method is missing'],
            $data
        );

        [$order, $service] = $this->loadOrderAndService($data);

        switch ($data['method']) {
            case 'start':
                $this->getService()->vm_start($order, $service);
                break;
            case 'shutdown':
                $this->getService()->vm_shutdown($order, $service);
                break;
            case 'reboot':
                $this->getService()->vm_reboot($order, $service);
                break;
            default:
                throw new \Box_Exception('Unknown vm_manage method: ' . $data['method']);
        }

        return true;
    }

    /**
     * Reinstall a VM — destroys the current instance and re-provisions from the same product.
     * Issues a new password; SSH key and VMID are reused.
     *
     * @param array $data ['order_id']
     * @return array New credentials (ip, username, password)
     */
    public function vm_reinstall($data): array
    {
        [$order, $service] = $this->loadOrderAndService($data);

        if ($order->status !== 'active') {
            throw new \Box_Exception('Only active orders can be reinstalled.');
        }

        return $this->getService()->vm_reinstall($order, $service);
    }

    /**
     * Generate a Proxmox VNC console URL for the VM.
     *
     * Returns a time-limited URL that opens the Proxmox noVNC interface in a new tab.
     * The client's browser must be able to reach the Proxmox host directly.
     *
     * @param array $data ['order_id']
     * @return array ['url' => 'https://...']
     */
    public function vm_console($data): array
    {
        [$order, $service] = $this->loadOrderAndService($data);

        return $this->getService()->vm_console($order, $service);
    }

    // -------------------------------------------------------------------------
    // SSH key management
    // -------------------------------------------------------------------------

    /**
     * Save or replace the client's SSH public key.
     *
     * The key is validated and stored in extension_meta. It will be injected into
     * new VMs at provisioning time; existing running VMs are not updated.
     *
     * @param array $data ['ssh_key' => 'ssh-rsa AAAA...']
     */
    public function ssh_key_save($data): bool
    {
        if (empty($data['ssh_key'])) {
            throw new \Box_Exception('SSH key is required');
        }

        $key = trim($data['ssh_key']);

        $allowed_prefixes = ['ssh-rsa', 'ssh-ed25519', 'ssh-ecdsa', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521', 'sk-ssh-ed25519', 'sk-ecdsa-sha2-nistp256'];
        $valid = false;
        foreach ($allowed_prefixes as $prefix) {
            if (str_starts_with($key, $prefix . ' ')) {
                $valid = true;
                break;
            }
        }
        if (!$valid) {
            throw new \Box_Exception('Invalid SSH public key format. Key must start with ssh-rsa, ssh-ed25519, or ecdsa-sha2-*');
        }

        $parts = explode(' ', $key);
        if (count($parts) < 2 || strlen($parts[1]) < 20) {
            throw new \Box_Exception('SSH key appears to be incomplete');
        }

        $client_id = $this->di['loggedin_client']->id;
        $this->getService()->save_client_ssh_key($client_id, $key);

        return true;
    }

    /**
     * Return the client's stored SSH public key.
     *
     * @return array ['ssh_key' => '...' | null]
     */
    public function ssh_key_get($data): array
    {
        $client_id = $this->di['loggedin_client']->id;
        return ['ssh_key' => $this->getService()->get_client_ssh_key($client_id)];
    }

    /**
     * Delete the client's stored SSH public key.
     */
    public function ssh_key_delete($data): bool
    {
        $client_id = $this->di['loggedin_client']->id;
        $this->getService()->delete_client_ssh_key($client_id);
        return true;
    }
}
