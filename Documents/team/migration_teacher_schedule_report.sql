-- Migration: Add 'rescheduled' status to teacher_class_log
-- Run this on the production database

ALTER TABLE `teacher_class_log` 
  MODIFY COLUMN `status` ENUM('taken','not_taken','rescheduled') 
  COLLATE utf8mb4_unicode_ci DEFAULT 'taken';
