-- ================================================
-- DENTAL CLINIC DATABASE CLEANUP SCRIPT
-- ================================================
-- This script removes unused tables and views
-- Run this in phpMyAdmin or MySQL client
-- ================================================

USE dental_clinic;

-- ================================================
-- DROP UNUSED VIEWS (not referenced in code)
-- ================================================

DROP VIEW IF EXISTS active_treatment_plans;
DROP VIEW IF EXISTS doctor_statistics;
DROP VIEW IF EXISTS financial_stats_view;
DROP VIEW IF EXISTS patient_balance_view;
DROP VIEW IF EXISTS patient_dental_status;

-- ================================================
-- DROP UNUSED TABLES (empty, not referenced in code)
-- ================================================

DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS follow_up_reminders;
DROP TABLE IF EXISTS system_settings;

-- ================================================
-- TABLES TO REVIEW MANUALLY BEFORE DROPPING
-- ================================================
-- These tables have data or minimal usage
-- Review before uncommenting:

-- DROP TABLE IF EXISTS treatment_stages_config;  -- Has 16 rows of data
-- DROP TABLE IF EXISTS treatment_templates;       -- Empty, used in 1 file
-- DROP TABLE IF EXISTS tooth_treatments;          -- Empty, used in 1 file

-- ================================================
-- VERIFICATION QUERIES
-- ================================================
-- Run these after cleanup to verify:

-- Show remaining tables
-- SHOW TABLES;

-- Show remaining views
-- SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = 'dental_clinic';
