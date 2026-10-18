SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN (
 'discovery_settings','discovery_settings_events','discovery_preferences','discovery_geocodes',
 'discovery_distances','discovery_limits','travel_enquiries','travel_enquiry_messages') ORDER BY table_name;
SELECT extra_km,cap_km,revision FROM discovery_settings WHERE id=1;
