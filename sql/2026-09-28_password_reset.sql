ALTER TABLE `Accounts` ADD COLUMN `session_version` INT NOT NULL DEFAULT 0 AFTER `must_change_password`;

CREATE TABLE IF NOT EXISTS `Password_reset` (
  `reset_id` INT NOT NULL AUTO_INCREMENT,
  `account_id` INT NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`reset_id`),
  UNIQUE KEY `token_hash_UNIQUE` (`token_hash`),
  KEY `account_id_created_at_idx` (`account_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `Password_reset` ADD CONSTRAINT `fk_password_reset_account_id` FOREIGN KEY (`account_id`) REFERENCES `Accounts` (`account_id`) ON DELETE CASCADE ON UPDATE CASCADE;
