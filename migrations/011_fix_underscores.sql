UPDATE songs   SET title = TRIM(REGEXP_REPLACE(REPLACE(title, '_', ' '), '[[:space:]]+', ' '))   WHERE title LIKE '%\_%';
UPDATE artists SET name  = TRIM(REGEXP_REPLACE(REPLACE(name,  '_', ' '), '[[:space:]]+', ' '))   WHERE name  LIKE '%\_%';
UPDATE albums  SET name  = TRIM(REGEXP_REPLACE(REPLACE(name,  '_', ' '), '[[:space:]]+', ' '))   WHERE name  LIKE '%\_%';
