<?php

namespace Freegle\Iznik;

class Utils {
    public static function tmpdir() {
        $tempfile = tempnam(sys_get_temp_dir(),'');
        if (file_exists($tempfile)) { unlink($tempfile); }
        mkdir($tempfile);
        $ret = NULL;
        if (is_dir($tempfile)) { $ret = $tempfile; }
        return $ret;
    }

    public static function checkFiles($path, $upper, $lower, $delay = 60, $max = 10000) {
        $queuesize = trim(shell_exec("ls -1 $path | wc -l 2>&1"));

        if ($queuesize > $upper) {
            $count = 0;

            while ($queuesize > $lower && $count < $max) {
                sleep($delay);
                $queuesize = trim(shell_exec("ls -1 $path | wc -l 2>&1"));
                error_log("...sleeping, $path has $queuesize");
                $count++;
            }
        }

        return $queuesize;
    }
}