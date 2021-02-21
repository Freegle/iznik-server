<?php

namespace Freegle\Iznik;

class Utils {
    # Use matching based on https://gist.github.com/gruber/249502, but changed:
    # - to only look for http/https, otherwise here:http isn't caught
    const URL_PATTERN = '#(?i)\b(((?:(?:http|https):(?:/{1,3}|[a-z0-9%])|www\d{0,3}[.]|[a-z0-9.\-]+[.][a-z]{2,4}/)(?:[^\s()<>]+|\(([^\s()<>]+|(\([^\s()<>]+\)))*\))+(?:\(([^\s()<>]+|(\([^\s()<>]+\)))*\)|[^\s`!()\[\]{};:\'".,<>?«»“”‘’]))|(\.com\/))#m';

    # ...but this matches some bad character patterns.
    const URL_BAD = [ '%', '{', ';', '#', ':' ];

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

    public static function filterResult(&$array, $skip = NULL) {
        # We want to ensure that we have the correct data types - for example PDO returns floats as strings.
        foreach($array as $key => $val){
            #error_log("$key type ". gettype($val) . " null? " . is_null($val) . " is_numeric ");

            if ($skip && (array_search($key, $skip) !== false)) {
                # Asked to do nothing
            } else if (is_int($val)) {
                # We don't want to filter out ints, even if they are 0 i.e. null.
            } else if (is_null($val)) {
                unset($array[$key]);
            } else if (is_array($val)) {
                #error_log("Recurse $key");
                Utils::filterResult($val);
                $array[$key] = $val;
            } else if (is_bool($val)) {
                # Nothing to do - it's fine.
            } else if ((array_key_exists($key, $array)) && (gettype($val) == 'string') && (strlen($val) == 0)) {
                # There is no value here worth returning.
                unset($val);
            } else if (is_numeric($val)) {
                #error_log("Numeric");
                if (strpos($val, '.') === false) {
                    # This is an integer value.  We want to return it as an int rather than a string,
                    # not least for boolean values which would otherwise require a parseInt on the client.
                    $array[$key] = intval($val);
                } else {
                    $v = floatval($val);
                    if (!is_infinite($v)) {
                        $array[$key] = $v;
                    }
                }
            }
        }
    }

    public static function safeDate($date) {
        return date("Y-m-d H:i:s", strtotime($date));
    }

    public static function calculate_median($arr) {
        sort($arr);
        $count = count($arr); //total numbers in array
        $middleval = floor(($count-1)/2); // find the middle value, or the lowest middle value
        if($count % 2) { // odd number, middle is the median
            $median = $arr[$middleval];
        } else { // even number, calculate avg of 2 medians
            $low = $arr[$middleval];
            $high = $arr[$middleval+1];
            $median = (($low+$high)/2);
        }
        return $median;
    }

