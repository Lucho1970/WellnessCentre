USE wellness_centre;
INSERT INTO clinics(name,legal_name,email,phone) VALUES('Willow Wellness Centre','Willow Wellness Centre Inc.','hello@example.test','+1-416-555-0100');
SET @clinic_id=LAST_INSERT_ID();
INSERT INTO locations(clinic_id,name,timezone,address_line1,city,province,postal_code) VALUES(@clinic_id,'Toronto Clinic','America/Toronto','240 Queen Street West','Toronto','Ontario','M5V 1Z7');
SET @location_id=LAST_INSERT_ID();
INSERT INTO service_categories(clinic_id,name,description) VALUES
(@clinic_id,'Massage Therapy','Registered massage therapy services'),
(@clinic_id,'Nutrition','Nutrition consultations and follow-ups'),
(@clinic_id,'Naturopathic Care','Naturopathic assessments and follow-ups');
INSERT INTO rooms(location_id,name,room_type,turnover_minutes) VALUES
(@location_id,'Cedar','Treatment',15),(@location_id,'Birch','Consultation',15);
INSERT INTO reminder_schedules(clinic_id,minutes_before,channel) VALUES
(@clinic_id,4320,'email'),(@clinic_id,1440,'email');
INSERT INTO taxes(clinic_id,code,name,rate_basis_points) VALUES(@clinic_id,'HST_ON','Ontario HST',1300);
