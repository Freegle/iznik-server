<?php


$handle = fopen("viaf.xml", "r");
#$handle = fopen("pain", "r");

while (($line = fgets($handle)) !== false) {
    $p = strpos($line, ' ');
    $q = strpos($line, '<');

    if ($p !== -1) {
        $id = trim(substr($line, 0, $q));
//        error_log("Id $id");
        $xmlstr = substr($line, $q);
//        error_log("xml $xmlstr");
        $xml = simplexml_load_string($xmlstr);

        if ($xml->nameType == 'Personal') {
            if ($xml->mainHeadings && $xml->mainHeadings->data && $xml->mainHeadings->data->text) {
                $name = $xml->mainHeadings->data->text;
            }

            $include = FALSE;

            foreach ($xml->countries as $country) {
                if ($country->data && $country->data->text && ($country->data->text == 'US')) {
                    $include = TRUE;
                }
            }

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
