ALTER TABLE video DROP COLUMN full_path;

ALTER TABLE video DROP COLUMN web_path;

ALTER TABLE video DROP COLUMN full_path_mp4;

ALTER TABLE video DROP COLUMN web_path_mp4;

ALTER table video ADD column `user_id` int(11) DEFAULT 0;

ALTER table video ADD column `status` tinyint DEFAULT 0;