-- greytHR employees mirrored locally (run on existing installs)

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS allurehr_employee (
  employeeId INT NOT NULL,
  name VARCHAR(255) NOT NULL DEFAULT '',
  NickName VARCHAR(255) NULL,
  BranchID INT NULL,
  RoleID INT NULL,
  mobile VARCHAR(20) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (employeeId),
  KEY idx_allurehr_emp_branch (BranchID),
  KEY idx_allurehr_emp_role (RoleID),
  CONSTRAINT fk_allurehr_emp_branch
    FOREIGN KEY (BranchID) REFERENCES allureone_branch (id)
    ON DELETE SET NULL,
  CONSTRAINT fk_allurehr_emp_role
    FOREIGN KEY (RoleID) REFERENCES allureone_roles (id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing installs (run once if needed):
-- ALTER TABLE allurehr_employee ADD COLUMN RoleID INT NULL AFTER BranchID;
-- ALTER TABLE allurehr_employee ADD KEY idx_allurehr_emp_role (RoleID);
-- ALTER TABLE allurehr_employee ADD COLUMN mobile VARCHAR(20) NULL AFTER RoleID;
