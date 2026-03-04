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
 * IPAM Class Trait for FOSSSBilling Proxmox Module
 * 
 * This class trait contains all the functions that are used to manage the IPAM inside the Proxmox Module.
 * 
 */
trait ProxmoxIPAM
{
	/**
	 * Retrieves the IP ranges from the Proxmox IPAM.
	 *
	 * @return array An array of IP ranges.
	 */
	public function get_ip_ranges()
	{
		// get all the VM templates from the service_proxmox_vm_config_template table
		$ip_ranges = $this->di['db']->find('service_proxmox_ip_range');
		return $ip_ranges;
	}


	/**
	 * Retrieves a list of IP addresses from the Proxmox IPAM.
	 *
	 * @return array An array of IP addresses.
	 */
	public function get_ip_adresses()
	{
		// get all the VM templates from the service_proxmox_vm_config_template table
		$ip_addresses = $this->di['db']->find('service_proxmox_ipadress');
		return $ip_addresses;
	}

	/**
	 * Allocates the next free IPv4 address from the IPAM pool.
	 *
	 * Looks up all registered IPs in service_proxmox_ipadress, excludes
	 * gateway entries and IPs already assigned to active services, and
	 * returns the first available address together with its gateway and
	 * CIDR prefix length (e.g. "/24") for use in cloud-init ipconfig0.
	 *
	 * @return array|null Array with keys 'ip', 'gateway', 'prefix', or null if no IP is available.
	 */
	public function allocate_ip(): ?array
	{
		// All IPs already assigned to active services
		$assigned = $this->di['db']->getCol('SELECT ipv4 FROM service_proxmox WHERE ipv4 IS NOT NULL AND ipv4 != ""');

		// Candidate IPs: not a gateway, not already assigned
		$candidates = $this->di['db']->find('service_proxmox_ipadress', 'gateway = 0 AND dedicated = 0');

		foreach ($candidates as $candidate) {
			// Double-check in PHP – the SQL WHERE should already exclude these,
			// but be defensive in case the ORM or a future refactor misses it.
			if ($candidate->gateway || $candidate->dedicated) {
				continue;
			}
			if (!in_array($candidate->ip, $assigned, true)) {
				$range   = $this->di['db']->load('service_proxmox_ip_range', $candidate->ip_range_id);
				$gateway = $range ? $range->gateway : null;

				// Extract prefix length from CIDR notation (e.g. "192.168.1.0/24" → "/24")
				$prefix = '/24'; // safe default
				if ($range && !empty($range->cidr)) {
					$parts = explode('/', $range->cidr);
					if (isset($parts[1]) && is_numeric($parts[1])) {
						$prefix = '/' . $parts[1];
					}
				}

				return [
					'ip'      => $candidate->ip,
					'gateway' => $gateway,
					'prefix'  => $prefix,
				];
			}
		}

		return null;
	}

	/**
	 * Retrieves a list of VLANs from the Proxmox IPAM service.
	 *
	 * @return array An array of VLAN objects, each containing the VLAN ID and name.
	 */
	public function get_vlans()
	{
		$vlans = $this->di['db']->find('service_proxmox_client_vlan');
		foreach ($vlans as $vlan) {
			$client = $this->di['db']->getExistingModelById('client', $vlan->client_id);
			$vlan->client_name = $client->first_name . " " . $client->last_name;
		}

		return $vlans;
	}
}


/* ################################################################################################### */
/* ###################################  Manage PVE Network   ######################################### */
/* ################################################################################################### */


/**
 * Trait ProxmoxNetwork
 * 
 * This class trait contains all the functions that are used to manage the SDN on the PVE Hosts.
 */
trait ProxmoxNetwork
{
}
