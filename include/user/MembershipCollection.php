<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Log.php');
require_once(IZNIK_BASE . '/include/group/Group.php');

class MembershipCollection
{
    # These match the collection enumeration.
    const APPROVED = 'Approved';
    const PENDING = 'Pending';
    const BANNED = 'Banned';
    const SPAM = 'Spam';
    const HAPPINESS = 'Happiness';
    const RELATED = 'Related';
}
