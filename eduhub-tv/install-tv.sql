-- Execute no banco: robsonga_dbinclusao (banco principal do WP)
-- ou no banco onde o plugin EduHub Turmas criou as tabelas

CREATE TABLE IF NOT EXISTS `ehtm_tv_playlist` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sort_order`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `type`         ENUM('material','youtube','url') NOT NULL DEFAULT 'material',
  `material_id`  INT UNSIGNED NULL COMMENT 'FK eduhub_materials.id (se type=material)',
  `youtube_id`   VARCHAR(20)  NULL COMMENT 'ID do vídeo YouTube (se type=youtube)',
  `external_url` VARCHAR(512) NULL COMMENT 'URL externa de vídeo (se type=url)',
  `title`        VARCHAR(255) NOT NULL,
  `description`  TEXT NULL,
  `thumbnail`    VARCHAR(512) NULL,
  `duration`     INT UNSIGNED NULL COMMENT 'Duração em segundos',
  `active`       TINYINT(1) NOT NULL DEFAULT 1,
  `added_by`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sort`   (`sort_order`),
  KEY `idx_active` (`active`),
  KEY `idx_type`   (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
