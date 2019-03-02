<?php

class ReturnPath {
    const DIGEST = 1;
    const CHAT = 2;

    # This is the key control over how frequency we add Return Path seed lists to our mails.  0 will disable.
    const THRESHOLDS = [
        ReturnPath::DIGEST => 0,
        ReturnPath::CHAT => 100
    ];

    public static function shouldSend($type) {
        return(mt_rand(0, 1000) < ReturnPath::THRESHOLDS[$type]);
    }

    public static function matchingId($type, $qualifier) {
        # Return Path is picky about the format - needs to be alphabetic then number.
        #
        # It also applies a limit per month so use something which will only change every week.  That way all our
        # mails of a particular type and qualifier will be grouped.
        $matchingid = 'freegle' . $type. str_pad($qualifier < 0 ? (100 + $qualifier) : $qualifier, 3, '0') . date("Ymd000000", strtotime('Last Sunday'));
        return($matchingid);
    }
}