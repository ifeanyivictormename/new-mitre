UPDATE settings
SET setting_value = 'Your MITRE application was received successfully. You will be contacted for further information. More of God''s blessings.',
    description = 'Application completion SMS.'
WHERE setting_key = 'sms_application_completion_template';

INSERT INTO settings (setting_key, setting_value, description)
SELECT 'sms_application_completion_template',
       'Your MITRE application was received successfully. You will be contacted for further information. More of God''s blessings.',
       'Application completion SMS.'
WHERE NOT EXISTS (
    SELECT 1 FROM settings WHERE setting_key = 'sms_application_completion_template'
);