    public static function code_to_country( $code ){

        $code = strtoupper($code);

        $countryList = array(
            'AF' => 'Afghanistan',
            'AX' => 'Aland Islands',
            'AL' => 'Albania',
            'DZ' => 'Algeria',
            'AS' => 'American Samoa',
            'AD' => 'Andorra',
            'AO' => 'Angola',
            'AI' => 'Anguilla',
            'AQ' => 'Antarctica',
            'AG' => 'Antigua and Barbuda',
            'AR' => 'Argentina',
            'AM' => 'Armenia',
            'AW' => 'Aruba',
            'AU' => 'Australia',
            'AT' => 'Austria',
            'AZ' => 'Azerbaijan',
            'BS' => 'Bahamas the',
            'BH' => 'Bahrain',
            'BD' => 'Bangladesh',
            'BB' => 'Barbados',
            'BY' => 'Belarus',
            'BE' => 'Belgium',
            'BZ' => 'Belize',
            'BJ' => 'Benin',
            'BM' => 'Bermuda',
            'BT' => 'Bhutan',
            'BO' => 'Bolivia',
            'BA' => 'Bosnia and Herzegovina',
            'BW' => 'Botswana',
            'BV' => 'Bouvet Island (Bouvetoya)',
            'BR' => 'Brazil',
            'IO' => 'British Indian Ocean Territory (Chagos Archipelago)',
            'VG' => 'British Virgin Islands',
            'BN' => 'Brunei Darussalam',
            'BG' => 'Bulgaria',
            'BF' => 'Burkina Faso',
            'BI' => 'Burundi',
            'KH' => 'Cambodia',
            'CM' => 'Cameroon',
            'CA' => 'Canada',
            'CV' => 'Cape Verde',
            'KY' => 'Cayman Islands',
            'CF' => 'Central African Republic',
            'TD' => 'Chad',
            'CL' => 'Chile',
            'CN' => 'China',
            'CX' => 'Christmas Island',
            'CC' => 'Cocos (Keeling) Islands',
            'CO' => 'Colombia',
            'KM' => 'Comoros the',
            'CD' => 'Congo',
            'CG' => 'Congo the',
            'CK' => 'Cook Islands',
            'CR' => 'Costa Rica',
            'CI' => 'Cote d\'Ivoire',
            'HR' => 'Croatia',
            'CU' => 'Cuba',
            'CY' => 'Cyprus',
            'CZ' => 'Czech Republic',
            'DK' => 'Denmark',
            'DJ' => 'Djibouti',
            'DM' => 'Dominica',
            'DO' => 'Dominican Republic',
            'EC' => 'Ecuador',
            'EG' => 'Egypt',
            'SV' => 'El Salvador',
            'GQ' => 'Equatorial Guinea',
            'ER' => 'Eritrea',
            'EE' => 'Estonia',
            'ET' => 'Ethiopia',
            'FO' => 'Faroe Islands',
            'FK' => 'Falkland Islands (Malvinas)',
            'FJ' => 'Fiji the Fiji Islands',
            'FI' => 'Finland',
            'FR' => 'France, French Republic',
            'GF' => 'French Guiana',
            'PF' => 'French Polynesia',
            'TF' => 'French Southern Territories',
            'GA' => 'Gabon',
            'GM' => 'Gambia the',
            'GE' => 'Georgia',
            'DE' => 'Germany',
            'GH' => 'Ghana',
            'GI' => 'Gibraltar',
            'GR' => 'Greece',
            'GL' => 'Greenland',
            'GD' => 'Grenada',
            'GP' => 'Guadeloupe',
            'GU' => 'Guam',
            'GT' => 'Guatemala',
            'GG' => 'Guernsey',
            'GN' => 'Guinea',
            'GW' => 'Guinea-Bissau',
            'GY' => 'Guyana',
            'HT' => 'Haiti',
            'HM' => 'Heard Island and McDonald Islands',
            'VA' => 'Holy See (Vatican City State)',
            'HN' => 'Honduras',
            'HK' => 'Hong Kong',
            'HU' => 'Hungary',
            'IS' => 'Iceland',
            'IN' => 'India',
            'ID' => 'Indonesia',
            'IR' => 'Iran',
            'IQ' => 'Iraq',
            'IE' => 'Ireland',
            'IM' => 'Isle of Man',
            'IL' => 'Israel',
            'IT' => 'Italy',
            'JM' => 'Jamaica',
            'JP' => 'Japan',
            'JE' => 'Jersey',
            'JO' => 'Jordan',
            'KZ' => 'Kazakhstan',
            'KE' => 'Kenya',
            'KI' => 'Kiribati',
            'KP' => 'Korea',
            'KR' => 'Korea',
            'KW' => 'Kuwait',
            'KG' => 'Kyrgyz Republic',
            'LA' => 'Lao',
            'LV' => 'Latvia',
            'LB' => 'Lebanon',
            'LS' => 'Lesotho',
            'LR' => 'Liberia',
            'LY' => 'Libyan Arab Jamahiriya',
            'LI' => 'Liechtenstein',
            'LT' => 'Lithuania',
            'LU' => 'Luxembourg',
            'MO' => 'Macao',
            'MK' => 'Macedonia',
            'MG' => 'Madagascar',
            'MW' => 'Malawi',
            'MY' => 'Malaysia',
            'MV' => 'Maldives',
            'ML' => 'Mali',
            'MT' => 'Malta',
            'MH' => 'Marshall Islands',
            'MQ' => 'Martinique',
            'MR' => 'Mauritania',
            'MU' => 'Mauritius',
            'YT' => 'Mayotte',
            'MX' => 'Mexico',
            'FM' => 'Micronesia',
            'MD' => 'Moldova',
            'MC' => 'Monaco',
            'MN' => 'Mongolia',
            'ME' => 'Montenegro',
            'MS' => 'Montserrat',
            'MA' => 'Morocco',
            'MZ' => 'Mozambique',
            'MM' => 'Myanmar',
            'NA' => 'Namibia',
            'NR' => 'Nauru',
            'NP' => 'Nepal',
            'AN' => 'Netherlands Antilles',
            'NL' => 'Netherlands the',
            'NC' => 'New Caledonia',
            'NZ' => 'New Zealand',
            'NI' => 'Nicaragua',
            'NE' => 'Niger',
            'NG' => 'Nigeria',
            'NU' => 'Niue',
            'NF' => 'Norfolk Island',
            'MP' => 'Northern Mariana Islands',
            'NO' => 'Norway',
            'OM' => 'Oman',
            'PK' => 'Pakistan',
            'PW' => 'Palau',
            'PS' => 'Palestinian Territory',
            'PA' => 'Panama',
            'PG' => 'Papua New Guinea',
            'PY' => 'Paraguay',
            'PE' => 'Peru',
            'PH' => 'Philippines',
            'PN' => 'Pitcairn Islands',
            'PL' => 'Poland',
            'PT' => 'Portugal, Portuguese Republic',
            'PR' => 'Puerto Rico',
            'QA' => 'Qatar',
            'RE' => 'Reunion',
            'RO' => 'Romania',
            'RU' => 'Russian Federation',
            'RW' => 'Rwanda',
            'BL' => 'Saint Barthelemy',
            'SH' => 'Saint Helena',
            'KN' => 'Saint Kitts and Nevis',
            'LC' => 'Saint Lucia',
            'MF' => 'Saint Martin',
            'PM' => 'Saint Pierre and Miquelon',
            'VC' => 'Saint Vincent and the Grenadines',
            'WS' => 'Samoa',
            'SM' => 'San Marino',
            'ST' => 'Sao Tome and Principe',
            'SA' => 'Saudi Arabia',
            'SN' => 'Senegal',
            'RS' => 'Serbia',
            'SC' => 'Seychelles',
            'SL' => 'Sierra Leone',
            'SG' => 'Singapore',
            'SK' => 'Slovakia (Slovak Republic)',
            'SI' => 'Slovenia',
            'SB' => 'Solomon Islands',
            'SO' => 'Somalia, Somali Republic',
            'ZA' => 'South Africa',
            'GS' => 'South Georgia and the South Sandwich Islands',
            'ES' => 'Spain',
            'LK' => 'Sri Lanka',
            'SD' => 'Sudan',
            'SR' => 'Suriname',
            'SJ' => 'Svalbard & Jan Mayen Islands',
            'SZ' => 'Swaziland',
            'SE' => 'Sweden',
            'CH' => 'Switzerland, Swiss Confederation',
            'SY' => 'Syrian Arab Republic',
            'TW' => 'Taiwan',
            'TJ' => 'Tajikistan',
            'TZ' => 'Tanzania',
            'TH' => 'Thailand',
            'TL' => 'Timor-Leste',
            'TG' => 'Togo',
            'TK' => 'Tokelau',
            'TO' => 'Tonga',
            'TT' => 'Trinidad and Tobago',
            'TN' => 'Tunisia',
            'TR' => 'Turkey',
            'TM' => 'Turkmenistan',
            'TC' => 'Turks and Caicos Islands',
            'TV' => 'Tuvalu',
            'UG' => 'Uganda',
            'UA' => 'Ukraine',
            'AE' => 'United Arab Emirates',
            'GB' => 'United Kingdom',
            'US' => 'United States of America',
            'UM' => 'United States Minor Outlying Islands',
            'VI' => 'United States Virgin Islands',
            'UY' => 'Uruguay, Eastern Republic of',
            'UZ' => 'Uzbekistan',
            'VU' => 'Vanuatu',
            'VE' => 'Venezuela',
            'VN' => 'Vietnam',
            'WF' => 'Wallis and Futuna',
            'EH' => 'Western Sahara',
            'YE' => 'Yemen',
            'ZM' => 'Zambia',
            'ZW' => 'Zimbabwe'
        );

        if( !array_key_exists($code, $countryList)) return $code;
        else return $countryList[$code];
    }

