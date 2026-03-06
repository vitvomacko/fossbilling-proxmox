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

// Load Composer autoloader when running standalone (outside FOSSBilling's class loader).
// In a deployed FOSSBilling instance composer install must be run in this directory first.
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
	require_once __DIR__ . '/vendor/autoload.php';
}

use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use PDO;
use PDOException;


/**
 * Provides the Proxmox module for FOSSBilling.
 */
class Service implements \FOSSBilling\InjectionAwareInterface
{
	protected $di;
	private $pdo;
	public function setDi(\Pimple\Container|null $di): void
	{
		$this->di = $di;
	}

	public function getDi(): ?\Pimple\Container
	{
		return $this->di;
	}
	use ProxmoxAuthentication;
	use ProxmoxServer;
	use ProxmoxVM;
	use ProxmoxTemplates;
	use ProxmoxIPAM;


	/**
	 * Returns a PDO instance for the database connection.
	 *
	 * @return PDO The PDO instance.
	 */
	private function getPdo(): PDO
	{
		if (!$this->pdo) {
			$db = \FOSSBilling\Config::getProperty('db');
			$host     = $db['host']     ?? 'localhost';
			$port     = $db['port']     ?? '3306';
			$dbname   = $db['name']     ?? '';
			$db_user  = $db['user']     ?? '';
			$db_pass  = $db['password'] ?? '';

			$this->pdo = new PDO(
				"mysql:host={$host};port={$port};dbname={$dbname}",
				$db_user,
				$db_pass
			);
			$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		}

		return $this->pdo;
	}


	/**
	 * Fetches all tables in the database that start with 'service_proxmox'.
	 *
	 * @return array An array of table names.
	 */
	private function fetchServiceProxmoxTables(): array
	{
		$pdo = $this->getPdo();
		$stmt = $pdo->query("SHOW TABLES LIKE 'service_proxmox%'");
		return $stmt->fetchAll(PDO::FETCH_COLUMN);
	}
	/**
	 * Method to install module. In most cases you will provide your own
	 * database table or tables to store extension related data.
	 *
	 * If your extension is not very complicated then extension_meta
	 * database table might be enough.
	 *
	 * @return bool
	 * 
	 * @throws \Box_Exception
	 */
	public function install(): bool
	{
		// read manifest.json to get current version number
		$manifest = json_decode(file_get_contents(__DIR__ . '/manifest.json'), true);
		$version = $manifest['version'];

		// check if there is a sqldump backup with "uninstall" in it's name in the pmxconfig folder, if so, restore it
		$filesystem = new Filesystem();
		$finder = new Finder();
		if (!$filesystem->exists(PATH_ROOT . '/pmxconfig')) {
			$filesystem->mkdir(PATH_ROOT . '/pmxconfig');
		}

		$pmxbackup_dir = $finder->in(PATH_ROOT . '/pmxconfig')->files()->name('proxmox_uninstall_*.sql');

		// find newest file in pmxbackup_dir according to timestamp
		$pmxbackup_file = array();
		foreach ($pmxbackup_dir as $file) {
			$pmxbackup_file[$file->getMTime()] = $file->getFilename();
		}
		ksort($pmxbackup_file);
		$pmxbackup_file = array_reverse($pmxbackup_file);
		$pmxbackup_file = reset($pmxbackup_file);

		// if pmxbackup_file is not empty, restore the sql dump to database
		if (!empty($pmxbackup_file)) {
			// Load the backup
			$dump = file_get_contents(PATH_ROOT . '/pmxconfig/' . $pmxbackup_file);

			// Check if dump is not empty
			if (!empty($dump)) {
				// Check version number in first line of dump
				$original_dump = $dump;
				$version_line = strtok($dump, "\n");

				// Get version number from line
				$dump_version = str_replace('-- Proxmox module version: ', '', $version_line);
				$dump = str_replace($version_line . "\n", '', $dump);


				try {
					// Retrieve PDO instance
					$pdo = $this->getPdo();
					// If version number in dump is smaller than current version number, restore dump and run upgrade function
					if ($dump_version < $version) {
						// Split the dump into an array by each sql command
						$query_array = explode(";", $dump);

						// Execute each sql command
						foreach ($query_array as $query) {
							if (!empty(trim($query))) {
								$pdo->exec($query);
							}
						}

						$this->upgrade($dump_version); // Runs all migrations between current and next version
					} elseif ($dump_version == $version) {
						// Split the dump into an array by each sql command
						$query_array = explode(";", $dump);

						// Execute each sql command
						foreach ($query_array as $query) {
							if (!empty(trim($query))) {
								$pdo->exec($query);
							}
						}
					} else {
						throw new \Box_Exception("The version number of the sql dump is bigger than the current version number of the module. Please check the installed Module version.", null, 9684);
					}
				} catch (\Box_Exception $e) {
					throw new \Box_Exception('Error during restoration process: ' . $e->getMessage());
				}
			}
		} else {
			// Get a list of all SQL migration files
			$migrations = glob(__DIR__ . '/migrations/*.sql');

			// Sort the array of migration files by their version numbers (which are in their file names)
			usort($migrations, function ($a, $b) {
				return version_compare(basename($a, '.sql'), basename($b, '.sql'));
			});

			try {
				// Create a new PDO instance, connecting to your MySQL database
				$pdo = $this->getPdo();

				// Loop through each migration file
				foreach ($migrations as $migration) {
					// Extract the version number from the file name
					$filename = basename($migration, '.sql');
					$version = str_replace('_', '.', $filename);

					// Log the execution of the current migration
					error_log('Running migration ' . $version . ' from ' . $migration);

					// Read the SQL statements from the file into a string
					$sql = file_get_contents($migration);

					// Split the string of SQL statements into an array
					// This uses the ';' character to identify the end of each SQL statement
					$statements = explode(';', $sql);

					// Loop through each SQL statement
					foreach ($statements as $statement) {
						// If the statement is not empty or just whitespace
						if (trim($statement)) {
							// Execute the SQL statement
							$pdo->exec($statement);
						}
					}
				}
			} catch (PDOException $e) {
				// If any errors occur while connecting to the database or executing SQL, log the error message and terminate the script
				error_log('PDO Exception: ' . $e->getMessage());
				exit(1);
			}
		}

		$extensionService = $this->di['mod_service']('extension');
		$extensionService->setConfig(['ext' => 'mod_serviceproxmox', 'cpu_overprovisioning' => '1', 'ram_overprovisioning' => '1', 'storage_overprovisioning' => '1', 'avoid_overprovision' => '0', 'no_overprovision' => '1', 'use_auth_tokens' => '1', 'pmx_debug_logging' =>'0']);


		return true;
	}


