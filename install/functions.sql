DELIMITER $$
CREATE DEFINER=`root`@`localhost` FUNCTION `GetCenterPoint`(`g` GEOMETRY) RETURNS point
NO SQL
DETERMINISTIC
  BEGIN
    DECLARE envelope POLYGON;
    DECLARE sw, ne POINT;
    DECLARE lat, lng DOUBLE;

    SET envelope = ExteriorRing(Envelope(g));
    SET sw = PointN(envelope, 1);
    SET ne = PointN(envelope, 3);
    SET lat = X(sw) + (X(ne)-X(sw))/2;
    SET lng = Y(sw) + (Y(ne)-Y(sw))/2;
    RETURN POINT(lat, lng);
  END$$
DELIMITER ;

DELIMITER $$
CREATE DEFINER=`root`@`localhost` FUNCTION `GetMaxDimension`(`g` GEOMETRY) RETURNS double
NO SQL
DETERMINISTIC
  BEGIN
    DECLARE area, radius, diag DOUBLE;

    SET area = AREA(g);
    SET radius = SQRT(area / PI());
    SET diag = SQRT(radius * radius * 2);
    RETURN(diag);

    /* Previous implementation returns odd geometry exceptions
    DECLARE envelope POLYGON;
    DECLARE sw, ne POINT;
    DECLARE xsize, ysize DOUBLE;

    DECLARE EXIT HANDLER FOR 1416
      RETURN(10000);

    SET envelope = ExteriorRing(Envelope(g));
    SET sw = PointN(envelope, 1);
    SET ne = PointN(envelope, 3);
    SET xsize = X(ne) - X(sw);
    SET ysize = Y(ne) - Y(sw);
    RETURN(GREATEST(xsize, ysize)); */
  END$$
DELIMITER ;

DELIMITER $$
CREATE DEFINER=`root`@`localhost` FUNCTION `GetMaxDimensionT`(`g` GEOMETRY) RETURNS double
NO SQL
  BEGIN
    DECLARE area, radius, diag DOUBLE;

    SET area = AREA(g);
    SET radius = SQRT(area / PI());
    SET diag = SQRT(radius * radius * 2);
    RETURN(diag);
  END$$
DELIMITER ;

DELIMITER $$
CREATE DEFINER=`root`@`localhost` FUNCTION `ST_IntersectionSafe`(a GEOMETRY, b GEOMETRY) RETURNS geometry
DETERMINISTIC
  BEGIN
    DECLARE ret GEOMETRY;
    DECLARE CONTINUE HANDLER FOR SQLSTATE '22023'
    BEGIN
      SET ret = POINT(0.0000,90.0000);
    END;
    SELECT ST_Intersection(a, b) INTO ret;
    RETURN ret;
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
