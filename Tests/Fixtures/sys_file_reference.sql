INSERT INTO `sys_file_reference` (
		`uid`, `pid`, `tstamp`, `crdate`,
		`deleted`, `hidden`, `sorting_foreign`,
		`tablenames`, `fieldname`, `uid_foreign`,
		`uid_local`, `crop`,
		`title`, `description`, `alternative`
) VALUES
(1, 19, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(),
0, 0, 1,
'fe_users', 'image', 1,
4,
'', 'Profile picture of John Doe', 'John Doe in a professional setting', ''),
(2, 19, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(),
0, 0, 1,
'fe_users', 'image', 3,
5,
'', 'Profile picture of Alice Johnson', 'Dr. Alice Marie Johnson at a conference', ''),
(3, 19, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(),
0, 0, 1,
'fe_users', 'image', 4,
6,
'', 'Profile picture of Bob Brown', 'Bob William Brown during a business meeting', '');