	/**
	 * Method to uninstall module.
	 * Now creates a sql dump of the database tables and stores it in the pmxconfig folder
	 * 
	 * @return bool
	 */
	public function uninstall(): bool
	{
		// Retrieve PDO instance
		$pdo = $this->getPdo();
		$tables = $this->fetchServiceProxmoxTables();

		foreach ($tables as $table) {
			$pdo->exec("DROP TABLE IF EXISTS `$table`");
		}
		return true;
	}

	/**
	 * Method to upgrade module.
	 * 
	 * @param string $previous_version
	 * @return bool
	 */
	public function upgrade($previous_version): bool
	{
		// read current module version from manifest.json
		$manifest = json_decode(file_get_contents(__DIR__ . '/manifest.json'), true);
		$current_version = $manifest['version'];

		// read migrations directory and find all files between current version and previous version
		$migrations = glob(__DIR__ . '/migrations/*.sql');

		// sort migrations by version number (Filenames: 0.0.1.sql, 0.0.2.sql etc.)
		usort($migrations, function ($a, $b) {
			return version_compare(basename($a, '.sql'), basename($b, '.sql'));
		});

		// Retrieve PDO instance
		$pdo = $this->getPdo();

		foreach ($migrations as $migration) {
			// get version from filename
			error_log('Found migration: ' . $migration);
			$filename = basename($migration, '.sql');
			$version = str_replace('_', '.', $filename);

			if (version_compare($version, $previous_version, '>') && version_compare($version, $current_version, '<=')) {
				error_log('Applying migration: ' . $migration);

				// run migration
				$migration_sql = file_get_contents($migration);
				$pdo->exec($migration_sql);
			} else {
				error_log('Skipping migration: ' . $migration);
			}
		}

		return true;
	}

