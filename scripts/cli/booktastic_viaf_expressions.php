<?php

$handle = gzopen('/tmp/viaf-20200408-clusters.xml.gz','r') or die("can't open: $php_errormsg");
$count = 0;

while ($line = gzgets($handle)) {
    if (strpos($line, '<expression') !== FALSE) {
        # Might have an expression
        $p = strpos($line, ' ');
        $q = strpos($line, '<');

        if ($p !== -1) {
            $id = trim(substr($line, 0, $q));
            $xmlstr = substr($line, $q);
            $xml = simplexml_load_string($xmlstr);

            if ($xml->nameType == 'UniformTitleWork') {
                $author = NULL;
                foreach ($xml->titles as $title) {
                    foreach ($title as $work) {
                        if ($work->lang && $work->lang == 'English') {
                            if ($work->datafield) {
                                foreach ($work->datafield->subfield as $d) {
                                    if ($d->attributes()['code'] == 'a') {
                                        $author = $d;
                                    } else if ($d->attributes()['code'] == 't') {
//                            fputcsv(STDOUT, [ $id, $name, $work->title ]);
                                        $title = $d;
                                    }
                                }

                                if ($author && $title) {
                                    fputcsv(STDOUT, [ $id, $author, $title ]);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    $count++;

    if ($count % 10000 == 0) {
        error_log("...$count at "
            . gztell($handle)
        );
    }
}
