-- TYPO3 Frontend Users Test Fixture
-- Complete dataset with all fe_users fields populated

INSERT INTO `fe_users` (
		`uid`, `pid`, `tstamp`, `crdate`,
		`title`, `first_name`, `middle_name`, `last_name`, `name`,
		`username`, `password`, `email`,
		`address`, `zip`, `city`, `country`,
		`telephone`, `fax`, `www`,
		`company`, `description`,
		`usergroup`, `disable`, `starttime`, `endtime`, `lastlogin`,
		`image`, `tx_extbase_type`
) VALUES
-- Active users with full profiles
(1, 19, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(),
'Mr.', 'John', 'Michael', 'Doe', 'John Michael Doe',
'johndoe', '$2y$10$e0NRy5l8bG9QeFhZc2VhZ.5J8bG9QeFhZc2VhZ.5J8bG9QeFhZc2VhZ.', 'john.doe@example.com',
'123 Main Street', '10001', 'New York', 'USA',
'+1-212-555-0101', '+1-212-555-0102', 'https://johndoe.com',
'Acme Corporation', 'Senior Software Developer with 10 years of experience in web technologies.',
'1,2', 0, 0, 0, UNIX_TIMESTAMP() - 3600,
1, 'Tx_Extbase_Domain_Model_FrontendUser'),

(2, 19, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(),
'Ms.', 'Jane', 'Elizabeth', 'Smith', 'Jane Elizabeth Smith',
'janesmith', '$2y$10$e0NRy5l8bG9QeFhZc2VhZ.5J8bG9QeFhZc2VhZ.5J8bG9QeFhZc2VhZ.', 'jane.smith@example.com',
'456 Oak Avenue, Apt 3B', '90210', 'Los Angeles', 'USA',
'+1-310-555-0201', '', 'https://janesmith.net',
'Tech Innovations Ltd', 'Project Manager specializing in agile methodologies and team leadership.',
'2,4', 0, 0, 0, UNIX_TIMESTAMP() - 7200,
0, 'Tx_Extbase_Domain_Model_FrontendUser'),

(3, 19, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(),
'Dr.', 'Alice', 'Marie', 'Johnson', 'Dr. Alice Marie Johnson',
'alicejohnson', '$2y$10$e0NRy5l8bG9QeFhZc2VhZ.5J8bG9QeFhZc2VhZ.5J8bG9QeFhZc2VhZ.', 'alice.johnson@university.edu',
'789 University Drive', 'CB2 1TN', 'Cambridge', 'United Kingdom',
'+44-1223-555301', '+44-1223-555302', 'https://research.alicejohnson.ac.uk',
'Cambridge University', 'Research scientist focusing on artificial intelligence and machine learning applications.',
'3', 1, 0, 0, 0,
1, 'Tx_Extbase_Domain_Model_FrontendUser'),

(4, 19, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(),
'Mr.', 'Bob', 'William', 'Brown', 'Bob William Brown',
'bobbrown', '$2y$10$e0NRy5l8bG9QeFhZc2VhZ.5J8bG9QeFhZc2VhZ.5J8bG9QeFhZc2VhZ.', 'bob.brown@example.com',
'321 Elm Street', '60601', 'Chicago', 'USA',
'+1-312-555-0401', '+1-312-555-0402', '',
'Brown & Associates', 'Financial consultant with expertise in corporate finance and investment strategies.',
'4', 0, 0, 0, UNIX_TIMESTAMP() - 86400,
1, 'Tx_Extbase_Domain_Model_FrontendUser'),

-- User with start date (not yet active)
(5, 19, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(),
'Mrs.', 'Carol', 'Ann', 'Wilson', 'Carol Ann Wilson',
'carolwilson', '$2y$10$e0NRy5l8bG9QeFhZc2VhZ.5J8bG9QeFhZc2VhZ.5J8bG9QeFhZc2VhZ.', 'carol.wilson@example.com',
'654 Pine Road', '98101', 'Seattle', 'USA',
'+1-206-555-0501', '', 'https://carolwilson.blog',
'Digital Marketing Pro', 'Digital marketing specialist with focus on SEO, content strategy, and social media.',
'2,3', 0, UNIX_TIMESTAMP() + 86400, 0, 0,
0, 'Tx_Extbase_Domain_Model_FrontendUser'),

-- User with end date (expired)
(6, 19, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(),
'Prof.', 'David', 'James', 'Miller', 'Prof. David James Miller',
'davidmiller', '$2y$10$e0NRy5l8bG9QeFhZc2VhZ.5J8bG9QeFhZc2VhZ.5J8bG9QeFhZc2VhZ.', 'david.miller@example.org',
'987 College Lane', '02138', 'Boston', 'USA',
'+1-617-555-0601', '+1-617-555-0602', 'https://davidmiller.edu',
'MIT', 'Professor of Computer Science specializing in distributed systems and cloud computing.',
'1,3,4', 0, 0, UNIX_TIMESTAMP() - 86400, UNIX_TIMESTAMP() - 172800,
0, 'Tx_Extbase_Domain_Model_FrontendUser'),