	/**
	 * Method to update module. When you release new version to
	 * extensions.fossbilling.org then this method will be called
	 * after the new files are placed.
	 *
	 * @param array $manifest - information about the new module version
	 *
	 * @return bool
	 *
	 * @throws \Box_Exception
	 */
	public function update(array $manifest): bool
	{
		// throw new \Box_Exception("Throw exception to terminate module update process with a message", array(), 125);
		return true;
	}




	/**
	 * Method to check if all tables have been migrated to current Module Version.
	 * Not yet used, but will be in the admin settings page for the module
	 * 
	 * @param string $action
	 * @return bool
	 */
	public function check_db_migration()
	{
		// read current module version from manifest.json
		$manifest = json_decode(file_get_contents(__DIR__ . '/manifest.json'), true);
		$current_version = $manifest['version'];
		$tables = $this->fetchServiceProxmoxTables();

		foreach ($tables as $table) {
			$sql = "SELECT table_comment FROM INFORMATION_SCHEMA.TABLES WHERE table_schema='" . DB_NAME . "' AND table_name='" . $table . "'"; /* @phpstan-ignore-line */
			$result = $this->di['db']->query($sql);
			$row = $result->fetch();
			// check if version is the same as current version
			if ($row['table_comment'] != $current_version) {
				throw new \Box_Exception('Database migration is not up to date. Please run the database migration script.');
			}
		}
		return true;
	}

	/**
	 * Method to create configuration Backups of Proxmox tables
	 * 
	 * @param string $data - 'uninstall' or 'backup'
	 * @return bool
	 */
	public function pmxdbbackup($data)
	{
		// create backup of all Proxmox tables
		try {
			$filesystem = new Filesystem();
			$filesystem->mkdir([PATH_ROOT . '/pmxconfig'], 0750);
		} catch (IOException $e) {
			error_log('An error occurred while creating backup directory at ' . $e->getMessage());
			throw new \Box_Exception('Unable to create directory pmxconfig');
		}

		if ($data == 'uninstall') {
			$filename = '/pmxconfig/proxmox_uninstall_' . date('Y-m-d_H-i-s') . '.sql';
		} else {
			$filename = '/pmxconfig/proxmox_backup_' . date('Y-m-d_H-i-s') . '.sql';
		}


		try {
			$pdo = $this->getPdo();
			$tables = $tables = $this->fetchServiceProxmoxTables();
			$backup = '';

			// Loop through tables and create SQL statement
			foreach ($tables as $table) {
				$result = $pdo->query('SELECT * FROM ' . $table);
				$num_fields = $result->columnCount();

				$backup .= 'DROP TABLE IF EXISTS ' . $table . ';';
				$row2 = $pdo->query('SHOW CREATE TABLE ' . $table)->fetch(PDO::FETCH_NUM);
				$backup .= "\n\n" . $row2[1] . ";\n\n";

				while ($row = $result->fetch(PDO::FETCH_NUM)) {
					$backup .= 'INSERT INTO ' . $table . ' VALUES(';
					for ($j = 0; $j < $num_fields; $j++) {
						$row[$j] = addslashes($row[$j]);
						$row[$j] = preg_replace("/\n/", "\\n", $row[$j]);
						if (isset($row[$j])) {
							$backup .= '"' . $row[$j] . '"';
						} else {
							$backup .= '""';
						}
						if ($j < ($num_fields - 1)) {
							$backup .= ',';
						}
					}
					$backup .= ");\n";
				}
				$backup .= "\n\n\n";
			}

			// Save to file
			$handle = fopen(PATH_ROOT . $filename, 'w+');
			fwrite($handle, $backup);
			fclose($handle);

		} catch (\Box_Exception $e) {
			throw new \Box_Exception('Error during backup process: ' . $e->getMessage());
		}

		// read current module version from manifest.json
		$manifest = json_decode(file_get_contents(__DIR__ . '/manifest.json'), true);
		$current_version = $manifest['version'];
		// add version comment to backup file
		$version_comment = '-- Proxmox module version: ' . $current_version . "\n";
		$filename = PATH_ROOT . $filename;
		$handle = fopen($filename, 'r+');
		$len = strlen($version_comment);
		$final_len = filesize($filename) + $len;
		$cache_old = fread($handle, $len);
		rewind($handle);
		$i = 1;
		while (ftell($handle) < $final_len) {
			fwrite($handle, $version_comment);
			$version_comment = $cache_old;
			$cache_old = fread($handle, $len);
			fseek($handle, $i * $len);
			$i++;
		}
		fclose($handle);

		return true;
	}


