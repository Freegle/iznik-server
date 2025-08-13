<?php

$opts = getopt('i:o:');

$im = imagecreatefromjpeg($opts['i']);

if (imageistruecolor($im))
{
    $transparent = imagecolortransparent($im);
    $grey = imagecolorallocate($im, 128, 128, 128);

    $sx = imagesx($im);
    $sy = imagesy($im);
    $miny = PHP_INT_MAX;

    for ($y = $sy - 1; $y >= 0; $y--)
    {
        $match = 0;
        $not = 0;

        for ($x = 0; $x < $sx; $x++)
        {
            $c = imagecolorat($im, $x, $y);

            if ($c == $grey)
            {
                $match++;
            } else
            {
                $not++;
            }
        }

        if (100 * $match / ($match + $not) > 10)
        {
            $miny = $y;
        }
    }

    if ($miny !== PHP_INT_MAX)
    {
        $im2 = imagecrop($im, ['x' => 0, 'y' => 0, 'width' => $sx, 'height' => $miny]);
        imagejpeg($im2, $opts['o'], 100);
    }
} else
{
    error_log("Not TRUE colour.");
    exit(1);
}