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

namespace Box\Mod\Serviceproxmox;

/**
 * VM management methods for the Proxmox module.
 *
 * Note: suspend(), unsuspend(), cancel(), and delete() are intentionally NOT defined here.
 * They are implemented directly in Service.php, which takes precedence over trait methods.
 */
trait ProxmoxVM
{
	/**
	 * Restore a previously cancelled service by starting the VM.
	 * FOSSBilling calls this when an order is un-cancelled.
	 */
	public function uncancel($order, $model): bool
	{
		return $this->vm_start($order, $model);
	}

	/**
	 * Return current VM status.
	 *
	 * @return array ['status' => 'running'|'stopped'|...]
	 */
	public function vm_info($order, $service): array
	{
		$product        = $this->di['db']->load('product', $order->product_id);
		$product_config = json_decode($product->config, true);
		$server         = $this->di['db']->findOne('service_proxmox_server', 'id=:id', [':id' => $service->server_id]);

		$proxmox = $this->getProxmoxInstance($server);
		$status  = $proxmox->get("/nodes/{$server->name}/{$product_config['virt']}/{$service->vmid}/status/current");

		return ['status' => $status['status'] ?? 'unknown'];
	}

	/**
	 * Cold-reboot a VM: graceful shutdown (force after 120 s) then start.
	 */
	public function vm_reboot($order, $service): bool
	{
		$product        = $this->di['db']->load('product', $order->product_id);
		$product_config = json_decode($product->config, true);
		$server         = $this->di['db']->findOne('service_proxmox_server', 'id=:id', [':id' => $service->server_id]);

		$proxmox  = $this->getProxmoxInstance($server);
		$base_url = "/nodes/{$server->name}/{$product_config['virt']}/{$service->vmid}";

		$proxmox->post("{$base_url}/status/shutdown", ['forceStop' => true]);

		for ($i = 0; $i < 12; $i++) {
			sleep(10);
			$status = $proxmox->get("{$base_url}/status/current");
			if (!empty($status) && $status['status'] === 'stopped') {
				break;
			}
			if ($i === 11) {
				throw new \Box_Exception('VM did not stop within 120 seconds. Reboot aborted.');
			}
		}

		$proxmox->post("{$base_url}/status/start", []);

		for ($i = 0; $i < 12; $i++) {
			sleep(10);
			$status = $proxmox->get("{$base_url}/status/current");
			if (!empty($status) && $status['status'] === 'running') {
				return true;
			}
		}
		throw new \Box_Exception("VM did not reach 'running' state within 120 seconds after reboot.");
	}

	/**
	 * Start a VM.
	 */
	public function vm_start($order, $service): bool
	{
		$product        = $this->di['db']->load('product', $order->product_id);
		$product_config = json_decode($product->config, true);
		$server         = $this->di['db']->findOne('service_proxmox_server', 'id=:id', [':id' => $service->server_id]);

		$proxmox = $this->getProxmoxInstance($server);
		$proxmox->post("/nodes/{$server->name}/{$product_config['virt']}/{$service->vmid}/status/start", []);
		return true;
	}

	/**
	 * Gracefully shut down a VM (with force-stop fallback).
	 */
	public function vm_shutdown($order, $service): bool
	{
		$product        = $this->di['db']->load('product', $order->product_id);
		$product_config = json_decode($product->config, true);
		$server         = $this->di['db']->findOne('service_proxmox_server', 'id=:id', [':id' => $service->server_id]);

		$proxmox = $this->getProxmoxInstance($server);
		$proxmox->post("/nodes/{$server->name}/{$product_config['virt']}/{$service->vmid}/status/shutdown", ['forceStop' => true]);
		return true;
	}
}
