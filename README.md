> **Note**:
> This is an **actively maintained fork** of the archived [FOSSBilling/Proxmox](https://github.com/FOSSBilling/Proxmox) module.
> The original upstream was archived in January 2026. This fork fixes critical bugs and continues active development.

# Proxmox module for FOSSBilling

Provision Proxmox VMs and LXC containers directly from FOSSBilling. Supports automated IP allocation, SSH key injection, cloud-init, and full VM lifecycle management from the client area.

## Features

- **Multi-server pool** — allocate orders across Proxmox nodes automatically (fill or spread strategy)
- **Privilege separation** — each client gets their own Proxmox user; clients can only see their own VMs
- **LXC containers** — provision from appliance templates with SSH key injection at creation
- **QEMU KVM** — provision via clone or fresh creation; cloud-init and legacy ISO modes both supported
- **Cloud-init provisioning** — set IP, hostname, DNS, root password and SSH key before first boot; static IP or DHCP from IPAM
- **SSH key management** — clients store their SSH public key in the client area; automatically injected into new VMs at provisioning
- **IP address management (IPAM)** — automatic allocation from configured subnets with gateway and dedicated-IP exclusion
- **VM controls** — clients can start, shutdown and reboot their VMs from the client area
- **Fill / spread placement** — configurable per product; respects CPU/RAM overprovisioning limits
- **Admin backup/restore** — module data survives reinstallation

## Requirements

- FOSSBilling 0.6+
- Proxmox VE 7 or higher (PVE 6 may work)
- PHP 8.1+ with Composer

## Installation

```bash
# Go to your FOSSBilling modules directory
cd /var/www/fossbilling/modules

# Clone this fork
git clone https://github.com/vitvomacko/fossbilling-proxmox.git Serviceproxmox

# Install PHP dependencies
cd Serviceproxmox
composer install --no-dev

# Fix ownership (adjust user to match your web server)
chown -R nginx:nginx /var/www/fossbilling/modules/Serviceproxmox
```

Then in the FOSSBilling admin area:

1. Go to **Extensions → Overview** and activate the **Proxmox** module.
2. Go to **Proxmox → Servers** and add your Proxmox node(s).
3. Click **Prepare Server** on each server — creates the API user, token and ACL permissions.
4. Configure IP ranges under **Proxmox → IPAM** so the module can auto-assign IPs.
5. Create products under **Products** and select *Proxmox* as the service type.

## Upgrading from the original FOSSBilling/Proxmox module

Back up the old config directory first:

```bash
mv /var/www/pmxconfig /var/www/pmxconfig.bak
```

Drop the old `service_proxmox*` tables before activating the new module, or run the cleanup SQL below for a clean slate:

```sql
-- Run in your FOSSBilling database to drop all service_proxmox* tables
SET @db = DATABASE();

SELECT GROUP_CONCAT('DROP TABLE IF EXISTS `', table_name, '`;' SEPARATOR '\n')
INTO @stmts
FROM information_schema.tables
WHERE table_schema = @db
  AND table_name LIKE 'service_proxmox%';

PREPARE stmt FROM @stmts;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
```

## SSH Key Management

Clients can store a single SSH public key in their client area
(**My Services → [any Proxmox order] → SSH Public Key** card).

The key is validated on save (must start with `ssh-rsa`, `ssh-ed25519`, `ecdsa-sha2-*`,
`sk-ssh-ed25519`, or `sk-ecdsa-sha2-nistp256`) and stored in `extension_meta`.

At provisioning time the key is injected:
- **LXC** — via the `ssh-public-keys` parameter at container creation
- **QEMU (cloud-init)** — via `sshkeys` (URL-encoded per RFC 3986) in a `PUT /config` call before first boot
- **QEMU (non-cloud-init)** — via the same `sshkeys` config if the image is cloud-init capable

Updating the key in the client area does **not** affect already-running VMs.

## Cloud-init (QEMU)

Enable cloud-init provisioning per product by setting `cloud_init = true` in the product config.

The module will:
1. Allocate an IP from IPAM **before** the VM is created
2. Add a cloud-init drive (`ide2: storage:cloudinit`) to the VM
3. Configure `ciuser`, `cipassword`, `nameserver`, `ipconfig0` (static or DHCP) and `sshkeys` via `PUT /config`
4. Start the VM — cloud-init picks up all settings on first boot

## Development

```bash
# Run unit tests (PHP 8.1+, Composer)
composer install
php vendor/bin/phpunit
```

All 58 unit tests must pass before merging.

## Screenshots

### Server List
![Server List](https://github.com/Anuril/Proxmox/assets/1939311/d81a052e-6c00-429b-81aa-7a3cd8dfad71)

### Storage List
![Storage List](https://github.com/Anuril/Proxmox/assets/1939311/01505103-3e76-4f48-89fb-16775e9b6a91)

### VM Templates
![VM Templates](https://github.com/Anuril/Proxmox/assets/1939311/37ef5104-91fe-4275-a4db-6481f99fc71a)

### LXC Appliances
![LXC Appliances](https://github.com/Anuril/Proxmox/assets/1939311/96c9ec9e-087f-4736-a087-01527d532368)

### IPAM
![IPAM](https://github.com/Anuril/Proxmox/assets/1939311/d8444494-c43b-4791-9bbe-27434754da8c)

## Licensing

GNU General Public License v3.0 — see [LICENSE](LICENSE).

## Copyright

Original module: Christoph Schläpfer & the FOSSBilling Team.
Fork maintainer: [vitvomacko](https://github.com/vitvomacko)
Based on [previous work](https://github.com/scith/BoxBilling_Proxmox) by [Scitch](https://github.com/scitch).
