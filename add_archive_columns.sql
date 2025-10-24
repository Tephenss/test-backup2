-- Add is_deleted and deleted_at columns to teachers table
ALTER TABLE teachers ADD COLUMN IF NOT EXISTS is_deleted TINYINT(1) DEFAULT 0;
ALTER TABLE teachers ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;

-- Add is_deleted and deleted_at columns to students table
ALTER TABLE students ADD COLUMN IF NOT EXISTS is_deleted TINYINT(1) DEFAULT 0;
ALTER TABLE students ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;

-- Add is_deleted and deleted_at columns to subjects table
ALTER TABLE subjects ADD COLUMN IF NOT EXISTS is_deleted TINYINT(1) DEFAULT 0;
ALTER TABLE subjects ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL; 