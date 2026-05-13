CREATE TABLE IF NOT EXISTS `copilot_attack_runs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `attack_category` VARCHAR(128) NOT NULL,
  `user_role` VARCHAR(64) NOT NULL,
  `patient_id` VARCHAR(64) NOT NULL,
  `workflow` VARCHAR(128) NOT NULL,
  `prompt` MEDIUMTEXT NOT NULL,
  `retrieved_context_ids` LONGTEXT NULL,
  `model_response` LONGTEXT NOT NULL,
  `judge_result` LONGTEXT NOT NULL,
  `severity` VARCHAR(32) NOT NULL,
  `violated_policy` VARCHAR(128) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_copilot_attack_runs_created_at` (`created_at`),
  KEY `idx_copilot_attack_runs_category_role` (`attack_category`, `user_role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `copilot_regression_cases` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source_attack_run_id` BIGINT UNSIGNED NULL,
  `eval_name` VARCHAR(190) NOT NULL,
  `prompt` MEDIUMTEXT NOT NULL,
  `expected_behavior` LONGTEXT NOT NULL,
  `role` VARCHAR(64) NOT NULL,
  `workflow` VARCHAR(128) NOT NULL,
  `must_block` TINYINT(1) NOT NULL DEFAULT 0,
  `must_include_sources` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_copilot_regression_cases_source` (`source_attack_run_id`),
  KEY `idx_copilot_regression_cases_eval` (`eval_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `copilot_guardrail_results` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` VARCHAR(128) NOT NULL,
  `stage` VARCHAR(32) NOT NULL,
  `passed` TINYINT(1) NOT NULL DEFAULT 0,
  `reason` LONGTEXT NOT NULL,
  `policy_code` VARCHAR(128) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_copilot_guardrail_results_request` (`request_id`),
  KEY `idx_copilot_guardrail_results_stage` (`stage`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
