-- From https://stackoverflow.com/questions/634995/implementation-of-levenshtein-distance-for-mysql-fuzzy-search .
-- On live servers we use the C UDF but this is used in Circle where it's hard to install on the mysql image.
DELIMITER $$
CREATE FUNCTION damlevlim( s1 VARCHAR(255), s2 VARCHAR(255), n INT)
    RETURNS INT
    DETERMINISTIC
BEGIN
    DECLARE s1_len, s2_len, i, j, c, c_temp, cost, c_min INT;
    DECLARE s1_char CHAR;
    -- max strlen=255 
    DECLARE cv0, cv1 VARBINARY(256);
    SET s1_len = CHAR_LENGTH(s1), s2_len = CHAR_LENGTH(s2), cv1 = 0x00, j = 1, i = 1, c = 0, c_min = 0;
    IF s1 = s2 THEN
        RETURN 0;
    ELSEIF s1_len = 0 THEN
        RETURN s2_len;
    ELSEIF s2_len = 0 THEN
        RETURN s1_len;
    ELSE
        WHILE j <= s2_len DO
                SET cv1 = CONCAT(cv1, UNHEX(HEX(j))), j = j + 1;
            END WHILE;
        WHILE i <= s1_len and c_min < n DO -- if actual levenshtein dist >= limit, don't bother computing it
        SET s1_char = SUBSTRING(s1, i, 1), c = i, c_min = i, cv0 = UNHEX(HEX(i)), j = 1;
        WHILE j <= s2_len DO
                SET c = c + 1;
                IF s1_char = SUBSTRING(s2, j, 1) THEN
                    SET cost = 0; ELSE SET cost = 1;
                END IF;
                SET c_temp = CONV(HEX(SUBSTRING(cv1, j, 1)), 16, 10) + cost;
                IF c > c_temp THEN SET c = c_temp; END IF;
                SET c_temp = CONV(HEX(SUBSTRING(cv1, j+1, 1)), 16, 10) + 1;
                IF c > c_temp THEN
                    SET c = c_temp;
                END IF;
                SET cv0 = CONCAT(cv0, UNHEX(HEX(c))), j = j + 1;
                IF c < c_min THEN
                    SET c_min = c;
                END IF;
            END WHILE;
        SET cv1 = cv0, i = i + 1;
            END WHILE;
    END IF;
    IF i <= s1_len THEN -- we didn't finish, limit exceeded    
        SET c = c_min; -- actual distance is >= c_min (i.e., the smallest value in the last computed row of the matrix)
    END IF;
    RETURN c;
END$$