	/**
	 * Method to list all Proxmox backups
	 * 
	 * @return array
	 */
	public function pmxbackuplist()
	{
		$files = glob(PATH_ROOT . '/pmxconfig/*.sql');
		$backups = array();
		foreach ($files as $file) {
			$backups[] = basename($file);
		}
		return $backups;
	}

	/**
	 * Method to restore Proxmox tables from backup
	 * It's a destructive operation, as it will drop & overwrite all existing tables
	 * 
	 * @param string $data - filename of backup
	 * @return bool
	 */
	public function pmxbackuprestore($data)
	{
		$manifest = json_decode(file_get_contents(__DIR__ . '/manifest.json'), true);
		$version = $manifest['version'];
		$dump = file_get_contents(PATH_ROOT . '/pmxconfig/' . $data['backup']);
		if (!empty($dump)) {
			$version_line = strtok($dump, "\n");
			$dump_version = str_replace('-- Proxmox module version: ', '', $version_line);
			$dump = str_replace($version_line . "\n", '', $dump);

			if ($dump_version == $version) {
				try {
					// create PDO instance
					$pdo = $this->getPdo();
					// split the dump into an array by each sql command
					$query_array = explode(";", $dump);

					// execute each sql command
					foreach ($query_array as $query) {
						if (!empty(trim($query))) {
							$pdo->exec($query);
						}
					}

					return true;
				} catch (\Box_Exception $e) {
					throw new \Box_Exception('Error during restoration process: ' . $e->getMessage());
				}
			} else {
				throw new \Box_Exception("The sql dump file (V: $dump_version) is not compatible with the current module version (V: $version). Please check the file.", null);
			}
		} else {
			throw new \Box_Exception("The sql dump file is empty. Please check the file.", null);
		}
	}

	// Create function that runs with cron job hook
	// This function will run every 5 minutes and update all servers
	// Disabled for now
	/*
	public static function onBeforeAdminCronRun(\Box_Event $event)
	{
		 // getting Dependency Injector
		 $di = $event->getDi();

		 // @note almost in all cases you will need Admin API
		 $api = $di['api_admin'];
		 // get all servers from database
		 // like this $vms = $this->di['db']->findAll('service_proxmox', 'server_id = :server_id', array(':server_id' => $data['id']));
 		 $servers = $di['db']->findAll('service_proxmox_server');
		 // rum getHardwareData, getStorageData and getAssignedResources for each server 
		 foreach ($servers as $server) {
			$hardwareData = $api->getHardwareData($server['id']);
			$storageData = $api->getStorageData($server['id']);
			$assignedResources = $api->getAssignedResources($server['id']);
		  }
	} */


	/* ################################################################################################### */
	/* ###########################################  Orders  ############################################## */
	/* ################################################################################################### */

	/**
	 * @param \Model_ClientOrder $order
	 * @return void
	 */
	public function create($order)
	{
		$config = json_decode($order->config, 1);

		$product = $this->di['db']->getExistingModelById('Product', $order->product_id, 'Product not found');

		$model                	= $this->di['db']->dispense('service_proxmox');
		$model->client_id     	= $order->client_id;
		$model->order_id     	= $order->id;
		$model->created_at    	= date('Y-m-d H:i:s');
		$model->updated_at    	= date('Y-m-d H:i:s');

		$model->server_id = $this->find_empty($product);
		$this->di['db']->store($model);

		return $model;
	}

