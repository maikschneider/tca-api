INSERT INTO `sys_category` (`uid`, `pid`, `tstamp`, `crdate`, `sorting`, `sys_language_uid`, `l10n_parent`, `title`, `description`, `parent`) VALUES
-- Main categories (Level 1)
(1, 17, 0, 0, 10, 0, 0, 'Politics', 'News from politics', 0),
(2, 17, 0, 0, 20, 0, 0, 'Economy', 'News from the economy', 0),
(3, 17, 0, 0, 30, 0, 0, 'Sports', 'News from sports', 0),
(4, 17, 0, 0, 40, 0, 0, 'Culture', 'News from culture', 0),
(5, 17, 0, 0, 50, 0, 0, 'Science', 'News from science', 0),

-- Subcategories of Politics
(6, 17, 0, 0, 11, 0, 0, 'Domestic Policy', '', 1),
(7, 17, 0, 0, 12, 0, 0, 'Foreign Policy', '', 1),
(8, 17, 0, 0, 13, 0, 0, 'Parties', '', 1),

-- Subcategories of Economy
(9, 17, 0, 0, 21, 0, 0, 'Finance', '', 2),
(10, 17, 0, 0, 22, 0, 0, 'Companies', '', 2),
(11, 17, 0, 0, 23, 0, 0, 'Labor Market', '', 2),

-- Subcategories of Sports
(12, 17, 0, 0, 31, 0, 0, 'Football', '', 3),
(13, 17, 0, 0, 32, 0, 0, 'Handball', '', 3),
(14, 17, 0, 0, 33, 0, 0, 'Olympics', '', 3),

-- Subcategories of Culture
(15, 17, 0, 0, 41, 0, 0, 'Music', '', 4),
(16, 17, 0, 0, 42, 0, 0, 'Film', '', 4),
(17, 17, 0, 0, 43, 0, 0, 'Literature', '', 4),

-- Subcategories of Science
(18, 17, 0, 0, 51, 0, 0, 'Technology', '', 5),
(19, 17, 0, 0, 52, 0, 0, 'Medicine', '', 5),
(20, 17, 0, 0, 53, 0, 0, 'Environment', '', 5),

-- Level 3: Subcategories of Domestic Policy
(21, 17, 0, 0, 111, 0, 0, 'Laws', '', 6),
(22, 17, 0, 0, 112, 0, 0, 'Government', '', 6),
(23, 17, 0, 0, 113, 0, 0, 'Opposition', '', 6),

-- Level 3: Subcategories of Foreign Policy
(24, 17, 0, 0, 121, 0, 0, 'EU', '', 7),
(25, 17, 0, 0, 122, 0, 0, 'UNO', '', 7),
(26, 17, 0, 0, 123, 0, 0, 'Diplomacy', '', 7),

-- Level 3: Subcategories of Finance
(27, 17, 0, 0, 211, 0, 0, 'Stock Market', '', 9),
(28, 17, 0, 0, 212, 0, 0, 'Taxes', '', 9),
(29, 17, 0, 0, 213, 0, 0, 'Banks', '', 9),

-- Level 3: Subcategories of Companies
(30, 17, 0, 0, 221, 0, 0, 'Startups', '', 10),
(31, 17, 0, 0, 222, 0, 0, 'Corporations', '', 10),
(32, 17, 0, 0, 223, 0, 0, 'Medium-sized Businesses', '', 10),

-- Level 3: Subcategories of Football
(33, 17, 0, 0, 311, 0, 0, 'Bundesliga', '', 12),
(34, 17, 0, 0, 312, 0, 0, 'National Team', '', 12),
(35, 17, 0, 0, 313, 0, 0, 'Champions League', '', 12),

-- Level 3: Subcategories of Music
(36, 17, 0, 0, 411, 0, 0, 'Pop', '', 15),
(37, 17, 0, 0, 412, 0, 0, 'Rock', '', 15),
(38, 17, 0, 0, 413, 0, 0, 'Classical', '', 15),

-- Level 3: Subcategories of Technology
(39, 17, 0, 0, 511, 0, 0, 'IT', '', 18),
(40, 17, 0, 0, 512, 0, 0, 'Mechanical Engineering', '', 18),
(41, 17, 0, 0, 513, 0, 0, 'Electrical Engineering', '', 18),

-- Other categories (Level 2/3, randomly distributed)
(42, 17, 0, 0, 999, 0, 0, 'Energy', '', 20),
(43, 17, 0, 0, 999, 0, 0, 'Renewables', '', 20),
(44, 17, 0, 0, 999, 0, 0, 'Research', '', 5),
(45, 17, 0, 0, 999, 0, 0, 'Education', '', 5),
(46, 17, 0, 0, 999, 0, 0, 'Health', '', 19),
(47, 17, 0, 0, 999, 0, 0, 'Pharma', '', 19),
(48, 17, 0, 0, 999, 0, 0, 'Environmental Protection', '', 20),
(49, 17, 0, 0, 999, 0, 0, 'Climate Change', '', 20),
(50, 17, 0, 0, 999, 0, 0, 'Innovation', '', 18),

