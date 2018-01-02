<?php

class Image {
    private $data;
    public $img;

    function __construct($data) {
        $this->data = $data;
        $this->img = @imagecreatefromstring($data);
    }

    public function fillTransparent() {
        imagealphablending($this->img, false);
        imagesavealpha($this->img, true);
        $transparent = imagecolorallocatealpha($this->img, 255, 255, 255, 127);
        imagefill($this->img, 0, 0, $transparent);
        imagecolortransparent($this->img, $transparent);
    }

    public function width() {
        return(imagesx($this->img));
    }

    public function height() {
        return(imagesy($this->img));
    }

    public function rotate($deg) {
        $this->img = imagerotate($this->img, $deg, 0);
    }

    public function scale($width, $height) {
        $sw = imagesx($this->img);
        $sh = imagesy($this->img);

        if (($width != NULL && $sw != $width) || ($height != NULL && $sh != $height)) {
            # We might have been asked to scale either or both of the width and height.
            #
            # We want to return even values - if we use the images with ffmpeg, they need to be.
            if ($width) {
                $height = intval($sh * $width / $sw + 0.5);
                $height = $height + ($height % 2);
            } else {
                $width = $sw;
            }

            if ($height) {
                $width = intval($sw * $height / $sh + 0.5);
                $width = $width + ($width % 2);
            } else {
                $height = $sh;
            }

            $height = $height ? $height : $sh;
            $old = $this->img;
            $this->img = @imagecreatetruecolor($width, $height);
            $this->fillTransparent();

            # Don't use imagecopyresized here - creates artefacts.
            imagecopyresampled($this->img, $old, 0, 0, 0, 0, $width, $height, $sw, $sh);
        }
    }

    public function getData($quality = 75) {
        $data = NULL;

        if ($this->img) {
            # Get data back as JPEG.  Use default quality.
            ob_start();
            imagejpeg($this->img, null, $quality);
            $data = ob_get_contents();
            ob_end_clean();
        }

        return($data);
    }

    public function getDataPNG() {
        $data = NULL;

        if ($this->img) {
            ob_start();
            imagepng($this->img, null);
            $data = ob_get_contents();
            ob_end_clean();
        }

        return($data);
    }

    public function circle($radius) {
        $d = imagecreatetruecolor($radius, $radius);
        imagecopy($d, $this->img, 0, 0, 0, 0, $radius, $radius);

        $mask = imagecreatetruecolor($radius, $radius);
        $maskTransparent = imagecolorallocate($mask, 255, 0, 255);
        imagecolortransparent($mask, $maskTransparent);
        imagefilledellipse($mask, $radius / 2, $radius / 2, $radius, $radius, $maskTransparent);

        imagecopymerge($d, $mask, 0, 0, 0, 0, $radius, $radius, 100);

        $dstTransparent = imagecolorallocate($d, 255, 0, 255);
        imagefill($d, 0, 0, $dstTransparent);
        imagefill($d, $radius - 1, 0, $dstTransparent);
        imagefill($d, 0, $radius - 1, $dstTransparent);
        imagefill($d, $radius - 1, $radius - 1, $dstTransparent);
        imagecolortransparent($d, $dstTransparent);

        $this->img = $d;
    }
}