	/**
	 * @param \Model_ClientOrder $order
	 * @return boolean
	 */
	public function activate($order, $model)
	{
		if (!is_object($model)) {
			throw new \Box_Exception('Could not activate order. Service was not created');
		}
		$config = json_decode($order->config, 1);

		$client  = $this->di['db']->load('client', $order->client_id);
		$product = $this->di['db']->load('product', $order->product_id);
		if (!$product) {
			throw new \Box_Exception('Could not activate order because ordered product does not exists');
		}
		$product_config = json_decode($product->config, 1);

		// Allocate to an appropriate server id
		$server = $this->di['db']->load('service_proxmox_server', $model->server_id);

		// Retrieve or create client unser account in service_proxmox_users
		$clientuser = $this->di['db']->findOne('service_proxmox_users', 'server_id = ? and client_id = ?', array($server->id, $client->id));
		if (!$clientuser) {
			$this->create_client_user($server, $client);
		}

		// Connect to Proxmox API using the server admin token.
		// VM/CT creation requires permissions on storage (/storage/STORAGEID) which the client
		// token cannot have without granting broad storage access. The admin token creates the
		// resource and assigns it to the client pool — attribution is handled via pool membership.
		$serveraccess = $this->find_access($server);
		$clientuser = $this->di['db']->findOne('service_proxmox_users', 'server_id = ? and client_id = ?', array($server->id, $client->id));
		$config = $this->di['mod_config']('Serviceproxmox');
		$proxmox = new \PVE2APIClient\PVE2_API($serveraccess, $server->root_user, $server->realm, $server->root_password, port: $server->port, tokenid: $server->tokenname, tokensecret: $server->tokenvalue, debug: $config['pmx_debug_logging']);

		// Create Proxmox VM
		if (!$proxmox->login()) {
			throw new \Box_Exception('Login to Proxmox Host failed with admin credentials', null, 7457);
		}

		// Generate VMID: server_id + client_id (3 digits) + order_id (3 digits)
		// Result fits in Proxmox range 100-999999999 for typical deployments.
		$vmid = (int) ($server->id . str_pad($client->id, 3, '0', STR_PAD_LEFT) . str_pad($order->id, 3, '0', STR_PAD_LEFT));
		if ($vmid < 100) {
			$vmid = 100 + $vmid;
		}

		// Ensure VMID is not already in use on this node.
		// Proxmox returns HTTP 500 "config does not exist" for a non-existent VM/CT,
		// which the PVE2 API client throws as an exception — catch it and treat as "free".
		try {
			$vmid_in_use = $proxmox->get("/nodes/" . $server->name . "/" . $product_config['virt'] . "/" . $vmid);
		} catch (\Exception $e) {
			$vmid_in_use = null;
		}
		if ($vmid_in_use) {
			// Append a suffix and retry once; if still colliding, raise an error
			$vmid = $vmid + 1;
			try {
				$still_in_use = $proxmox->get("/nodes/" . $server->name . "/" . $product_config['virt'] . "/" . $vmid);
			} catch (\Exception $e) {
				$still_in_use = null;
			}
			if ($still_in_use) {
				throw new \Box_Exception("Could not find a free VMID for this order. Please contact support.");
			}
		}

		$proxmoxuser_password = $this->di['tools']->generatePassword(16, 4); // Generate root/console password
		$vm_name              = 'vm-' . $vmid;

		// Retrieve SSH public key from client profile (stored via client area or admin)
		$ssh_key = null;
		$ssh_key_meta = $this->di['db']->findOne(
			'extension_meta',
			'extension = ? AND rel_type = ? AND rel_id = ? AND meta_key = ?',
			['mod_serviceproxmox', 'client', $client->id, 'ssh_key']
		);
		if ($ssh_key_meta && !empty(trim($ssh_key_meta->meta_value))) {
			$ssh_key = trim($ssh_key_meta->meta_value);
		}

		// Detect cloud-init mode: only applicable to QEMU (fresh or cloned)
		$use_cloud_init = !empty($product_config['cloud_init']) && $product_config['virt'] === 'qemu';

		// For cloud-init VMs, allocate IP before creation so ipconfig0 can be set before first boot.
		// For non-cloud-init VMs, we allocate after the VM is running (legacy behaviour).
		$ipam    = null;
		$ipv4    = null;
		$gateway = null;
		$prefix  = '/24';
		if ($use_cloud_init) {
			$ipam    = $this->allocate_ip();
			$ipv4    = $ipam['ip']      ?? null;
			$gateway = $ipam['gateway'] ?? null;
			$prefix  = $ipam['prefix']  ?? '/24';
		}

		// Fetch DNS servers from IPAM settings (used by cloud-init)
		$ipam_settings = $this->di['db']->findOne('service_proxmox_ipam_settings', '1=1');
		$dns1 = $ipam_settings->dns_server_1 ?? '1.1.1.1';
		$dns2 = $ipam_settings->dns_server_2 ?? '8.8.8.8';

		// Build VM/CT creation parameters
		$clone              = '';
		$container_settings = [];
		$description        = 'Service #' . $model->id . ' – client #' . $client->id;

		if (!empty($product_config['clone'])) {
			// Clone an existing template VM (cloud image template for cloud-init)
			$clone              = '/' . $product_config['cloneid'] . '/clone';
			$container_settings = [
				'newid'       => $vmid,
				'name'        => $vm_name,
				'description' => $description,
				'full'        => true,
				'storage'     => $product_config['storage'],
				'pool'        => 'fb_client_' . $client->id,
			];
		} elseif ($product_config['virt'] === 'qemu') {
			// Create a fresh QEMU VM
			$container_settings = [
				'vmid'        => $vmid,
				'name'        => $vm_name,
				'description' => $description,
				'storage'     => $product_config['storage'],
				'memory'      => $product_config['memory'],
				'scsihw'      => 'virtio-scsi-single',
				'scsi0'       => $product_config['storage'] . ':' . ($product_config['disk'] ?? 10),
				'ostype'      => $product_config['ostype'] ?? 'other',
				'bios'        => $product_config['bios'] ?? 'seabios',
				'sockets'     => 1,
				'cores'       => $product_config['cpu'],
				'net0'        => $product_config['network'] ?? 'virtio,bridge=vmbr0',
				'onboot'      => 1,
				'pool'        => 'fb_client_' . $client->id,
			];
			if ($use_cloud_init) {
				// cloud-init drive replaces the ISO cdrom slot
				$container_settings['ide2'] = $product_config['storage'] . ':cloudinit';
			} else {
				// traditional ISO/CD-ROM install
				$container_settings['ide2'] = ($product_config['cdrom'] ?? '') . ',media=cdrom';
			}
		} else {
			// Create a fresh LXC container
			$container_settings = [
				'vmid'        => $vmid,
				'hostname'    => $vm_name,
				'description' => $description,
				'storage'     => $product_config['storage'],
				'memory'      => $product_config['memory'],
				'swap'        => $product_config['swap'] ?? 512,
				'ostemplate'  => $product_config['ostemplate'],
				'password'    => $proxmoxuser_password,
				'net0'        => $product_config['network'] ?? 'name=eth0,bridge=vmbr0,dhcp',
				'rootfs'      => $product_config['storage'] . ':' . ($product_config['disk'] ?? 8),
				'onboot'      => 1,
				'pool'        => 'fb_client_' . $client->id,
			];
			if ($ssh_key) {
				$container_settings['ssh-public-keys'] = $ssh_key;
			}
		}

		// Create the VM/CT
		$vmurl    = "/nodes/" . $server->name . "/" . $product_config['virt'] . $clone;
		$vmcreate = $proxmox->post($vmurl, $container_settings);
		if (!$vmcreate) {
			throw new \Box_Exception("VPS could not be created on the Proxmox node.");
		}

		// Apply cloud-init configuration before first boot.
		// This sets the IP, credentials, SSH key and DNS — all picked up during boot.
		if ($use_cloud_init) {
			$ci_config = [
				'ciuser'     => 'root',
				'cipassword' => $proxmoxuser_password,
				'nameserver' => trim($dns1 . ' ' . $dns2),
				'ipconfig0'  => $ipv4
					? 'ip=' . $ipv4 . $prefix . ',gw=' . $gateway
					: 'ip=dhcp',
			];
			if ($ssh_key) {
				// Proxmox requires the key to be URL-encoded (RFC 3986, not application/x-www-form-urlencoded)
				$ci_config['sshkeys'] = rawurlencode($ssh_key);
			}
			$proxmox->put("/nodes/" . $server->name . "/qemu/" . $vmid . "/config", $ci_config);
		} elseif ($ssh_key && $product_config['virt'] === 'qemu') {
			// Non-cloud-init QEMU: inject SSH key into the VM config so it's available
			// if the image is already cloud-init capable but we're not managing it fully.
			$proxmox->put(
				"/nodes/" . $server->name . "/qemu/" . $vmid . "/config",
				['sshkeys' => rawurlencode($ssh_key)]
			);
		}

		// Wait for the creation/clone task to settle before starting
		sleep(10);

		// Start the VM/CT
		$proxmox->post("/nodes/" . $server->name . "/" . $product_config['virt'] . "/" . $vmid . "/status/start", []);

		// Poll until running, with a maximum wait of 120 seconds (12 × 10 s)
		$max_retries = 12;
		for ($i = 0; $i < $max_retries; $i++) {
			sleep(10);
			$status = $proxmox->get("/nodes/" . $server->name . "/" . $product_config['virt'] . "/" . $vmid . "/status/current");
			if (empty($status)) {
				throw new \Box_Exception("VMID $vmid not found after creation.");
			}
			if ($status['status'] === 'running') {
				break;
			}
			// Re-send start in case the first one was lost
			$proxmox->post("/nodes/" . $server->name . "/" . $product_config['virt'] . "/" . $vmid . "/status/start", []);
			if ($i === $max_retries - 1) {
				throw new \Box_Exception("VM $vmid did not reach 'running' state within 120 seconds.");
			}
		}

		// For non-cloud-init VMs: allocate IP after VM is confirmed running
		if (!$use_cloud_init) {
			$ipam    = $this->allocate_ip();
			$ipv4    = $ipam['ip']      ?? null;
			$gateway = $ipam['gateway'] ?? null;
		}

		// Persist the new service record
		$model->updated_at = date('Y-m-d H:i:s');
		$model->vmid       = $vmid;
		$model->password   = $proxmoxuser_password;
		$model->ipv4       = $ipv4;
		$model->hostname   = $vm_name;
		$this->di['db']->store($model);

		return [
			'ip'       => $ipv4 ?? 'To be assigned – check the client area',
			'username' => 'root',
			'password' => $proxmoxuser_password,
		];
	}

