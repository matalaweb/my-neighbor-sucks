-- Separate database for the automated MySQL integration test suite.
CREATE DATABASE IF NOT EXISTS noise_monitor_test;
GRANT ALL PRIVILEGES ON noise_monitor_test.* TO 'noise'@'%';
FLUSH PRIVILEGES;
-- Additional isolated databases so parallel test runs do not collide.
CREATE DATABASE IF NOT EXISTS noise_monitor_test_a;
CREATE DATABASE IF NOT EXISTS noise_monitor_test_b;
CREATE DATABASE IF NOT EXISTS noise_monitor_test_c;
GRANT ALL PRIVILEGES ON `noise_monitor_test%`.* TO 'noise'@'%';
FLUSH PRIVILEGES;
