SELECT
  8 AS check_number,
  TABLE_NAME AS object_name,
  CASE
    WHEN ENGINE <> 'InnoDB' THEN 'ENGINE_ABWEICHUNG'
    WHEN TABLE_COLLATION NOT LIKE 'utf8mb4%' THEN 'COLLATION_ABWEICHUNG'
    ELSE 'VORHANDEN'
  END AS status
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'customers','repairs','companies','company_contacts','projects','tickets'
  )
ORDER BY TABLE_NAME
