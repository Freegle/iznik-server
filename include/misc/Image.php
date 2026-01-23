<?php
namespace Freegle\Iznik;

class Image {
    private $data;
    public $img;

    function __construct($data) {
        $this->data = $data;
        $this->img = @imagecreatefromstring($data);
    }

    public function fillTransparent() {
        imagealphablending($this->img, FALSE);
        imagesavealpha($this->img, TRUE);
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

    /**
     * Apply a duotone effect to the image.
     * Converts the image to grayscale, then maps grayscale values to a color gradient
     * between two specified colors.
     *
     * @param int $darkR Red component of dark color (0-255)
     * @param int $darkG Green component of dark color (0-255)
     * @param int $darkB Blue component of dark color (0-255)
     * @param int $lightR Red component of light color (0-255)
     * @param int $lightG Green component of light color (0-255)
     * @param int $lightB Blue component of light color (0-255)
     */
    public function duotone($darkR, $darkG, $darkB, $lightR, $lightG, $lightB) {
        if (!$this->img) {
            return;
        }

        $width = imagesx($this->img);
        $height = imagesy($this->img);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($this->img, $x, $y);

                # Extract RGB components.
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                # Convert to grayscale using luminance formula.
                $gray = intval(0.299 * $r + 0.587 * $g + 0.114 * $b);

                # Map grayscale to duotone gradient.
                # gray=0 -> dark color, gray=255 -> light color
                $t = $gray / 255.0;
                $newR = intval($darkR + $t * ($lightR - $darkR));
                $newG = intval($darkG + $t * ($lightG - $darkG));
                $newB = intval($darkB + $t * ($lightB - $darkB));

                $newColor = imagecolorallocate($this->img, $newR, $newG, $newB);
                imagesetpixel($this->img, $x, $y, $newColor);
            }
        }
    }

    /**
     * Apply the standard Freegle AI image duotone effect.
     * Converts to dark green (#0D3311) to white (#FFFFFF) gradient.
     */
    public function duotoneGreen() {
        # Dark green: #0D3311 = RGB(13, 51, 17)
        # White: #FFFFFF = RGB(255, 255, 255)
        $this->duotone(13, 51, 17, 255, 255, 255);
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