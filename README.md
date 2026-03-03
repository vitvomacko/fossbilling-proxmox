> **Note**:
> This is an **actively maintained fork** of the archived [FOSSBilling/Proxmox](https://github.com/FOSSBilling/Proxmox) module.
> The original upstream was archived in January 2026. This fork fixes critical bugs and continues development.

# Proxmox module for FOSSBilling

> **Warning**: This module is still in active development. Test thoroughly before deploying to production.

WIP Proxmox support for FOSSBilling. As stated above, this is still heavily a WIP and is not ready for production use. Please only install it if you are a developer and intend to contribute back to the module. Please see the issues (especially [#26](https://github.com/FOSSBilling/Proxmox/issues/26)) for the current status of the module as some great work is being done, but it's not yet to a complete state.

## Server List
![image](https://github.com/Anuril/Proxmox/assets/1939311/d81a052e-6c00-429b-81aa-7a3cd8dfad71)

## Features
- Manage pools of Proxmox servers (orders can be allocated to servers automatically based on their capacity)
- Complete Privilege Separation – each client can only see their own VMs
- Provision LXC containers (beta)
- Provision QEMU KVM machines via clone or from ISO (beta)
- IP address management (IPAM) – automatic IP allocation from configured ranges
- Clients can start, shutdown and reboot their VMs
- Fill or spread placement strategy configurable per product
- Rudimentary backup of module data (module data is not lost when reinstalling)


## TODOs:
- Better Error Handling when creating unexpected things happen & get returned from pve host.
- Better VM Allocation procedure
- Consistent Naming: Templates might be confusing...
- VM & LXC Template setup needs to be expanded so it can create VMs from it.
- Provisioning of VMs with Cloudinit (https://pve.proxmox.com/wiki/Cloud-Init_Support)
- Work on Usability to configure products and manage customer's products


## Requirements
- Tested on Proxmox VE 7 or higher, PVE 6 should work too

## Installation ( For 0.1.0 Preview!)

### Prerequisites

You need to run Fossbilling 0.5.5, otherwise this preview will not work.
- Make sure you uninstall the Module first by going to Extensions -> Overview
- Then, make sure that you move the pmxconfig folder in your Fossbilling rootfolder so the new installer doesn't accidentially restore an old backup.
```mv /var/www/pmxconfig /var/www/pmxold```
- Make sure you don't have any tables beginning with service_promxox in your database anymore:
```
-- Create a stored procedure to drop tables starting with "service_proxmox" in the "client" database --

USE client; -- Set this to the database where FOSSBilling is installed

DELIMITER //
CREATE PROCEDURE DropTables()
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE tableName VARCHAR(255);
    DECLARE cur CURSOR FOR
        SELECT table_name
        FROM information_schema.tables
        WHERE table_schema = 'client' -- replace client with your database name
        AND table_name LIKE 'service_proxmox%';
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    OPEN cur;
    read_loop: LOOP
        FETCH cur INTO tableName;
        IF done THEN
            LEAVE read_loop;
        END IF;
        SET @sql = CONCAT('DROP TABLE IF EXISTS `', tableName, '`;');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END LOOP;
    CLOSE cur;
END;
//
DELIMITER ;

-- Call the stored procedure to drop the tables
CALL DropTables();

-- Drop the stored procedure
DROP PROCEDURE IF EXISTS DropTables;
```

### Installation

```bash
# Go to your FOSSBilling modules directory
cd /var/www/fossbilling/modules

# Clone this fork
git clone https://github.com/vitvomacko/fossbilling-proxmox.git Serviceproxmox

# Install PHP dependencies
cd Serviceproxmox/src
composer install --no-dev

# Fix ownership (adjust user to match your web server)
chown -R www-data:www-data /var/www/fossbilling/modules/Serviceproxmox
```

Then in the FOSSBilling admin area:

1. Go to **Extensions → Overview** and install the **Proxmox** module.
2. Go to **Proxmox → Servers** and add your Proxmox node(s).
3. Click **Prepare Server** on each server (creates the API user, token and permissions).
4. Configure IP ranges under **Proxmox → IPAM** so the module can auto-assign IPs.
5. Create products under **Products** and select *Proxmox* as the service type.

The Proxmox Addon now has its own Menu Entry:

![image](https://github.com/Anuril/Proxmox/assets/1939311/13ad3290-dda2-403d-be71-a1d06b2390ec)

## Storage List 
![image](https://github.com/Anuril/Proxmox/assets/1939311/01505103-3e76-4f48-89fb-16775e9b6a91)

## Templates

### VM Templates
![image](https://github.com/Anuril/Proxmox/assets/1939311/37ef5104-91fe-4275-a4db-6481f99fc71a)

### LXC Appliances
![image](https://github.com/Anuril/Proxmox/assets/1939311/96c9ec9e-087f-4736-a087-01527d532368)

### LXC Appliances
![image](https://github.com/Anuril/Proxmox/assets/1939311/7b4a780e-c3a9-44ff-87a1-a4814ef883e8)

## IPAM
![image](https://github.com/Anuril/Proxmox/assets/1939311/d8444494-c43b-4791-9bbe-27434754da8c)

![image](https://github.com/Anuril/Proxmox/assets/1939311/b1072dc6-1839-4a1e-b8c2-242d76d8d57d)

![image](https://github.com/Anuril/Proxmox/assets/1939311/738d573e-7c61-4ca0-98b9-7bc644aae353)

![image](https://github.com/Anuril/Proxmox/assets/1939311/1c4860e8-905f-4852-827d-a41d795daf0c)


## Settings
![Admin_General](https://github.com/Anuril/Proxmox/assets/1939311/42a3492b-9df7-48d8-a1c3-98e6ed698758)
![Backup](https://github.com/Anuril/Proxmox/assets/1939311/31d4c1a6-3e46-49cf-935c-af65b0582d2a)
![image](https://github.com/Anuril/Proxmox/assets/1939311/01505103-3e76-4f48-89fb-16775e9b6a91)

## Licensing
This module is licensed under the GNU General Public License v3.0. See the LICENSE file for more information.

## Copyright
Original module: Christoph Schläpfer & the FOSSBilling Team.
Fork maintainer: [vitvomacko](https://github.com/vitvomacko)

Based on [previous work](https://github.com/scith/BoxBilling_Proxmox) by [Scitch](https://github.com/scitch).
