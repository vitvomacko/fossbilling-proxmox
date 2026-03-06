-- Add admin_user column to service_proxmox
-- Tracks the OS username (root for Linux, Administrator for Windows)
ALTER TABLE `service_proxmox`
    ADD COLUMN IF NOT EXISTS `admin_user` varchar(64) DEFAULT 'root'
    AFTER `password`;
