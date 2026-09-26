INSERT INTO settings (setting_key, setting_value, description) VALUES
('sms_application_completion_template', 'Dear {{first_name}}, your {{app_name}} application has been completed successfully after referee confirmation. We will notify you when it is reviewed.', 'Application completion SMS. Placeholders: {{first_name}}, {{app_name}}.'),
('sms_admission_template', 'Dear {{first_name}}, congratulations! You have been admitted to {{app_name}} (Set {{set_number}}, Reg. No. {{reg_no}}). Further details will follow. Welcome!', 'Admission SMS. Placeholders: {{first_name}}, {{app_name}}, {{set_number}}, {{reg_no}}.'),
('sms_withdrawal_template', 'Dear {{full_name}}, you have been marked as WITHDRAWN from {{app_name}} ({{zone_name}}). We wish you the best.', 'Withdrawal SMS. Placeholders: {{full_name}}, {{app_name}}, {{zone_name}}.')
ON DUPLICATE KEY UPDATE
    setting_key = setting_key;