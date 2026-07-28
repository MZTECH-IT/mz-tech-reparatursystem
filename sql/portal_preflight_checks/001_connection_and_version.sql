SELECT
  1 AS check_number,
  DATABASE() AS active_schema,
  VERSION() AS database_version,
  @@version_comment AS database_comment
