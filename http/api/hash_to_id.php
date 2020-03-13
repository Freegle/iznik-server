<?php

# TN uses this to retrieve the User id that FD associates with a TN member.
# This allows TN to fetch further information that FD stores about TN members.

function hash_to_id() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 1, 'status' => 'Unknown hash' ];

    if (pres('partner', $_SESSION)) {

        $hash = presdef('hash', $_REQUEST, NULL);

        $u = new User($dbhr, $dbhm);

        $id = $u->findByEmailHash($hash);

        if ($id) {
            $ret = [
                'ret' => 0,
                'status' => 'Success',
                'id' => $id
            ];
        }

    } else {
        $ret = [
            'ret' => 1,
            'status' => 'Forbidden'
        ];
    }

    return($ret);
}