(51, 17, 0, 0, 10, 1, 1, 'Politik', 'Nachrichten aus der Politik', 0),
(52, 17, 0, 0, 20, 1, 2, 'Wirtschaft', 'Nachrichten aus der Wirtschaft', 0),
(53, 17, 0, 0, 30, 1, 3, 'Sport', 'Nachrichten aus dem Sport', 0),
(54, 17, 0, 0, 40, 1, 4, 'Kultur', 'Nachrichten aus der Kultur', 0),
(55, 17, 0, 0, 50, 1, 5, 'Wissenschaft', 'Nachrichten aus der Wissenschaft', 0),

(56, 17, 0, 0, 11, 1, 6, 'Innenpolitik', '', 51),
(57, 17, 0, 0, 12, 1, 7, 'Außenpolitik', '', 51),
(58, 17, 0, 0, 13, 1, 8, 'Parteien', '', 51),

(59, 17, 0, 0, 21, 1, 9, 'Finanzen', '', 52),
(60, 17, 0, 0, 22, 1, 10, 'Unternehmen', '', 52),
(61, 17, 0, 0, 23, 1, 11, 'Arbeitsmarkt', '', 52),

(62, 17, 0, 0, 31, 1, 12, 'Fußball', '', 53),
(63, 17, 0, 0, 32, 1, 13, 'Handball', '', 53),
(64, 17, 0, 0, 33, 1, 14, 'Olympia', '', 53),

(65, 17, 0, 0, 41, 1, 15, 'Musik', '', 54),
(66, 17, 0, 0, 42, 1, 16, 'Film', '', 54),
(67, 17, 0, 0, 43, 1, 17, 'Literatur', '', 54),

(68, 17, 0, 0, 51, 1, 18, 'Technik', '', 55),
(69, 17, 0, 0, 52, 1, 19, 'Medizin', '', 55),
(70, 17, 0, 0, 53, 1, 20, 'Umwelt', '', 55),

(71, 17, 0, 0, 111, 1, 21, 'Gesetze', '', 56),
(72, 17, 0, 0, 112, 1, 22, 'Regierung', '', 56),
(73, 17, 0, 0, 113, 1, 23, 'Opposition', '', 56),

(74, 17, 0, 0, 121, 1, 24, 'EU', '', 57),
(75, 17, 0, 0, 122, 1, 25, 'UNO', '', 57),
(76, 17, 0, 0, 123, 1, 26, 'Diplomatie', '', 57),

(77, 17, 0, 0, 211, 1, 27, 'Börse', '', 59),
(78, 17, 0, 0, 212, 1, 28, 'Steuern', '', 59),
(79, 17, 0, 0, 213, 1, 29, 'Banken', '', 59),

(80, 17, 0, 0, 221, 1, 30, 'Startups', '', 60),
(81, 17, 0, 0, 222, 1, 31, 'Konzerne', '', 60),
(82, 17, 0, 0, 223, 1, 32, 'Mittelstand', '', 60),

(83, 17, 0, 0, 311, 1, 33, 'Bundesliga', '', 62),
(84, 17, 0, 0, 312, 1, 34, 'Nationalmannschaft', '', 62),
(85, 17, 0, 0, 313, 1, 35, 'Champions League', '', 62),

(86, 17, 0, 0, 411, 1, 36, 'Pop', '', 65),
(87, 17, 0, 0, 412, 1, 37, 'Rock', '', 65),
(88, 17, 0, 0, 413, 1, 38, 'Klassik', '', 65),

(89, 17, 0, 0, 511, 1, 39, 'IT', '', 68),
(90, 17, 0, 0, 512, 1, 40, 'Maschinenbau', '', 68),
(91, 17, 0, 0, 513, 1, 41, 'Elektrotechnik', '', 68),

(92, 17, 0, 0, 999, 1, 42, 'Energie', '', 70),
(93, 17, 0, 0, 999, 1, 43, 'Erneuerbare', '', 70),
(94, 17, 0, 0, 999, 1, 44, 'Forschung', '', 55),
(95, 17, 0, 0, 999, 1, 45, 'Bildung', '', 55),
(96, 17, 0, 0, 999, 1, 46, 'Gesundheit', '', 69),
(97, 17, 0, 0, 999, 1, 47, 'Pharma', '', 69),
(98, 17, 0, 0, 999, 1, 48, 'Umweltschutz', '', 70),
(99, 17, 0, 0, 999, 1, 49, 'Klimawandel', '', 70),
(100, 17, 0, 0, 999, 1, 50, 'Innovation', '', 68);
