-- phpMyAdmin SQL Dump
-- version 5.1.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3307
-- Generation Time: Nov 11, 2025 at 11:57 AM
-- Server version: 10.4.21-MariaDB
-- PHP Version: 8.0.10

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `dolate_eshgh`
--

-- --------------------------------------------------------

--
-- Table structure for table `communities`
--

CREATE TABLE `communities` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `approach` text NOT NULL,
  `description` text DEFAULT NULL,
  `creator_id` int(11) NOT NULL,
  `members_count` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `communities`
--

INSERT INTO `communities` (`id`, `name`, `approach`, `description`, `creator_id`, `members_count`, `created_at`) VALUES
(20, 'ایران', 'ایران یک کشور استن', NULL, 0, 1, '2025-11-11 10:43:39');

-- --------------------------------------------------------

--
-- Table structure for table `community_members`
--

CREATE TABLE `community_members` (
  `id` int(11) NOT NULL,
  `community_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `community_members`
--

INSERT INTO `community_members` (`id`, `community_id`, `user_id`, `joined_at`) VALUES
(63, 20, 15, '2025-11-11 10:43:48');

-- --------------------------------------------------------

--
-- Table structure for table `ideas`
--

CREATE TABLE `ideas` (
  `id` int(11) NOT NULL,
  `topic_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `content` text NOT NULL,
  `idea_rationality` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `ideas`
--

INSERT INTO `ideas` (`id`, `topic_id`, `user_id`, `content`, `idea_rationality`, `created_at`) VALUES
(67, 91, 15, 'تعریف معروف: فقه در اصطلاح عبارت است از: روشی که به‌وسیلهٔ آن احکام شرعی را از ادلهٔ تفصیلی به دست می‌آوریم. «دلایل تفصیلی» از نظر علمای مسلمان سنی عبارتند از: قرآن، سنت، اجماع و قیاس و در شیعه عبارتند از:', 1, '2025-11-11 10:48:10');

-- --------------------------------------------------------

--
-- Table structure for table `idea_votes`
--

CREATE TABLE `idea_votes` (
  `id` int(11) NOT NULL,
  `idea_id` int(11) NOT NULL,
  `topic_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `idea_votes`
--

INSERT INTO `idea_votes` (`id`, `idea_id`, `topic_id`, `user_id`, `created_at`) VALUES
(58, 67, 91, 15, '2025-11-11 10:48:43');

-- --------------------------------------------------------

--
-- Table structure for table `topics`
--

CREATE TABLE `topics` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `community_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `priority` int(11) NOT NULL,
  `urgency` int(11) NOT NULL,
  `deadline` datetime DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `reasonability` int(11) NOT NULL DEFAULT 0,
  `rationality` int(11) DEFAULT 0,
  `closed` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `topics`
--

INSERT INTO `topics` (`id`, `title`, `description`, `community_id`, `created_at`, `priority`, `urgency`, `deadline`, `user_id`, `reasonability`, `rationality`, `closed`) VALUES
(91, 'شکایت از رودخانه', '', 20, '2025-11-10 10:44:28', 1, 4, '2025-11-11 11:52:40', 15, 0, 0, 1);

-- --------------------------------------------------------

--
-- Table structure for table `topic_final_votes`
--

CREATE TABLE `topic_final_votes` (
  `id` int(11) NOT NULL,
  `topic_id` int(11) NOT NULL,
  `idea_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `vote_value` enum('yes','no') DEFAULT NULL,
  `urgency_days` int(11) DEFAULT 5,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `topic_final_votes`
--

INSERT INTO `topic_final_votes` (`id`, `topic_id`, `idea_id`, `user_id`, `vote_value`, `urgency_days`, `created_at`) VALUES
(34, 91, 67, 0, NULL, 5, '2025-11-11 10:50:28');

-- --------------------------------------------------------

--
-- Table structure for table `topic_priority_votes`
--

CREATE TABLE `topic_priority_votes` (
  `id` int(11) NOT NULL,
  `topic_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `topic_priority_votes`
--

INSERT INTO `topic_priority_votes` (`id`, `topic_id`, `user_id`, `created_at`) VALUES
(44, 91, 15, '2025-11-11 10:48:17');

-- --------------------------------------------------------

--
-- Table structure for table `topic_urgency_votes`
--

CREATE TABLE `topic_urgency_votes` (
  `id` int(11) NOT NULL,
  `topic_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `urgency_days` int(11) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `topic_urgency_votes`
--

INSERT INTO `topic_urgency_votes` (`id`, `topic_id`, `user_id`, `urgency_days`, `created_at`) VALUES
(9, 91, 15, 1, '2025-11-11 14:18:30');

-- --------------------------------------------------------

--
-- Table structure for table `topic_votes`
--

CREATE TABLE `topic_votes` (
  `id` int(11) NOT NULL,
  `topic_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `vote` enum('yes','no') NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `topic_voting_ballots`
--

CREATE TABLE `topic_voting_ballots` (
  `id` int(11) NOT NULL,
  `topic_id` int(11) NOT NULL,
  `idea_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `vote_type` enum('yes','no') DEFAULT 'yes',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `topic_voting_ballots`
--

INSERT INTO `topic_voting_ballots` (`id`, `topic_id`, `idea_id`, `user_id`, `vote_type`, `created_at`) VALUES
(55, 91, 67, 15, 'yes', '2025-11-11 14:20:44');

-- --------------------------------------------------------

--
-- Table structure for table `topic_voting_meta`
--

CREATE TABLE `topic_voting_meta` (
  `topic_id` int(11) NOT NULL,
  `urgency_days` int(11) NOT NULL DEFAULT 5,
  `voting_started` datetime DEFAULT NULL,
  `finalized` tinyint(1) NOT NULL DEFAULT 0,
  `finalized_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `topic_voting_meta`
--

INSERT INTO `topic_voting_meta` (`topic_id`, `urgency_days`, `voting_started`, `finalized`, `finalized_at`) VALUES
(91, 1, '2025-11-11 14:18:30', 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `topic_winners`
--

CREATE TABLE `topic_winners` (
  `id` int(11) NOT NULL,
  `topic_id` int(11) NOT NULL,
  `idea_id` int(11) NOT NULL,
  `percent` float NOT NULL,
  `total_votes` int(11) NOT NULL,
  `decided_at` datetime DEFAULT current_timestamp(),
  `enforceable` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `national_id` varchar(20) NOT NULL,
  `password` varchar(255) NOT NULL,
  `bio` text DEFAULT NULL,
  `community_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `phone`, `national_id`, `password`, `bio`, `community_id`, `created_at`) VALUES
(15, 'یوسف حاجی نیا', '09373474772', '1378205308', '$2y$10$dlGv6bVVpC/RwwcGkcZiu.8pl38wMhTJ.tlqwUWZp0FMCGTLB1rNu', NULL, NULL, '2025-11-11 10:42:44');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `communities`
--
ALTER TABLE `communities`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `community_members`
--
ALTER TABLE `community_members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_member` (`community_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `ideas`
--
ALTER TABLE `ideas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `topic_id` (`topic_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `idea_votes`
--
ALTER TABLE `idea_votes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`,`topic_id`),
  ADD KEY `idea_id` (`idea_id`),
  ADD KEY `topic_id` (`topic_id`);

--
-- Indexes for table `topics`
--
ALTER TABLE `topics`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `topic_final_votes`
--
ALTER TABLE `topic_final_votes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_topic` (`topic_id`),
  ADD KEY `idea_id` (`idea_id`);

--
-- Indexes for table `topic_priority_votes`
--
ALTER TABLE `topic_priority_votes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `topic_id` (`topic_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `topic_urgency_votes`
--
ALTER TABLE `topic_urgency_votes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_topic_user` (`topic_id`,`user_id`),
  ADD KEY `idx_topic_id` (`topic_id`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `topic_votes`
--
ALTER TABLE `topic_votes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_vote` (`topic_id`,`user_id`);

--
-- Indexes for table `topic_voting_ballots`
--
ALTER TABLE `topic_voting_ballots`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_vote` (`topic_id`,`idea_id`,`user_id`);

--
-- Indexes for table `topic_voting_meta`
--
ALTER TABLE `topic_voting_meta`
  ADD PRIMARY KEY (`topic_id`);

--
-- Indexes for table `topic_winners`
--
ALTER TABLE `topic_winners`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `phone` (`phone`),
  ADD UNIQUE KEY `national_id` (`national_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `communities`
--
ALTER TABLE `communities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `community_members`
--
ALTER TABLE `community_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=64;

--
-- AUTO_INCREMENT for table `ideas`
--
ALTER TABLE `ideas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=68;

--
-- AUTO_INCREMENT for table `idea_votes`
--
ALTER TABLE `idea_votes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=59;

--
-- AUTO_INCREMENT for table `topics`
--
ALTER TABLE `topics`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=92;

--
-- AUTO_INCREMENT for table `topic_final_votes`
--
ALTER TABLE `topic_final_votes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `topic_priority_votes`
--
ALTER TABLE `topic_priority_votes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT for table `topic_urgency_votes`
--
ALTER TABLE `topic_urgency_votes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `topic_votes`
--
ALTER TABLE `topic_votes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `topic_voting_ballots`
--
ALTER TABLE `topic_voting_ballots`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=58;

--
-- AUTO_INCREMENT for table `topic_winners`
--
ALTER TABLE `topic_winners`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `community_members`
--
ALTER TABLE `community_members`
  ADD CONSTRAINT `community_members_ibfk_1` FOREIGN KEY (`community_id`) REFERENCES `communities` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `community_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ideas`
--
ALTER TABLE `ideas`
  ADD CONSTRAINT `ideas_ibfk_1` FOREIGN KEY (`topic_id`) REFERENCES `topics` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ideas_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `idea_votes`
--
ALTER TABLE `idea_votes`
  ADD CONSTRAINT `idea_votes_ibfk_1` FOREIGN KEY (`idea_id`) REFERENCES `ideas` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `idea_votes_ibfk_2` FOREIGN KEY (`topic_id`) REFERENCES `topics` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `idea_votes_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `topic_final_votes`
--
ALTER TABLE `topic_final_votes`
  ADD CONSTRAINT `topic_final_votes_ibfk_1` FOREIGN KEY (`topic_id`) REFERENCES `topics` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `topic_final_votes_ibfk_2` FOREIGN KEY (`idea_id`) REFERENCES `ideas` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `topic_priority_votes`
--
ALTER TABLE `topic_priority_votes`
  ADD CONSTRAINT `topic_priority_votes_ibfk_1` FOREIGN KEY (`topic_id`) REFERENCES `topics` (`id`),
  ADD CONSTRAINT `topic_priority_votes_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);
-- Separate urgency votes for the final voting period (not discussion-phase urgency).
CREATE TABLE IF NOT EXISTS `topic_voting_urgency_votes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `topic_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `urgency_days` int(11) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_voting_topic_user` (`topic_id`,`user_id`),
  KEY `idx_topic_id` (`topic_id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
