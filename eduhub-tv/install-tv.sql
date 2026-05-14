-- EduHub TV v2.0 — execute no banco principal do WordPress
-- Ou use o hook de ativação do plugin (ehtv_create_tables)

CREATE TABLE IF NOT EXISTS `ehtm_tv_playlist` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sort_order`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `type`           ENUM('material','youtube','url') NOT NULL DEFAULT 'material',
  `material_id`    INT UNSIGNED NULL     COMMENT 'FK eduhub_materials.id',
  `youtube_id`     VARCHAR(20)  NULL     COMMENT 'ID do vídeo YouTube',
  `external_url`   VARCHAR(512) NULL     COMMENT 'URL externa de vídeo MP4',
  `title`          VARCHAR(255) NOT NULL,
  `description`    TEXT NULL             COMMENT 'Mini descrição exibida no player',
  `tema`           VARCHAR(255) NULL     COMMENT 'Tema/assunto do conteúdo',
  `classification` VARCHAR(30)  NOT NULL DEFAULT 'conteudo'
                                         COMMENT 'conteudo | propaganda_externa | propaganda_institucional',
  `scheduled_time` TIME NULL             COMMENT 'Horário fixo na grade (NULL = livre)',
  `thumbnail`      VARCHAR(512) NULL,
  `duration`       INT UNSIGNED NULL     COMMENT 'Duração em segundos',
  `active`         TINYINT(1) NOT NULL DEFAULT 1,
  `added_by`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sort`   (`sort_order`),
  KEY `idx_active` (`active`),
  KEY `idx_type`   (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migração para instalações existentes (v1 → v2)
-- ALTER TABLE `ehtm_tv_playlist`
--   ADD COLUMN `tema`           VARCHAR(255) NULL     AFTER `description`,
--   ADD COLUMN `classification` VARCHAR(30)  NOT NULL DEFAULT 'conteudo' AFTER `tema`,
--   ADD COLUMN `scheduled_time` TIME NULL             AFTER `classification`;

CREATE TABLE IF NOT EXISTS `ehtm_tv_views` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `playlist_id`    INT UNSIGNED NOT NULL,
  `session_key`    VARCHAR(64) NOT NULL    COMMENT 'ID anônimo de sessão do espectador',
  `started_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_heartbeat` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Atualizado a cada 25s',
  `ended_at`       DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_playlist`  (`playlist_id`),
  KEY `idx_heartbeat` (`last_heartbeat`),
  KEY `idx_session`   (`session_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