-- International user (Germany)
(7, 19, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(),
'Herr', 'Hans', 'Friedrich', 'Schmidt', 'Hans Friedrich Schmidt',
'hansschmidt', '$2y$10$e0NRy5l8bG9QeFhZc2VhZ.5J8bG9QeFhZc2VhZ.5J8bG9QeFhZc2VhZ.', 'hans.schmidt@beispiel.de',
'Hauptstraße 42', '10115', 'Berlin', 'Germany',
'+49-30-555-0701', '+49-30-555-0702', 'https://schmidt-engineering.de',
'Schmidt Engineering GmbH', 'Mechanical engineer with 15 years experience in automotive industry.',
'1,2,3', 0, 0, 0, UNIX_TIMESTAMP() - 1800,
0, 'Tx_Extbase_Domain_Model_FrontendUser'),

-- User with minimal information
(8, 19, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(),
'', 'Emma', '', 'Davis', 'Emma Davis',
'emmadavis', '$2y$10$e0NRy5l8bG9QeFhZc2VhZ.5J8bG9QeFhZc2VhZ.5J8bG9QeFhZc2VhZ.', 'emma.davis@example.com',
'', '', '', '',
'', '', '',
'', 'Freelance designer and illustrator.',
'2', 0, 0, 0, UNIX_TIMESTAMP() - 14400,
0, 'Tx_Extbase_Domain_Model_FrontendUser'),

-- User from France
(9, 19, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(),
'Mme', 'Sophie', 'Marie', 'Dubois', 'Sophie Marie Dubois',
'sophiedubois', '$2y$10$e0NRy5l8bG9QeFhZc2VhZ.5J8bG9QeFhZc2VhZ.5J8bG9QeFhZc2VhZ.', 'sophie.dubois@exemple.fr',
'15 Rue de la Paix', '75001', 'Paris', 'France',
'+33-1-555-0901', '', 'https://sophiedubois.fr',
'Dubois Consulting', 'Business consultant specializing in international trade and market expansion.',
'3,4', 0, 0, 0, UNIX_TIMESTAMP() - 5400,
0, 'Tx_Extbase_Domain_Model_FrontendUser'),

-- User from Japan
(10, 19, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(),
'Mr.', 'Takeshi', '', 'Yamamoto', 'Takeshi Yamamoto',
'takeshiyamamoto', '$2y$10$e0NRy5l8bG9QeFhZc2VhZ.5J8bG9QeFhZc2VhZ.5J8bG9QeFhZc2VhZ.', 'takeshi.yamamoto@example.jp',
'3-2-1 Shibuya', '150-0002', 'Tokyo', 'Japan',
'+81-3-555-1001', '+81-3-555-1002', 'https://yamamoto-tech.jp',
'Yamamoto Technologies', 'Software architect specializing in enterprise applications and microservices.',
'1,4', 0, 0, 0, UNIX_TIMESTAMP() - 10800,
0, 'Tx_Extbase_Domain_Model_FrontendUser'),

-- Young user with social media focus
(11, 19, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(),
'', 'Alex', 'Jordan', 'Taylor', 'Alex Jordan Taylor',
'alextaylor', '$2y$10$e0NRy5l8bG9QeFhZc2VhZ.5J8bG9QeFhZc2VhZ.5J8bG9QeFhZc2VhZ.', 'alex.taylor@example.com',
'888 Social Boulevard', 'M1 1AE', 'Manchester', 'United Kingdom',
'+44-161-555-1101', '', 'https://alextaylor.social',
'Influencer Media', 'Content creator and social media strategist focusing on lifestyle and technology.',
'2', 0, 0, 0, UNIX_TIMESTAMP() - 900,
0, 'Tx_Extbase_Domain_Model_FrontendUser'),

-- Senior executive
(12, 19, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(),
'Mrs.', 'Margaret', 'Rose', 'Thompson', 'Margaret Rose Thompson',
'margaretthompson', '$2y$10$e0NRy5l8bG9QeFhZc2VhZ.5J8bG9QeFhZc2VhZ.5J8bG9QeFhZc2VhZ.', 'margaret.thompson@globalcorp.com',
'1 Executive Plaza, Suite 5000', '94105', 'San Francisco', 'USA',
'+1-415-555-1201', '+1-415-555-1202', 'https://margaretthompson.com',
'Global Corp International', 'Chief Technology Officer with 20+ years experience leading digital transformation initiatives.',
'1,2,3,4', 0, 0, 0, UNIX_TIMESTAMP() - 600,
0, 'Tx_Extbase_Domain_Model_FrontendUser');