    public static function getProtocol() {
        return(Utils::pres('HTTPS', $_SERVER) ? 'https://' : 'http://');
    }

    // equiv to rand, mt_rand
    // returns int in *closed* interval [$min,$max]
    public static function devurandom_rand($min = 1, $max = 0x7FFFFFFF) {
        return mt_rand($min, $max);
    }

    public static function pres($key, $arr) {
        return($arr && is_array($arr) && array_key_exists($key, $arr) && $arr[$key] ? $arr[$key] : FALSE);
    }

    public static function presdef($key, $arr, $def) {
        if ($arr && array_key_exists($key, $arr) && $arr[$key]) {
            return($arr[$key]);
        } else {
            return($def);
        }
    }

    public static function presint($key, $arr, $def) {
        if ($arr && array_key_exists($key, $arr)) {
            return(intval($arr[$key]));
        } else {
            return($def);
        }
    }

    public static function presfloat($key, $arr, $def) {
        if ($arr && array_key_exists($key, $arr)) {
            return(floatval($arr[$key]));
        } else {
            return($def);
        }
    }

    public static function presbool($key, $arr, $def) {
        return array_key_exists($key, $arr) ? filter_var($_REQUEST[$key], FILTER_VALIDATE_BOOLEAN) : $def;
    }

    public static function ISODate($date)
    {
        if ($date) {
            $date = new \DateTime($date);
            $date = $date->format(\DateTime::ISO8601);
            $date = str_replace('+0000', 'Z', $date);
        }

        return ($date);
    }