	/**
	 * Look up the service_proxmox record for a given order.
	 *
	 * @param  int $orderId
	 * @return object
	 * @throws \Box_Exception
	 */
	public function getServiceproxmoxByOrderId(int $orderId): object
	{
		$model = $this->di['db']->findOne('service_proxmox', 'order_id = ?', [$orderId]);
		if (!$model) {
			throw new \Box_Exception('Proxmox service not found for order #' . $orderId);
		}
		return $model;
	}

	/**
	 * Dispatch a named method call on the service with the given model and data.
	 * Used by Client API's __call() magic dispatcher for custom product methods.
	 *
	 * @param  object $model
	 * @param  string $name
	 * @param  array  $data
	 * @return mixed
	 * @throws \Box_Exception
	 */
	public function customCall(object $model, string $name, array $data): mixed
	{
		if (!method_exists($this, $name)) {
			throw new \Box_Exception('Method ' . $name . ' not found in Proxmox service', null, 7104);
		}
		return $this->$name($model, $data);
	}

	/**
	 * Return a ready-to-use SSH CLI string for connecting to the VM.
	 *
	 * @param  object $order
	 * @param  object $service
	 * @return string  e.g. "ssh root@10.0.1.5"
	 */
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

	/**
	 * Get the API array representation of a model
	 * Important to interact with the Order
	 * @param  object $model
	 * @return array
	 */
	public function toApiArray($model)
	{
		$server = $this->di['db']->findOne('service_proxmox_server', 'id=:id', array(':id' => $model->server_id));

		return array(
			'id'         => $model->id,
			'client_id'  => $model->client_id,
			'server_id'  => $model->server_id,
			'vmid'       => $model->vmid,
			'hostname'   => $model->hostname,
			'ipv4'       => $model->ipv4,
			'username'   => 'root',
			'password'   => $model->password,
			'server'     => $server,
			'created_at' => $model->created_at,
			'updated_at' => $model->updated_at,
		);
	}

