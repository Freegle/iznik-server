DELIMITER $$
DELIMITER $$
CREATE DEFINER=`root`@`localhost` FUNCTION `GetMaxDimension`(`g` GEOMETRY) RETURNS double
NO SQL
DETERMINISTIC
  BEGIN
    DECLARE area, radius, diag DOUBLE;

    IF ST_Dimension(g) > 1 THEN
        SET area = ST_AREA(g);
        SET radius = SQRT(area / PI());
        SET diag = SQRT(radius * radius * 2);
        RETURN(diag);
    ELSE
        RETURN 0;
    END IF;
  END$$
DELIMITER ;

DELIMITER $$
CREATE DEFINER=`root`@`localhost` FUNCTION `GetMaxDimensionT`(`g` GEOMETRY) RETURNS double
NO SQL
  BEGIN
    DECLARE area, radius, diag DOUBLE;

    IF ST_Dimension(g) > 1 THEN
        SET area = ST_AREA(g);
        SET radius = SQRT(area / PI());
        SET diag = SQRT(radius * radius * 2);
        RETURN(diag);
    ELSE
        RETURN 0;
    END IF;
  END$$
DELIMITER ;

DELIMITER $$
CREATE DEFINER=`root`@`localhost` FUNCTION `haversine`(
  lat1 FLOAT, lon1 FLOAT,
  lat2 FLOAT, lon2 FLOAT
) RETURNS float
NO SQL
DETERMINISTIC
  COMMENT 'Returns the distance in degrees on the Earth\n             between two known points of latitude and longitude'
  BEGIN
    RETURN 69 * DEGREES(ACOS(
                            COS(RADIANS(lat1)) *
                            COS(RADIANS(lat2)) *
                            COS(RADIANS(lon2) - RADIANS(lon1)) +
                            SIN(RADIANS(lat1)) * SIN(RADIANS(lat2))
                        ));
  END$$
DELIMITER ;
