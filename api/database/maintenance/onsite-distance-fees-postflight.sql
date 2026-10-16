-- Read-only; expect six OK rows after migration 044.
SELECT required.table_name,required.column_name,
       CASE WHEN c.column_name IS NULL THEN 'MISSING - STOP' ELSE 'OK' END result
FROM (SELECT 'practitioner_services' table_name,'mobile_free_radius_km' column_name
      UNION ALL SELECT 'appointments','mobile_distance_meters'
      UNION ALL SELECT 'appointments','mobile_assessed_fee_cents'
      UNION ALL SELECT 'appointments','mobile_fee_waived_by'
      UNION ALL SELECT 'appointments','mobile_fee_waived_at'
      UNION ALL SELECT 'appointments','mobile_fee_waiver_reason') required
LEFT JOIN information_schema.columns c ON c.table_schema='wellness_centre' AND c.table_name=required.table_name AND c.column_name=required.column_name;
