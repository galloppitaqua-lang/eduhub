-- EduHub TV v2.1 — execute no banco principal do WordPress
-- Fuso horário de referência: America/Sao_Paulo

-- Tabela principal da playlist
CREATE TABLE IF NOT EXISTS `ehtm_tv_playlist` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sort_order`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `type`           ENUM('material','youtube','url') NOT NULL DEFAULT 'material',
  `material_id`    INT UNSIGNED NULL,
  `youtube_id`     VARCHAR(20)  NULL,
  `external_url`   VARCHAR(512) NULL,
  `title`          VARCHAR(255) NOT NULL,
  `description`    TEXT NULL                COMMENT 'Mini descrição exibida no player',
  `tema`           VARCHAR(255) NULL        COMMENT 'Tema/assunto do conteúdo',
  `classification` VARCHAR(30)  NOT NULL DEFAULT 'conteudo'
                                           COMMENT 'conteudo | propaganda_externa | propaganda_institucional',
  `thumbnail`      VARCHAR(512) NULL,
  `duration`       INT UNSIGNED NULL        COMMENT 'Duração em segundos',
  `active`         TINYINT(1) NOT NULL DEFAULT 1,
  `added_by`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sort`   (`sort_order`),
  KEY `idx_active` (`active`),
  KEY `idx_type`   (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Agendamentos por data e horário (fuso SP)
-- Permite criar a grade dia a dia
CREATE TABLE IF NOT EXISTS `ehtm_tv_schedule` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `playlist_id`   INT UNSIGNED NOT NULL   COMMENT 'FK ehtm_tv_playlist.id',
  `schedule_date` DATE NOT NULL           COMMENT 'Data de exibição (America/Sao_Paulo)',
  `start_time`    TIME NOT NULL           COMMENT 'Hora de início (America/Sao_Paulo)',
  `active`        TINYINT(1) NOT NULL DEFAULT 1,
  `created_by`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unq_date_time` (`schedule_date`, `start_time`),
  KEY `idx_date`     (`schedule_date`),
  KEY `idx_playlist` (`playlist_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audiência / analytics
CREATE TABLE IF NOT EXISTS `ehtm_tv_views` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `playlist_id`    INT UNSIGNED NOT NULL,
  `session_key`    VARCHAR(64) NOT NULL    COMMENT 'ID anônimo do espectador (localStorage)',
  `started_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_heartbeat` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Atualizado a cada 25s',
  `ended_at`       DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_playlist`  (`playlist_id`),
  KEY `idx_heartbeat` (`last_heartbeat`),
  KEY `idx_session`   (`session_key`),
  KEY `idx_started`   (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Migração v1→v2.1 ──────────────────────────────────────────
-- ALTER TABLE `ehtm_tv_playlist`
--   ADD COLUMN `tema`           VARCHAR(255) NULL        AFTER `description`,
--   ADD COLUMN `classification` VARCHAR(30)  NOT NULL DEFAULT 'conteudo' AFTER `tema`,
--   DROP COLUMN `scheduled_time`;
-- (O plugin executa isso automaticamente via ehtv_migrate_tables())
