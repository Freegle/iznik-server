<?php

#$handle = gzopen('viaf-20200408-clusters.xml.gz','r') or die("can't open: $php_errormsg");
$handle = fopen("personal.xml", "r");
$count = 0;

while (($line = fgets($handle)) !== false) {
#while ($line = gzgets($handle)) {
    if (strpos($line, 'Personal') !== FALSE) {
        # Might have a personal record.
        $p = strpos($line, ' ');
        $q = strpos($line, '<');

        if ($p !== -1) {
            $id = trim(substr($line, 0, $q));
            $xmlstr = substr($line, $q);
            $xml = simplexml_load_string($xmlstr);

            if ($xml->nameType == 'Personal') {
                if ($xml->mainHeadings && $xml->mainHeadings->data && $xml->mainHeadings->data->text) {
                    $name = $xml->mainHeadings->data->text;
                }

//            $include = FALSE;
//
//            foreach ($xml->countries as $country) {
//                if ($country->data && $country->data->text && ($country->data->text == 'NL')) {
//                    $include = TRUE;
//                }
//            }

                $include = TRUE;

                if ($include) {
                    foreach ($xml->titles as $title) {
                        foreach ($title as $work) {
                            if ($work->title) {
                                fputcsv(STDOUT, [ $id, $name, $work->title ]);
                                #error_log("...{$title->work->title}");
                            }
                        }
                    }
                }
            }
//        print_r($xml);
        }
    }

    $count++;

    if ($count % 10000 == 0) {
        error_log("...$count at "
            #. gztell($handle)
        );
    }
}
