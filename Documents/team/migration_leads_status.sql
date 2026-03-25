-- Run this on your Hostinger database via phpMyAdmin to fix the schema drift for the Leads module.
ALTER TABLE `leads` MODIFY COLUMN `status` ENUM('New', 'Message Sent', 'To Call', 'Done Calling', 'Contacted', 'Converted', 'Rejected') DEFAULT 'New';