    public static function randstr($length = 10) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $randomString;
    }

    public static function lockScript($fn) {
        $lock = "/tmp/iznik_lock_$fn.lock";
        $lockh = fopen($lock, 'wa');

        try {
            $block = 0;

            if (!flock($lockh, LOCK_EX | LOCK_NB, $block)) {
                error_log("Script locked");
                exit(0);
            }
        } catch (\Exception $e) {
            error_log("Top-level exception " . $e->getMessage() . "\n");
            exit(0);
        }

        return($lockh);
    }

    public static function unlockScript($lockh) {
        flock($lockh, LOCK_UN);
        fclose($lockh);
    }

    public static function randomFloat($min = 0, $max = 1) {
        return $min + mt_rand() / mt_getrandmax() * ($max - $min);
    }

    public static function canonWord($word)
    {
        $word = strtolower($word);
        $word = preg_replace('/[^\da-z]/i', '', $word);

        $arr = str_split($word);

        if (strlen($word) > 3)
        {
            sort($arr);
        }

        $ret = implode($arr);

        return($ret);
    }

    public static function canonSentence($sentence)
    {
        $words = preg_split('/\s+/', $sentence);
        $canonWords = array();

        for ($i = 0; $i < count($words); $i++)
        {
            array_push($canonWords, Utils::canonWord($words[$i]));
        }

        $canonWords = array_values(array_unique($canonWords));

        return($canonWords);
    }

    public static function wordsInCommon($sentence1, $sentence2)
    {
        $words1 = Utils::canonSentence($sentence1);
        $words2 = Utils::canonSentence($sentence2);

        // We have two arrays of words.
        $ret = 0;
        $count1 = count($words1);
        $count2 = count($words2);

        for ($i = 0; $i < $count1; $i++)
        {
            for ($j = 0; $j < $count2; $j++)
            {
                if ($words1[$i] === $words2[$j])
                {
                    $ret++;
                }
            }
        }

        # Calculate percent vs the longest.
        $limit = max($count1, $count2);
        $ret = ($limit == 1) ? 0 : (100 * $ret / $limit);

        return($ret);
    }

    public function array_key_first(array $arr) {
        # Not available in PHP until 7.3.
        foreach($arr as $key => $unused) {
            return $key;
        }
        return NULL;
    }
}