	/**
	 * Retrieves the novnc appjs file from a Proxmox server.
	 *
	 * @param array $data An array containing the version of the appjs file to retrieve.
	 * @return object The contents of the appjs file.
	 */
	public function get_novnc_appjs($data)
	{
		$servers = $this->di['db']->find('service_proxmox_server');
		if (empty($servers)) {
			throw new \Box_Exception('No Proxmox servers configured.');
		}
		$server = reset($servers); // use first active server

		$hostname = $server->hostname;
		// build url

		$url = "https://$hostname:8006/novnc/" . $data; //$data['ver'];
		// set options
		$client = $this->getHttpClient()->withOptions([
			'verify_peer' => false,
			'verify_host' => false,
			'timeout' => 60,
		]);
		$result = $client->request('GET', $url);
		// return file
		return $result;
	}

	/**
	 * Returns an instance of the Symfony HttpClient.
	 *
	 * @return \Symfony\Component\HttpClient\HttpClient
	 */
	public function getHttpClient()
	{
		return \Symfony\Component\HttpClient\HttpClient::create();
	}



	/**
	 * Validates custom form data against a product's form fields.
	 * TODO: This needs to be fixes / changed
	 * @param array &$data The form data to validate.
	 * @param array $product The product containing the form fields to validate against.
	 * @throws \Box_Exception If a required field is missing or a read-only field is modified.
	 */
	public function validateCustomForm(array &$data, array $product)
	{
		if ($product['form_id']) {
			$formbuilderService = $this->di['mod_service']('formbuilder');
			$form = $formbuilderService->getForm($product['form_id']);

			foreach ($form['fields'] as $field) {
				if ($field['required'] == 1) {
					$field_name = $field['name'];
					if ((!isset($data[$field_name]) || empty($data[$field_name]))) {
						throw new \Box_Exception("You must fill in all required fields. " . $field['label'] . " is missing", null, 9684);
					}
				}

				if ($field['readonly'] == 1) {
					$field_name = $field['name'];
					if ($data[$field_name] != $field['default_value']) {
						throw new \Box_Exception("Field " . $field['label'] . " is read only. You can not change its value", null, 5468);
					}
				}
			}
		}
	}
	/**
	 * Returns the salt value from the configuration.
	 *
	 * @return string The salt value.
	 */
	private function _getSalt()
	{
		return \FOSSBilling\Config::getProperty('info.salt', '');
	}

