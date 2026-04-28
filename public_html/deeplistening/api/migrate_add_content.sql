-- Миграция: добавление колонки content для хранения данных практик с гибкой схемой
-- Запустить один раз через phpMyAdmin
-- После этой миграции новые типы практик не требуют изменений схемы БД

ALTER TABLE entries
    ADD COLUMN content JSON NULL AFTER notes;
