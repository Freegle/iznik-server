<?php
function export()
{
    global $dbhr, $dbhm;

    $me = whoAmI($dbhr, $dbhm);
    $myid = $me ? $me->getId() : NULL;

    $id = intval(presdef('id', $_REQUEST, NULL));
    $tag = presdef('tag', $_REQUEST, NULL);
    $ret = [ 'ret' => 1, 'status' => 'Not logged in' ];

    if ($myid) {
        $u = new User($dbhr, $dbhm);

        switch ($_REQUEST['type']) {
            case 'GET': {
                # Return the status of the export, assuming it is for the correct user.
                $myid = $me ? $me->getId() : NULL;

                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'export' => $u->getExport($myid, $id, $tag)
                ];

                break;
            }

            case 'POST': {
                # Request an export.  We do this in the background because it can take minutes and we don't want
                # to tie up HHVM.
                list($id, $tag) = $me->requestExport();

                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'id' => $id,
                    'tag' => $tag
                ];

                break;
            }
        }
    }

    return($ret);
}
