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
 * Proxmox module for FOSSBilling
 */
trait ProxmoxVM
{
	/* ################################################################################################### */
	/* #####################################  VM Management  ############################################ */
	/* ################################################################################################### */
	/**
	 * Suspend Proxmox VM
	 * @param $order
	 * @return boolean
	 */
	public function suspend($order, $model)
	{
		// Shutdown VM
		$this->vm_shutdown($order, $model);
		// TODO: Check that the VM was shutdown, otherwise send an email to the admin

		$model->updated_at = date('Y-m-d H:i:s');
		$this->di['db']->store($model);

		return true;
	}

	/**
	 * Unsuspend Proxmox VM
	 * @param $order
	 * @return boolean
	 */
	public function unsuspend($order, $model)
	{
		// Power on VM?
		$this->vm_start($order, $model);
		$model->updated_at = date('Y-m-d H:i:s');
		$this->di['db']->store($model);

		return true;
	}

	/**
	 * Cancel Proxmox VM
	 * @param $order
	 * @return boolean
	 */
	public function cancel($order, $model)
	{
		return $this->suspend($order, $model);
	}

	/**
	 * Uncancel Proxmox VM
	 * @param $order
	 * @return boolean
	 */
	public function uncancel($order, $model)
	{
		return $this->unsuspend($order, $model);
	}

	/**
	 * Delete Proxmox VM
	 * @param $order
	 * @return boolean
	 */
	public function delete($order, $model)
	{
		if (is_object($model)) {

			$product = $this->di['db']->load('product', $order->product_id);
			$product_config = json_decode($product->config, 1);
			$server = $this->di['db']->findOne('service_proxmox_server', 'id=:id', array(':id' => $model->server_id));

			$proxmox = $this->getProxmoxInstance($server);
			if (!$proxmox->login()) {
				throw new \Box_Exception("Login to Proxmox Host failed");
			}

			$node     = $server->name;
			$virt     = $product_config['virt'];
			$vmid     = $model->vmid;
			$base_url = "/nodes/$node/$virt/$vmid";

			$status = $proxmox->get("$base_url/status/current");
			if (empty($status)) {
				throw new \Box_Exception("VMID $vmid cannot be found on node $node");
			}

			if ($status['status'] !== 'stopped') {
				$proxmox->post("$base_url/status/shutdown", ['forceStop' => true]);

				// Wait up to 120 s for the VM to stop
				$max_retries = 12;
				for ($i = 0; $i < $max_retries; $i++) {
					sleep(10);
					$status = $proxmox->get("$base_url/status/current");
					if ($status['status'] === 'stopped') {
						break;
					}
					if ($i === $max_retries - 1) {
						throw new \Box_Exception("VM $vmid did not stop within 120 seconds. Cannot delete.");
					}
				}
			}

			if (!$proxmox->delete($base_url)) {
				throw new \Box_Exception("VM $vmid could not be deleted from Proxmox.");
			}
			return true;
		}
		return false;
	}

	/*
	*	VM status
	*
	*	TODO: Add more Information
	*/
	public function vm_info($order, $service)
	{
		$product = $this->di['db']->load('product', $order->product_id);
		$product_config = json_decode($product->config, 1);
		$client = $this->di['db']->load('client', $order->client_id);
		$server = $this->di['db']->findOne('service_proxmox_server', 'id=:id', array(':id' => $service->server_id));
		$clientuser = $this->di['db']->findOne('service_proxmox_users', 'server_id = ? and client_id = ?', array($server->id, $client->id));

		$proxmox = $this->getProxmoxInstance($server);
		if ($proxmox->getVersion()) {
			$status = $proxmox->get("/nodes/" . $server->name . "/" . $product_config['virt'] . "/" . $service->vmid . "/status/current");
			// VM monitoring?

			$output = array(
				'status'	=> $status['status']
			);
			return $output;
		} else {
			throw new \Box_Exception("Login to Proxmox Host failed.");
		}
	}

	/*
		Cold Reboot VM
	*/
	public function vm_reboot($order, $service)
	{
		$product = $this->di['db']->load('product', $order->product_id);
		$product_config = json_decode($product->config, 1);
		$client = $this->di['db']->load('client', $order->client_id);

		$server = $this->di['db']->findOne('service_proxmox_server', 'id=:id', array(':id' => $service->server_id));
		$clientuser = $this->di['db']->findOne('service_proxmox_users', 'server_id = ? and client_id = ?', array($server->id, $client->id));

		$proxmox = $this->getProxmoxInstance($server);
		if (!$proxmox->login()) {
			throw new \Box_Exception("Login to Proxmox Host failed.");
		}

		$base_url = "/nodes/" . $server->name . "/" . $product_config['virt'] . "/" . $service->vmid;

		// Graceful shutdown with force, max 120 s
		$proxmox->post("$base_url/status/shutdown", ['forceStop' => true]);
		for ($i = 0; $i < 12; $i++) {
			sleep(10);
			$status = $proxmox->get("$base_url/status/current");
			if (!empty($status) && $status['status'] === 'stopped') {
				break;
			}
			if ($i === 11) {
				throw new \Box_Exception("VM did not stop within 120 seconds. Reboot aborted.");
			}
		}

		// Start
		$proxmox->post("$base_url/status/start", []);
		for ($i = 0; $i < 12; $i++) {
			sleep(10);
			$status = $proxmox->get("$base_url/status/current");
			if (!empty($status) && $status['status'] === 'running') {
				return true;
			}
		}
		throw new \Box_Exception("VM did not reach 'running' state within 120 seconds after reboot.");
	}

	/*
		Start VM
	*/
	public function vm_start($order, $service)
	{
		$product = $this->di['db']->load('product', $order->product_id);
		$product_config = json_decode($product->config, 1);
		$client = $this->di['db']->load('client', $order->client_id);
		$server = $this->di['db']->findOne('service_proxmox_server', 'id=:id', array(':id' => $service->server_id));
		$clientuser = $this->di['db']->findOne('service_proxmox_users', 'server_id = ? and client_id = ?', array($server->id, $client->id));

		$proxmox = $this->getProxmoxInstance($server);
		if ($proxmox->login()) {
			$proxmox->post("/nodes/" . $server->name . "/" . $product_config['virt'] . "/" . $service->vmid . "/status/start", array());
			return true;
		} else {
			throw new \Box_Exception("Login to Proxmox Host failed.");
		}
	}

	/*
		Shutdown VM
	*/
	public function vm_shutdown($order, $service)
	{
		$product = $this->di['db']->load('product', $order->product_id);
		$product_config = json_decode($product->config, 1);
		$client = $this->di['db']->load('client', $order->client_id);
		$server = $this->di['db']->findOne('service_proxmox_server', 'id=:id', array(':id' => $service->server_id));

		$clientuser = $this->di['db']->findOne('service_proxmox_users', 'server_id = ? and client_id = ?', array($server->id, $client->id));
		$proxmox = $this->getProxmoxInstance($server);
		if ($proxmox->login()) {
			$settings = array(
				'forceStop' 	=> true
			);

			$proxmox->post("/nodes/" . $server->name . "/" . $product_config['virt'] . "/" . $service->vmid . "/status/shutdown", $settings);
			return true;
		} else {
			throw new \Box_Exception("Login to Proxmox Host failed.");
		}
	}
}