	private function getProxmoxInstance($server)
	{
		$serveraccess = $this->find_access($server);
		$config = $this->di['mod_config']('Serviceproxmox');
		return new \PVE2APIClient\PVE2_API($serveraccess, $server->root_user, $server->realm, $server->root_password, port: $server->port, tokenid: $server->tokenname, tokensecret: $server->tokenvalue, debug: $config['pmx_debug_logging']);
	}

	/* ################################################################################################### */
	/* #########################################  SSH Key  ############################################### */
	/* ################################################################################################### */

	/**
	 * Save or update the client's SSH public key in extension_meta.
	 *
	 * @param int    $clientId
	 * @param string $key       Trimmed, validated SSH public key
	 */
	public function save_client_ssh_key(int $clientId, string $key): void
	{
		$meta = $this->di['db']->findOne(
			'extension_meta',
			'extension = ? AND rel_type = ? AND rel_id = ? AND meta_key = ?',
			['mod_serviceproxmox', 'client', $clientId, 'ssh_key']
		);

		if (!$meta) {
			$meta            = $this->di['db']->dispense('extension_meta');
			$meta->extension = 'mod_serviceproxmox';
			$meta->rel_type  = 'client';
			$meta->rel_id    = $clientId;
			$meta->meta_key  = 'ssh_key';
			$meta->created_at = date('Y-m-d H:i:s');
		}

		$meta->meta_value = $key;
		$meta->updated_at = date('Y-m-d H:i:s');
		$this->di['db']->store($meta);
	}

	/**
	 * Get the client's stored SSH public key, or null if none is set.
	 *
	 * @param int $clientId
	 * @return string|null
	 */
	public function get_client_ssh_key(int $clientId): ?string
	{
		$meta = $this->di['db']->findOne(
			'extension_meta',
			'extension = ? AND rel_type = ? AND rel_id = ? AND meta_key = ?',
			['mod_serviceproxmox', 'client', $clientId, 'ssh_key']
		);

		if ($meta && !empty(trim($meta->meta_value))) {
			return trim($meta->meta_value);
		}

		return null;
	}

	/**
	 * Delete the client's stored SSH public key.
	 *
	 * @param int $clientId
	 */
	public function delete_client_ssh_key(int $clientId): void
	{
		$meta = $this->di['db']->findOne(
			'extension_meta',
			'extension = ? AND rel_type = ? AND rel_id = ? AND meta_key = ?',
			['mod_serviceproxmox', 'client', $clientId, 'ssh_key']
		);

		if ($meta) {
			$this->di['db']->trash($meta);
		}
	}
}
