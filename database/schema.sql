-- Import into the existing empty MySQL database through phpMyAdmin.
-- No DROP statements: applying this file does not delete existing records.
CREATE TABLE IF NOT EXISTS currencies (
 code CHAR(3) PRIMARY KEY,
 name VARCHAR(80) NOT NULL,
 decimal_places SMALLINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO currencies(code,name,decimal_places) VALUES
 ('IDR','Indonesian rupiah',0),('USD','US dollar',2),('EUR','Euro',2),
 ('SGD','Singapore dollar',2),('JPY','Japanese yen',0),('MYR','Malaysian ringgit',2),
 ('AUD','Australian dollar',2),('GBP','British pound',2);
CREATE TABLE IF NOT EXISTS users (
 id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 email VARCHAR(190) NOT NULL UNIQUE,
 name VARCHAR(80) NOT NULL,
 password_hash VARCHAR(255) NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'active',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS app_settings (
 id INT PRIMARY KEY,
 owner_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
 FOREIGN KEY(owner_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO app_settings(id, owner_id) VALUES(1, NULL);
CREATE TABLE IF NOT EXISTS workspaces (
 id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 owner_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 name VARCHAR(80) NOT NULL,
 base_currency CHAR(3) NOT NULL DEFAULT 'IDR',
 revision BIGINT NOT NULL DEFAULT 0,
 state_json LONGTEXT NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(owner_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS workspace_members (
 workspace_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 user_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 role VARCHAR(16) NOT NULL,
 PRIMARY KEY(workspace_id,user_id),
 FOREIGN KEY(workspace_id) REFERENCES workspaces(id),
 FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS institutions (
 workspace_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 name VARCHAR(80) NOT NULL,
 type VARCHAR(20) NOT NULL,
 payload_json LONGTEXT NOT NULL,
 PRIMARY KEY(workspace_id,id),
 FOREIGN KEY(workspace_id) REFERENCES workspaces(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS accounts (
 workspace_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 institution_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
 name VARCHAR(80) NOT NULL,
 account_type VARCHAR(20) NOT NULL,
 currency CHAR(3) NOT NULL,
 status VARCHAR(16) NOT NULL,
 payload_json LONGTEXT NOT NULL,
 PRIMARY KEY(workspace_id,id),
 FOREIGN KEY(workspace_id) REFERENCES workspaces(id),
 FOREIGN KEY(workspace_id,institution_id) REFERENCES institutions(workspace_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS categories (
 workspace_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 name VARCHAR(80) NOT NULL,
 type VARCHAR(16) NOT NULL,
 payload_json LONGTEXT NOT NULL,
 PRIMARY KEY(workspace_id,id),
 FOREIGN KEY(workspace_id) REFERENCES workspaces(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS instruments (
 workspace_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 name VARCHAR(80) NOT NULL,
 instrument_type VARCHAR(20) NOT NULL,
 purity DECIMAL(12,8) NOT NULL,
 currency CHAR(3) NOT NULL,
 payload_json LONGTEXT NOT NULL,
 PRIMARY KEY(workspace_id,id),
 FOREIGN KEY(workspace_id) REFERENCES workspaces(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS transactions (
 workspace_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 category_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
 transaction_type VARCHAR(20) NOT NULL,
 transaction_date DATETIME(3) NOT NULL,
 description TEXT NOT NULL,
 payload_json LONGTEXT NOT NULL,
 PRIMARY KEY(workspace_id,id),
 KEY transaction_date_idx(workspace_id,transaction_date),
 FOREIGN KEY(workspace_id) REFERENCES workspaces(id),
 FOREIGN KEY(workspace_id,category_id) REFERENCES categories(workspace_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS transaction_entries (
 workspace_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 transaction_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 account_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
 amount_minor BIGINT NOT NULL,
 currency CHAR(3) NOT NULL,
 offset_type VARCHAR(20) NULL,
 PRIMARY KEY(workspace_id,id),
 KEY account_ledger_idx(workspace_id,account_id),
 FOREIGN KEY(workspace_id,transaction_id) REFERENCES transactions(workspace_id,id),
 FOREIGN KEY(workspace_id,account_id) REFERENCES accounts(workspace_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS investment_transactions (
 workspace_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 transaction_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 account_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 instrument_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 cash_account_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
 type VARCHAR(16) NOT NULL,
 quantity_micrograms BIGINT NOT NULL,
 total_minor BIGINT NULL,
 fee_minor BIGINT NOT NULL,
 tax_minor BIGINT NOT NULL,
 transaction_date DATETIME(3) NOT NULL,
 payload_json LONGTEXT NOT NULL,
 PRIMARY KEY(workspace_id,id),
 UNIQUE KEY unique_trade(workspace_id,transaction_id),
 FOREIGN KEY(workspace_id,transaction_id) REFERENCES transactions(workspace_id,id),
 FOREIGN KEY(workspace_id,account_id) REFERENCES accounts(workspace_id,id),
 FOREIGN KEY(workspace_id,instrument_id) REFERENCES instruments(workspace_id,id),
 FOREIGN KEY(workspace_id,cash_account_id) REFERENCES accounts(workspace_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS positions (
 workspace_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 account_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 instrument_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 quantity_micrograms BIGINT NOT NULL,
 cost_basis_minor BIGINT NULL,
 realized_pnl_minor BIGINT NULL,
 average_cost DECIMAL(36,8) NULL,
 updated_at DATETIME(3) NOT NULL,
 PRIMARY KEY(workspace_id,account_id,instrument_id),
 FOREIGN KEY(workspace_id,account_id) REFERENCES accounts(workspace_id,id),
 FOREIGN KEY(workspace_id,instrument_id) REFERENCES instruments(workspace_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS market_prices (
 workspace_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 instrument_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 timestamp DATETIME(3) NOT NULL,
 source VARCHAR(80) CHARACTER SET ascii NOT NULL,
 price DECIMAL(28,8) NOT NULL,
 currency CHAR(3) NOT NULL,
 PRIMARY KEY(workspace_id,instrument_id,timestamp,source),
 FOREIGN KEY(workspace_id,instrument_id) REFERENCES instruments(workspace_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS exchange_rates (
 workspace_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 base_currency CHAR(3) NOT NULL,
 quote_currency CHAR(3) NOT NULL,
 rate DECIMAL(28,12) NOT NULL,
 timestamp DATETIME(3) NOT NULL,
 source VARCHAR(80) NOT NULL,
 PRIMARY KEY(workspace_id,base_currency,quote_currency),
 FOREIGN KEY(workspace_id) REFERENCES workspaces(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS login_attempts (
 attempt_key CHAR(64) CHARACTER SET ascii PRIMARY KEY,
 attempts INT NOT NULL DEFAULT 0,
 expires_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
