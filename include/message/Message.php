<?php
namespace Freegle\Iznik;

# We include this directly because the composer version isn't quite right for us - see
# https://github.com/php-mime-mail-parser/php-mime-mail-parser/issues/163
require_once(IZNIK_BASE . '/lib/php-mime-mail-parser/php-mime-mail-parser/src/Parser.php');
require_once(IZNIK_BASE . '/lib/geoPHP/geoPHP.inc');

use GeoIp2\Database\Reader;
use Oefenweb\DamerauLevenshtein\DamerauLevenshtein;

class Message
{
    const TYPE_OFFER = 'Offer';
    const TYPE_TAKEN = 'Taken';
    const TYPE_WANTED = 'Wanted';
    const TYPE_RECEIVED = 'Received';
    const TYPE_ADMIN = 'Admin';
    const TYPE_OTHER = 'Other';

    const EXPIRE_TIME = 90;

    const OUTCOME_TAKEN = 'Taken';
    const OUTCOME_RECEIVED = 'Received';
    const OUTCOME_WITHDRAWN = 'Withdrawn';
    const OUTCOME_REPOST = 'Repost';
    const OUTCOME_EXPIRED = 'Expired';
    const OUTCOME_PARTIAL = 'Partial';

    const LIKE_LOVE = 'Love';
    const LIKE_LAUGH = 'Laugh';
    const LIKE_VIEW = 'View';

    const EMAIL_REGEXP = '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,4}\b/i';
    const LOVEJUNK_AREA = 'POLYGON((-0.21284415586420025 51.68497949384652,-0.198767922954044 51.68625660505424,-0.18194510801263775 51.68370234661232,-0.16615226133295025 51.687746522596925,-0.15104606016107525 51.685405201585354,-0.136969827250919 51.687746522596925,-0.1211769805712315 51.68817220431696,-0.10710074766107525 51.691790337324136,-0.08650138242670025 51.68966205881485,-0.07894828184076275 51.687959363957326,-0.06521537168451275 51.683489485237345,-0.058005593852481496 51.682638029729965,-0.055259011821231496 51.683276622861634,-0.027106546000918996 51.681573687828724,-0.009597085551700246 51.68050932090834,0.0017325653272060038 51.68136081644617,0.023361898823299754 51.677954738220215,0.038468099995174754 51.67944492896868,0.048424459858456004 51.68412806636002,0.06524727479986225 51.68838504367593,0.090996481342831 51.6894492254601,0.10026619569829975 51.68753368023585,0.1136557831006435 51.68178655821048,0.12773201601079975 51.67944492896868,0.14970467226079975 51.67007720103184,0.15863106386236225 51.668160836162826,0.169274069233456 51.66134643782319,0.176140524311581 51.657299903861244,0.1864402069287685 51.65474401202227,0.196739889545956 51.6517619560304,0.207726217670956 51.64515813383615,0.2152793182568935 51.638553349617816,0.22317574159673725 51.63173449871459,0.2290122284131435 51.626619687749645,0.246178366108456 51.620864836073935,0.255791403217831 51.61596197929035,0.266777731342831 51.60487530702578,0.26918099062017475 51.59570543654094,0.2715842498975185 51.588453530353384,0.2770774139600185 51.5837605035281,0.28222725526861225 51.577573500230606,0.284630514545956 51.57245258461065,0.290123678608456 51.561568723208794,0.28840706483892475 51.55409789893853,0.2839438690381435 51.548333853131375,0.2811972870068935 51.54022025506192,0.27879402772954975 51.53018301398858,0.27124092714361225 51.5192890356121,0.266777731342831 51.510742915045064,0.2688376678662685 51.50091289410549,0.26643440858892475 51.48231553414723,0.2660910858350185 51.47376247781017,0.2551047577100185 51.4607159787735,0.2413718475537685 51.4519449240603,0.23553536073736225 51.44723780963772,0.23896858827642475 51.430759087341904,0.23690865175298725 51.424551274035586,0.22111580507329975 51.414488544108146,0.19639656679204975 51.39328533262892,0.192620016499081 51.38707242919167,0.18403694765142475 51.37743004669281,0.17305061952642475 51.371643642062104,0.16343758241704975 51.36735694487111,0.15794441835454975 51.36178363854049,0.15588448183111225 51.356209653941896,0.15657112733892475 51.34227172482453,0.15657112733892475 51.33519392278045,0.15863106386236225 51.32918765794293,0.159661032124081 51.30987647731056,0.155541159077206 51.30451081760925,0.14627144472173725 51.300003178622646,0.1424948944287685 51.29184537323423,0.13185188905767475 51.28776592693084,0.11262581483892475 51.285833431133774,0.1026694549756435 51.28304190475026,0.07966683046392475 51.27724357703399,0.064903952045956 51.27337761859726,0.042931295795956004 51.272733260563825,0.028168417377987254 51.2701557380646,0.014435507221737254 51.267578070978566,-0.012686990336856496 51.269296531767935,-0.022986672954043996 51.267578070978566,-0.037406228618106496 51.259199654361296,-0.048049233989200246 51.258125387929155,-0.0648720489306065 51.2589848030832,-0.08032157285638775 51.25791053163025,-0.094397805766544 51.257265956708444,-0.1225502715868565 51.262637138246234,-0.1349098907274815 51.264570609965986,-0.15104606016107525 51.2660743650729,-0.16477897031732525 51.269296531767935,-0.17851188047357525 51.26628918321458,-0.19087149961420025 51.260918428436476,-0.2008278594774815 51.25834024322393,-0.2145607696337315 51.2572659567087,-0.23001029355951275 51.25962935390531,-0.2475197540087315 51.26714844574058,-0.259192727641544 51.27359240260059,-0.2667458282274815 51.28368611820632,-0.28013541562982525 51.29034246149802,-0.3038246856493565 51.29420699229139,-0.31378104551263775 51.296783165453334,-0.327857278422794 51.31245177113023,-0.334723733500919 51.31545609790481,-0.344336770610294 51.316314440849126,-0.35909964902826275 51.313095572000684,-0.37489249570795025 51.311807961226044,-0.39137198789545025 51.30730103894954,-0.4013283477587315 51.305369365321006,-0.408881448344669 51.30665715677414,-0.4301674590868565 51.313739363837634,-0.45111014707513775 51.322966054161014,-0.4644997344774815 51.32554061326973,-0.4713661895556065 51.32618423046487,-0.48200919492670025 51.332619905623595,-0.485785745219669 51.33862572085923,-0.4864723907274815 51.348061840924586,-0.5081017242235752 51.37121499040318,-0.5125649200243565 51.37914439650124,-0.524237893657169 51.38792943154818,-0.5335076080126377 51.393713777632755,-0.539344094829044 51.40271019583661,-0.5424339996142002 51.409563422110836,-0.539344094829044 51.41919903391512,-0.5414040313524815 51.42947478137162,-0.5369408355517002 51.435681925802044,-0.5304177032274815 51.441032235510114,-0.528357766704044 51.44638191851498,-0.5232079253954502 51.450661213710575,-0.5170281158251377 51.461143791991574,-0.5081017242235752 51.46798825806409,-0.5039818511767002 51.47504553851119,-0.5032952056688877 51.4810326779455,-0.495398782329044 51.49001191357496,-0.49505545957513775 51.49834819204551,-0.5019219146532627 51.50711032826451,-0.5012352691454502 51.51480252224192,-0.49848868711420025 51.52292065133426,-0.499518655375919 51.53295949359639,-0.502265237407169 51.53979318351118,-0.507758401469669 51.54491777764819,-0.5184014068407627 51.54897433873463,-0.5259545074267002 51.5530305381518,-0.5317909942431065 51.55964777132141,-0.5355675445360752 51.5722392006088,-0.5355675445360752 51.577360140268176,-0.524237893657169 51.624701484961086,-0.5108483062548252 51.63706181358589,-0.5019219146532627 51.64430595767679,-0.4960854278368565 51.65559599198794,-0.494025491313419 51.65879077420664,-0.494025491313419 51.66539261045245,-0.4892189727587315 51.6698642756091,-0.4796059356493565 51.672632228086115,-0.4644997344774815 51.67348387174268,-0.4507668243212315 51.681147944066566,-0.4466469512743565 51.68689514715198,-0.44699027402826275 51.6930672564007,-0.4452736602587315 51.69902573576184,-0.4384072051806065 51.70498343063746,-0.42227103574701275 51.713705782838986,-0.40647818906732525 51.71498208355159,-0.39823844297357525 51.71391850212565,-0.3862221465868565 51.71668376180786,-0.369056008891544 51.71498208355179,-0.3573830352587315 51.711365804652665,-0.3450234161181065 51.70902570540367,-0.329230569438419 51.71030213818374,-0.319617532329044 51.71221671981851,-0.3038246856493565 51.710727607772895,-0.2969582305712315 51.709663926296095,-0.2832253204149815 51.71562022040149,-0.27258231504388775 51.71647105552771,-0.248206399516544 51.70370684777468,-0.24374320371576275 51.700940794665975,-0.239966653422794 51.694769759127304,-0.23275687559076275 51.68859788203805,-0.22314383848138775 51.68519234822022,-0.21284415586420025 51.68497949384652))';

    private $replycount = 0;

    // Bounce checks.
    private $bounce_subjects = [
        "Unable to deliver your message",
        "Mail delivery failed",
        "Delivery Status Notification",
        "Undelivered Mail Returned to Sender",
        "Local delivery error",
        "Returned mail",
        "delivery failure",
        "Delivery has failed",
        "Please redirect your e-mail",
        "Email delivery failure",
        "Undeliverable",
        "Auto-response",
        "Inactive account",
        "Change of email",
        "Unable to process your message",
        "Unable to process the message",
        "Has decided to leave the company",
        "No longer a valid",
        "does not exist",
        "new email address",
        "Malformed recipient",
        "spamarrest.com",
        "(Automatic Response)",
        "Automatic reply",
        "email address closure",
        "invalid address",
        "User unknown",
        'Retiring this e-mail address',
        "Could not send message",
        "Unknown user"
    ];
    
    private $bounce_bodies = [
        "I'm afraid I wasn't able to deliver your message to the following addresses.",
        "I'm afraid I wasn't able to deliver the following message.",
        "Delivery to the following recipients failed.",
        "was not delivered to",
        "550 No such user",
        "update your records",
        "has now left",
        "please note his new address",
        "this account is no longer in use",
        "Sorry, we were unable to deliver your message",
        "this email address is no longer in use",
        "we are unable to process the message"
    ];
    
    // Autoreply checks.
    private $autoreply_subjects = [
        "Auto Response",
        "Autoresponder",
        "If your enquiry is urgent",
        "Thankyou for your enquiry",
        "Thanks for your email",
        "Thanks for contacting",
        "Thank you for your enquiry",
        "Many thanks for your",
        "Automatic reply",
        "Mail Receipt",
        "Read receipt",
        "Return Receipt",
        "Automated reply",
        "Auto-Reply",
        "Out of Office",
        "maternity leave",
        "paternity leave",
        "return to the office",
        "due to return",
        "annual leave",
        "on holiday",
        "vacation reply"
    ];

    private $autoreply_bodies = [
        "I aim to respond within",
        "Our team aims to respond",
        "reply as soon as possible",
        'with clients right now',
        "Automated response",
        "Please note his new address",
        "THIS IS AN AUTO-RESPONSE MESSAGE",
        "out of the office",
        "on annual leave",
        "Thank you so much for your email enquiry",
        "I am away",
        "I am currently away",
        "Thanks for your email enquiry",
        "don't check this very often",
        "below to complete the verification process",
        "We respond to emails as quickly as we can",
        "this email address is no longer in use",
        "away from the office",
        "I won't be able to check any emails until after",
        "I'm on leave at the moment",
        "We'll get back to you as soon as possible",
        'currently on leave'
    ];

    private $autoreply_text_start = [
        'Display message',
        'Display this message',
        'Display trusted message'
    ];

    private $loveJunkPoly = null;
    
    static public function checkType($type) {
        switch($type) {
            case Message::TYPE_OFFER:
            case Message::TYPE_TAKEN:
            case Message::TYPE_WANTED:
            case Message::TYPE_RECEIVED:
            case Message::TYPE_ADMIN:
            case Message::TYPE_OTHER:
                $ret = $type;
                break;
            default:
                $ret = NULL;
        }
        
        return($ret);
    }
    
    static public function checkTypes($types) {
        $ret = NULL;

        if ($types) {
            $ret = [];

            foreach ($types as $type) {
                $thistype = Message::checkType($type);

                if ($thistype) {
                    $ret[] = "'$thistype'";
                }
            }
        }

        return($ret);
    }

    /**
     * @return null
     */
    public function getGroupid()
    {
        return $this->groupid;
    }

    public function setPrivate($att, $val, $always = FALSE) {
        if ($this->$att != $val || $always) {
            $rc = $this->dbhm->preExec("UPDATE messages SET $att = ? WHERE id = {$this->id};", [$val]);
            if ($rc) {
                $this->$att = $val;
            }
        }
    }

    public function getPrivate($att) {
        return($this->$att);
    }

    public function setFOP($fop) {
        $this->dbhm->preExec("INSERT INTO messages_deadlines (msgid, fop) VALUES (?,?) ON DUPLICATE KEY UPDATE fop = ?;", [
            $this->id,
            $fop ? 1 : 0,
            $fop ? 1 : 0
        ]);
    }

    public function deleteItems() {
        $this->dbhm->preExec("DELETE FROM messages_items WHERE msgid = ?;", [ $this->id ]);
    }
    
    public function edit($subject, $textbody, $type, $item, $location, $attachments, $checkreview = TRUE, $groupid = NULL) {
        $ret = TRUE;
        $textbody = trim($textbody);

        # Get old values for edit history.  We put NULL if there is no edit.
        $oldtext = $textbody ? trim($this->getPrivate('textbody')) : NULL;
        $oldsubject = ($type || $item || $location) ? $this->getPrivate('subject') : NULL;
        $oldtype = $type ? $this->getPrivate('type') : NULL;
        $oldlocation = $location ? $this->getPrivate('locationid') : NULL;
        $olditems = NULL;

        if ($item) {
            $olditems = [];

            foreach ($this->getItems() as $olditem) {
                $olditems[] = intval($olditem['id']);
            }

            $olditems = json_encode($olditems);
        }

        $oldatts = $this->dbhr->preQuery("SELECT id FROM messages_attachments WHERE msgid = ? AND ((data IS NOT NULL AND LENGTH(data) > 0) OR archived = 1) ORDER BY id;", [
            $this->id
        ]);

        $oldattachments = NULL;

        if (count($oldatts)) {
            $oldattachments = [];

            foreach ($oldatts as $oldatt) {
                $oldattachments[] = intval($oldatt['id']);
            }

            $oldattachments = json_encode($oldattachments);
        }

        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $text = ($subject ? "New subject $subject " : '');
        $text .= ($type ? "New type $type " : '');
        $text .= ($item ? "New item $item " : '');
        $text .= ($location ? "New location $location" : '');
        $text .= "Text body changed to len " . strlen($textbody);

        if ($type) {
            $this->setPrivate('type', $type);
            $this->dbhm->preExec("UPDATE messages_groups SET msgtype = ? WHERE msgid = ?;", [
                $type,
                $this->id
            ]);
        }

        if ($item) {
            # Remove any old item and add this one.
            $i = new Item($this->dbhr, $this->dbhm);
            $iid = $i->create($item);

            $ret = FALSE;

            if ($iid) {
                $ret = TRUE;
                $this->deleteItems();
                $this->addItem($iid);
            }
        }

        if ($location) {
            $l = new Location($this->dbhr, $this->dbhm);
            $lid = $l->findByName($location);
            $l = new Location($this->dbhr, $this->dbhm, $lid);

            $ret = FALSE;
            if ($lid) {
                $ret = TRUE;
                $this->setPrivate('locationid', $lid);
                $this->setPrivate('lat', $l->getPrivate('lat'));
                $this->setPrivate('lng', $l->getPrivate('lng'));
            }
        }

        if ($subject && strlen($subject) > 10) {
            # If the subject has been edited, then that edit is more important than any suggestion we might have
            # come up with.  Don't allow stupidly short edits.
            $this->setPrivate('subject', $subject);
            $this->setPrivate('suggestedsubject', $subject);
        } else if ($ret && ($type || $item || $location)) {
            # Construct a new subject from the edited values.
            if (!$groupid) {
                $groups = $this->getGroups(FALSE, TRUE);

                foreach ($groups as $group) {
                    $groupid = $group;
                }
            }

            # If a subject has been supplied, don't overwrite it.
            $this->constructSubject($groupid);
            $this->setPrivate('subject', $this->subject);
            $this->setPrivate('suggestedsubject', $this->subject);
        }

        if ($textbody) {
            $this->setPrivate('textbody', $textbody);
        }

        if ($attachments !== NULL) {
            $this->replaceAttachments($attachments);
        }

        $reviewrequired = FALSE;

        if ($me && $me->getId() === $this->getFromuser() && $checkreview) {
            # Edited by the person who posted it.
            $groups = $this->getGroups(FALSE, FALSE);

            foreach ($groups as $group) {
                # Consider the posting status on this group.  The group might have a setting for moderation; failing
                # that we use the posting status on the group.
                #error_log("Consider group {$group['collection']} and status " . $me->getMembershipAtt($group['groupid'], 'ourPostingStatus'));
                $g = Group::get($this->dbhr, $this->dbhm, $group['groupid']);
                $postcoll = ($g->getSetting('moderated', 0) || $g->getSetting('closed', 0)) ? MessageCollection::PENDING : $me->postToCollection($group['groupid']);

                if ($group['collection'] === MessageCollection::APPROVED &&
                    $postcoll === MessageCollection::PENDING) {
                    # This message is approved, but the member is moderated.  That means the message must previously
                    # have been approved.  So this edit also needs approval.  We can't move the message back to Pending
                    # because it might already be getting replies from people.
                    $reviewrequired = TRUE;

                    # Notify the mods of the soon-to-exist pending work.
                    $n = new PushNotifications($this->dbhr, $this->dbhm);
                    $n->notifyGroupMods($group['groupid']);
                    error_log("Edit notify {$group['groupid']}");
                }
            }
        }

        if ($ret) {
            $this->log->log([
                'type' => Log::TYPE_MESSAGE,
                'subtype' => Log::SUBTYPE_EDIT,
                'msgid' => $this->id,
                'byuser' => $me ? $me->getId() : NULL,
                'text' => $text
            ]);

            $sql = "UPDATE messages SET editedby = ?, editedat = NOW() WHERE id = ?;";
            $this->dbhm->preExec($sql, [
                $me ? $me->getId() : NULL,
                $this->id
            ]);

            # Record the edit history.
            $newitems = $item ? json_encode([ intval($iid) ]) : NULL;
            $newlocation = $location ? $this->getPrivate('locationid') : NULL;
            $newsubject = $this->getPrivate('subject');
            $newattachments = $attachments && count($attachments) ? json_encode($attachments) : NULL;

            $data = [
                $this->id,
                ($oldtext && $oldtext != $textbody) ? $oldtext : NULL,
                ($oldtext && $oldtext != $textbody) ? $textbody : NULL,
                ($oldsubject && $oldsubject != $newsubject) ? $oldsubject : NULL,
                ($oldsubject && $oldsubject != $newsubject) ? $newsubject : NULL,
                $oldtype != $type ? $oldtype : NULL,
                $oldtype != $type ? $type : NULL,
                $olditems != $newitems ? $olditems : NULL,
                $olditems != $newitems ? $newitems : NULL,
                $newattachments !== NULL && $oldattachments != $newattachments ? $oldattachments : NULL,
                $newattachments !== NULL && $oldattachments != $newattachments ? $newattachments : NULL,
                $oldlocation != $newlocation ? $oldlocation : NULL,
                $oldlocation != $newlocation ? $newlocation : NULL,
                $me ? $me->getId() : NULL,
                $reviewrequired
            ];

            $changes = 0;
            foreach ($data as $d) {
                if ($d !== NULL) {
                    $changes++;
                }
            }

            # We'll always have 3, from the id, reviewrequired, and me.
            if ($changes > 3) {
                $this->dbhm->preExec("INSERT INTO messages_edits (msgid, oldtext, newtext, oldsubject, newsubject, 
              oldtype, newtype, olditems, newitems, oldimages, newimages, oldlocation, newlocation, byuser, reviewrequired) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);", $data);
            }
        }

        return($ret);
    }

    public function revertEdit($editid) {
        # We revert all outstanding or a specific one
        $idq = $editid ? " AND id = $editid " : " AND reviewrequired = 1 ";

        $edits = $this->dbhr->preQuery("SELECT * FROM messages_edits WHERE msgid = ? $idq ORDER BY id DESC;", [
            $this->id
        ]);

        foreach ($edits as $edit) {
            # We just edit it back to what it was.
            $item = NULL;

            if (Utils::pres('olditems', $edit)) {
                $itemid = json_decode($edit['olditems'], TRUE)[0];
                $i = new Item($this->dbhr, $this->dbhm, $itemid);
                $item = $i->getPrivate('name');
            }

            $this->edit(
                Utils::presdef('oldsubject', $edit, NULL),
                Utils::presdef('oldtext', $edit, NULL),
                Utils::presdef('oldtype', $edit, NULL),
                $item,
                Utils::presdef('oldlocation', $edit, NULL),
                Utils::pres('oldattachments', $edit) ? json_decode($edit['oldattachments'], TRUE) : NULL,
                FALSE
            );

            $this->dbhm->preExec("UPDATE messages_edits SET reviewrequired = 0, revertedat = NOW() WHERE id = ?;", [
                $edit['id']
            ]);
        }
    }

    public function approveEdit($editid) {
        # We approve either all outstanding or a specific one.
        $idq = $editid ? " AND id = $editid " : " AND reviewrequired = 1 ";
        $sql = "UPDATE messages_edits SET reviewrequired = 0, approvedat = NOW() WHERE msgid = ? $idq;";
        $this->dbhm->preExec($sql, [
            $this->id
        ]);
    }

    /** @var  $dbhr LoggedPDO */
    private $dbhr;
    /** @var  $dbhm LoggedPDO */
    private $dbhm;

    private $id, $source, $sourceheader, $message, $textbody, $htmlbody, $subject, $suggestedsubject, $fromname, $fromaddr,
        $replyto, $envelopefrom, $envelopeto, $messageid, $tnpostid, $fromip, $date,
        $fromhost, $type, $attach_dir, $attach_files,
        $parser, $arrival, $spamreason, $spamtype, $fromuser, $fromcountry, $deleted, $heldby, $lat = NULL, $lng = NULL, $locationid = NULL,
        $s, $editedby, $editedat, $modmail, $FOP, $publishconsent, $isdraft, $itemid, $itemname, $availableinitially, $availablenow;

    # These are used in the summary case only where a minimal message is constructed from MessageCollaction.

    private $groups = [];
    private $outcomes = [];
    private $attachments = [];

    /**
     * @return mixed
     */
    public function getModmail()
    {
        return $this->modmail;
    }

    /**
     * @return mixed
     */
    public function getDeleted()
    {
        return $this->deleted;
    }

    # The groupid is only used for parsing and saving incoming messages; after that a message can be on multiple
    # groups as is handled via the messages_groups table.
    private $groupid = NULL;

    private $inlineimgs = [];

    /**
     * @return mixed
     */
    public function getSpamreason()
    {
        return $this->spamreason;
    }

    # Each message has some public attributes, which are visible to API users.
    #
    # Which attributes can be seen depends on the currently logged in user's role on the group.
    #
    # Other attributes are only visible within the server code.
    public $nonMemberAtts = [
        'id', 'subject', 'suggestedsubject', 'type', 'arrival', 'date', 'deleted', 'heldby', 'textbody', 'FOP', 'fromaddr', 'isdraft',
        'lat', 'lng', 'availableinitially', 'availablenow'
    ];

    public $memberAtts = [
        'fromname', 'fromuser', 'modmail'
    ];

    public $moderatorAtts = [
        'source', 'sourceheader', 'envelopefrom', 'envelopeto', 'messageid', 'tnpostid',
        'fromip', 'fromcountry', 'message', 'spamreason', 'spamtype', 'replyto', 'editedby', 'editedat', 'locationid'
    ];

    public $ownerAtts = [
        # Add in a dup for UT coverage of loop below.
        'source'
    ];

    public $internalAtts = [
        'publishconsent', 'itemid', 'itemname', 'lat', 'lng'
    ];

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL, $atts = NULL)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->log = new Log($this->dbhr, $this->dbhm);
        $this->notif = new PushNotifications($this->dbhr, $this->dbhm);

        if ($id) {
            if (!$atts) {
                # We need to fetch.  Similar logic in MessageCollection::fillIn.
                #
                # When constructing we do some LEFT JOINs with other tables where we expect to only have one row at most.
                # This saves queries later, which is a round trip to the DB server.
                #
                # Don't try to cache message info - too many of them.
                $msgs = $dbhr->preQuery("SELECT messages.*, messages_deadlines.FOP, users.publishconsent, CASE WHEN messages_drafts.msgid IS NOT NULL THEN 1 ELSE 0 END AS isdraft, messages_items.itemid AS itemid, items.name AS itemname FROM messages LEFT JOIN messages_deadlines ON messages_deadlines.msgid = messages.id LEFT JOIN users ON users.id = messages.fromuser LEFT JOIN messages_drafts ON messages_drafts.msgid = messages.id LEFT JOIN messages_items ON messages_items.msgid = messages.id LEFT JOIN items ON items.id = messages_items.itemid WHERE messages.id = ?;", [$id]);
                foreach ($msgs as $msg) {
                    $this->id = $id;

                    # FOP defaults on for our messages.
                    if ($msg['source'] == Message::PLATFORM && $msg['type'] == Message::TYPE_OFFER && $msg['FOP'] === NULL) {
                        $msg['FOP'] = 1;
                    }

                    foreach (array_merge($this->nonMemberAtts, $this->memberAtts, $this->moderatorAtts, $this->ownerAtts, $this->internalAtts) as $attr) {
                        if (Utils::pres($attr, $msg)) {
                            $this->$attr = $msg[$attr];
                        }
                    }
                }

                # We parse each time because sometimes we will ask for headers.  Note that if we're not in the initial parse/save of
                # the message we might be parsing from a modified version of the source.
                $this->parser = new \PhpMimeMailParser\Parser();
                $this->parser->setText($this->message);
            } else {
                foreach ($atts as $att => $val) {
                    $this->$att = $val;
                }
            }
        }

        $start = strtotime("30 days ago");
        $this->s = new Search($dbhr, $dbhm, 'messages_index', 'msgid', 'arrival', 'words', 'groupid', $start, 'words_cache');
        $this->si = new Search($dbhr, $dbhm, 'items_index', 'itemid', 'popularity', 'words', 'categoryid', NULL, 'words_cache');
    }

    public function mailer($user, $modmail, $toname, $to, $bcc, $fromname, $from, $subject, $text) {
        # These mails don't need tracking, so we don't call addHeaders.
        try {
            #error_log(session_id() . " mail " . microtime(true));

            list ($transport, $mailer) = Mail::getMailer();
            
            $message = \Swift_Message::newInstance()
                ->setSubject($subject)
                ->setFrom([$from => $fromname])
                ->setTo([$to => $toname])
                ->setBody($text);

            # We add some headers so that if we receive this back, we can identify it as a mod mail.
            $headers = $message->getHeaders();

            if ($user) {
                $headers->addTextHeader('X-Iznik-From-User', $user->getId());
            }

            $headers->addTextHeader('X-Iznik-ModMail', $modmail);

            if ($bcc) {
                $message->setBcc(explode(',', $bcc));
            }

            $mailer->send($message);

            # Stop the transport, otherwise the message doesn't get sent until the UT script finishes.
            $transport->stop();

            #error_log(session_id() . " mailed " . microtime(true));
        } catch (\Exception $e) {
            # Not much we can do - shouldn't really happen given the failover transport.
            // @codeCoverageIgnoreStart
            error_log("Send failed with " . $e->getMessage());
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * Get the roles for multiple messages.
     *
     * @param bool Allow system role overrides
     * @param null $me
     * @param int $myid
     * @param array $msgs
     * @return mixed
     */
    public function getRolesForMessages($me = NULL, $msgs, $overrides = TRUE) {
        $me = $me ? $me : Session::whoAmI($this->dbhr, $this->dbhm);
        $ret = [];
        $groups = NULL;
        $groupid = NULL;

        foreach ($msgs as $msg) {
            # Our role for a message is the highest role we have on any group that this message is on.  That means that
            # we have limited access to information on other groups of which we are not a moderator, but that is legitimate
            # if the message is on our group.
            #
            # We might also be a partner, which allows us to appear like a member rather than a non-member.
            $role = User::ROLE_NONMEMBER;

            if (Utils::pres('partner', $_SESSION)) {
                # Partners always get at least member rights.
                $role = User::ROLE_MEMBER;

                if (Utils::pres('partnerdomain', $_SESSION) && strpos($msg['fromaddr'], '@' . $_SESSION['partnerdomain']) !== FALSE) {
                    # It's from the partner domain, so they have full access.
                    $role = User::ROLE_OWNER;
                }
            }

            if ($me) {
                if ($me->getId() == $msg['fromuser']) {
                    # It's our message.  We have full rights.
                    $role = User::ROLE_MODERATOR;
                } else {
                    if (!$groups) {
                        $msgids = array_filter(array_column($msgs, 'id'));
                        $sql = "SELECT role, messages_groups.groupid, messages_groups.collection, messages_groups.msgid FROM memberships
                              INNER JOIN messages_groups ON messages_groups.groupid = memberships.groupid
                                  AND userid = ? AND messages_groups.msgid IN (" . implode(',', $msgids) . ");";
                        $groups = $this->dbhr->preQuery($sql, [
                            $me->getId()
                        ]);
                    }

                    #error_log("$sql {$this->id}, " . $me->getId() . " " . var_export($groups, TRUE));

                    foreach ($groups as $group) {
                        if ($msg['id'] === $group['msgid']) {
                            switch ($group['role']) {
                                case User::ROLE_OWNER:
                                    # Owner is highest.
                                    $role = $group['role'];
                                    break;
                                case User::ROLE_MODERATOR:
                                    # Upgrade from member or non-member to mod.
                                    $role = ($role == User::ROLE_MEMBER || $role == User::ROLE_NONMEMBER) ? User::ROLE_MODERATOR : $role;
                                    break;
                                case User::ROLE_MEMBER:
                                    # Just a member
                                    $role = User::ROLE_MEMBER;
                                    break;
                            }

                            $groupid = $group['groupid'];
                        }
                    }

                    if ($overrides) {
                        switch ($me->getPrivate('systemrole')) {
                            case User::SYSTEMROLE_SUPPORT:
                                $role = User::ROLE_MODERATOR;
                                break;
                            case User::SYSTEMROLE_ADMIN:
                                $role = User::ROLE_OWNER;
                                break;
                        }
                    }
                }
            }

            if ($role == User::ROLE_NONMEMBER && Utils::presdef('isdraft', $msg, FALSE)) {
                # We can potentially upgrade our role if this is one of our drafts.
                $drafts = $this->dbhr->preQuery("SELECT * FROM messages_drafts WHERE msgid = ? AND session = ? OR (userid = ? AND userid IS NOT NULL);", [
                    $msg['id'],
                    session_id(),
                    $me ? $me->getId() : NULL
                ]);

                foreach ($drafts as $draft) {
                    $role = User::ROLE_MODERATOR;
                }
            }

            $ret[$msg['id']] = [ $role, $groupid ];
        }

        return($ret);
    }

    /**
     * Get the role for this message.
     *
     * @param bool Allow system role overrides
     * @param null $me
     * @return mixed
     */
    public function getRoleForMessage($overrides = TRUE, $me = NULL) {
        # Use the multi-message method.
        return($this->getRolesForMessages($me, $this->getThisAsArray(), $overrides)[$this->id]);
    }

    public function canSees($msgs) {
        # Can we see these messages?  This is called after getPublic because most of the time we can, and doing
        # that saves queries.
        $cansees = [];
        $drafts = NULL;

        foreach ($msgs as $atts) {
            # We can see messages if:
            # - we're a mod or an owner, or
            # - it's a message on a Freegle group, specifically including
            #    - Pending to allow Facebook share preview, and
            #    - TrashNothing message (the TN TOS allows this).
            $role = $atts['myrole'];

            $cansee = $role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER;

            if (!$cansee) {
                foreach ($atts['groups'] as $group) {
                    $g = Group::get($this->dbhr, $this->dbhm, $group['groupid']);
                    #error_log("Consider show " . $this->getID());
                    #error_log("...plat or TN " . ($this->getSourceheader() == Message::PLATFORM || strpos($this->getFromaddr(), '@user.trashnothing.com') !== FALSE));
                    #error_log("...consent || member " . ($atts['publishconsent'] || $role == User::ROLE_MEMBER));
                    #error_log("...coll == APPROVED " . ($group['collection'] == MessageCollection::APPROVED));
                    #error_log("...type == FREEGLE " . ($g->getPrivate('type') == Group::GROUP_FREEGLE));
                    #error_log("...onhere " . $g->getPrivate('onhere'));

                    if ($g->getPrivate('type') == Group::GROUP_FREEGLE) {
                        $cansee = TRUE;
                    }
                }
            }

            #error_log("Cansee now $cansee");

            if (!$cansee) {
                # We can see our drafts.
                if ($drafts === NULL) {
                    $drafts = [];

                    $me = Session::whoAmI($this->dbhr, $this->dbhm);
                    if ($me) {
                        $msgids = array_filter(array_column($msgs, 'id'));
                        $drafts = $this->dbhr->preQuery("SELECT * FROM messages_drafts WHERE msgid IN (" . implode(',', $msgids) . ") AND session = ? OR (userid = ? AND userid IS NOT NULL);", [
                            session_id(),
                            $me->getId()
                        ]);
                    }
                }

                foreach ($drafts as $draft) {
                    if ($draft['msgid'] == $atts['id']) {
                        $cansee = TRUE;
                    }
                }
            }

            $cansees[$atts['id']] = $cansee;
        }

        return($cansees);
    }

    public function canSee($atts) {
        $cansees = $this->canSees([ $atts ]);
        return($cansees[$atts['id']]);
    }

    public function stripGumf() {
        $text = $this->getTextbody();

        if ($text) {
            // console.log("Strip photo", text);
            // Strip photo links - we should have those as attachments.
            $text = preg_replace('/You can see a photo[\s\S]*?jpg/', '', $text);
            $text = preg_replace('/Check out the pictures[\s\S]*?https:\/\/trashnothing[\s\S]*?pics\/[a-zA-Z0-9]*/', '', $text);
            $text = preg_replace('/You can see photos here[\s\S]*jpg/m', '', $text);
            $text = preg_replace('/https:\/\/direct.*jpg/m', '', $text);
            $text = preg_replace('/Photos\:[\s\S]*?jpg/', '', $text);

            // FOPs
            $text = preg_replace('/Fair Offer Policy applies \(see https:\/\/[\s\S]*\)/', '', $text);
            $text = preg_replace('/Fair Offer Policy:[\s\S]*?reply./', '', $text);

            // App footer
            $text = preg_replace('/Freegle app.*[0-9]$/m', '', $text);

            // Footers
            $text = preg_replace('/--[\s\S]*Get Freegling[\s\S]*book/m', '', $text);
            $text = preg_replace('/--[\s\S]*Get Freegling[\s\S]*org[\s\S]*?<\/a>/m', '', $text);
            $text = preg_replace('/This message was sent via Freegle Direct[\s\S]*/m', '', $text);
            $text = preg_replace('/\[Non-text portions of this message have been removed\]/m', '', $text);
            $text = preg_replace('/^--$[\s\S]*/m', '', $text);
            $text = preg_replace('/==========/m', '', $text);

            // Redundant line breaks.
            $text = preg_replace('/(?:(?:\r\n|\r|\n)\s*){2}/s', "\n\n", $text);

            // Duff text added by Yahoo Mail app.
            $text = str_replace('blockquote, div.yahoo_quoted { margin-left: 0 !important; border-left:1px #715FFA solid !important; padding-left:1ex !important; background-color:white !important; }', '', $text);

            // Left over inline image references
            $text = preg_replace('/\[cid\:.*?\]/', '', $text);

            $text = trim($text);
        }
        
        return($text ? $text : '');
    }

    public function getLocation($locationid, &$locationlist) {
        if (!$locationlist || !count($locationlist) || !array_key_exists($locationid, $locationlist)) {
            $locationlist[$locationid] = new Location($this->dbhr, $this->dbhm, $locationid);
        }

        return($locationlist[$locationid]);
    }

    public function promiseCount() {
        $sql = "SELECT COUNT(*) AS count FROM messages_promises WHERE msgid = ?;";
        $promises = $this->dbhr->preQuery($sql, [$this->id]);
        return($promises[0]['count']);
    }

    private function getPublicAtts($me, $myid, $msgs, $roles, $seeall, $summary) {
        # Get the attributes which are visible based on our role.
        $rets = [];

        foreach ($msgs as $msg) {
            $role = $roles[$msg['id']][0];
            $ret = [];
            $ret['myrole'] = $role;

            foreach ($this->nonMemberAtts as $att) {
                $ret[$att] = Utils::presdef($att, $msg, NULL);
            }

            if ($role == User::ROLE_MEMBER || $role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER || $seeall) {
                foreach ($this->memberAtts as $att) {
                    $ret[$att] = Utils::presdef($att, $msg, NULL);
                }
            }

            if ($role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER || $seeall) {
                foreach ($this->moderatorAtts as $att) {
                    $ret[$att] = Utils::presdef($att, $msg, NULL);
                }
            }

            if ($role == User::ROLE_OWNER || $seeall) {
                foreach ($this->ownerAtts as $att) {
                    $ret[$att] = Utils::presdef($att, $msg, NULL);
                }
            }

            if ($role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER) {
                $this->checkLoveJunk($ret);
            }

            $blur = TRUE;

            if (Utils::pres('partner', $_SESSION)) {
                # We might have given consent to this partner to see more info.
                $consents = $this->dbhr->preQuery("SELECT * FROM partners_messages WHERE partnerid = ? AND msgid = ?", [
                    $_SESSION['partner']['id'],
                    $msg['id']
                ]);

                foreach ($consents as $consent) {
                    $blur = FALSE;
                }
            }

            if ($blur && ($role === User::ROLE_NONMEMBER || $role === User::ROLE_MEMBER)) {
                // Blur lat/lng slightly for privacy.
                list ($ret['lat'], $ret['lng']) = Message::blur($ret['lat'], $ret['lng']);
            }

            # URL people can follow to get to the message on our site.
            $ret['url'] = 'https://' . USER_SITE . '/message/' . $msg['id'];

            $ret['mine'] = $myid && $msg['fromuser'] == $myid;

            # If we are a mod with sufficient rights on this message, we can edit it.
            #
            # In the non-summary case we may be able to for our own messages, lower down.
            $ret['canedit'] = $myid && $me->isModerator() && ($role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER);

            # Remove any group subject tag.
            $ret['subject'] = preg_replace('/^\[.*?\]\s*/', '', $ret['subject']);
            $ret['subject'] = preg_replace('/\[.*Attachment.*\]\s*/', '', $ret['subject']);

            # Decode any HTML which is wrongly in there.
            $ret['subject'] = html_entity_decode($ret['subject']);

            # Add derived attributes.
            $ret['arrival'] = Utils::ISODate($ret['arrival']);
            $ret['date'] = Utils::ISODate($ret['date']);
            $ret['daysago'] = floor((time() - strtotime($ret['date'])) / 86400);
            $ret['snippet'] = Utils::pres('textbody', $ret) ? substr($ret['textbody'], 0, 60) : null;

            if (Utils::pres('fromcountry', $ret)) {
                $ret['fromcountry'] = Utils::code_to_country($ret['fromcountry']);
            }

            # TODO Is this still relevant?
            $ret['publishconsent'] = Utils::pres('publishconsent', $msg) ? TRUE : FALSE;

            if (!$summary) {
                if ($role == User::ROLE_NONMEMBER) {
                    # For non-members we want to strip out any potential phone numbers or email addresses.
                    $ret['textbody'] = preg_replace('/[0-9]{4,}/', '***', $ret['textbody']);
                    $ret['textbody'] = preg_replace(Message::EMAIL_REGEXP, '***@***.com', $ret['textbody']);
                }

                # We have a flag for FOP - but legacy posting methods might put it in the body.
                $ret['FOP'] = (Utils::pres('textbody', $ret) && (strpos($ret['textbody'], 'Fair Offer Policy') !== FALSE) || $ret['FOP']) ? 1 : 0;
            }

            $rets[$msg['id']] = $ret;
        }

        return($rets);
    }

    public function checkLoveJunk(&$ret) {
        if ($ret['lat'] || $ret['lng']) {
            # Check if this is a possibility for lovejunk.
            if (!$this->loveJunkPoly) {
                $this->loveJunkPoly = \geoPHP::load(self::LOVEJUNK_AREA, 'wkt');
            }

            $point = \geoPHP::load("POINT({$ret['lng']} {$ret['lat']})", 'wkt');

            if ($this->loveJunkPoly->contains($point)) {
                # Add in the hashed value of the ID which can be used to refer to LoveJunk.
                $ret['lovejunkhash'] = defined('LOVEJUNK_SECRET') ? hash_hmac('sha256', $ret['id'], LOVEJUNK_SECRET, FALSE) : NULL;
            }
        }
    }

    public static function blur($lat, $lng) {
        $lat = round($lat / 2, User::BLUR_100M) * 2;
        $lng = round($lng / 2, User::BLUR_100M) * 2;
        return [ $lat, $lng ];
    }

    private function getThisAsArray() {
        # This is a rather hacky function.  We have methods which work on an array of message attributes, and we
        # also have an instance of Message which represents a single one, where the attributes are properties of the
        # object.  This method allows us to convert an object into an array for use with those methods.
        #
        # In retrospect we would implement Message rather differently.
        $ret = [];

        foreach (array_merge($this->nonMemberAtts, $this->memberAtts, $this->moderatorAtts, $this->ownerAtts, $this->internalAtts) as $attr) {
            if (property_exists($this, $attr)) {
                $ret[$attr] = $this->$attr;
            }
        }

        $ret['attachments'] = $this->attachments;
        return([$ret]);
    }

    public function getPublicGroups($me, $myid, &$userlist, &$rets, $msgs, $roles, $summary, $seeall) {
        $msgids = array_filter(array_column($msgs, 'id'));
        $groups = NULL;
        $approvedcache = [];

        foreach ($msgs as $msg) {
            $role = $roles[$msg['id']][0];

            if (!$summary) {
                # In the summary case we fetched the groups in MessageCollection.  Otherwise we won't have fetched the groups yet.
                if ($groups === NULL) {
                    $groups = [];

                    if ($msgids) {
                        $sql = "SELECT *, TIMESTAMPDIFF(HOUR, arrival, NOW()) AS hoursago FROM messages_groups WHERE msgid IN (" . implode (',', $msgids ) . ") AND deleted = 0;";
                        $groups = $this->dbhr->preQuery($sql, NULL, FALSE, FALSE);
                    }
                }

                $retgroups = [];
                foreach ($groups as $group) {
                    if ($group['msgid'] == $rets[$msg['id']]['id']) {
                        #error_log("Add message {$msg['id']} {$msg['subject']} group {$group['msgid']}, {$rets[$msg['id']]['id']} val {$group['groupid']}");
                        $retgroups[] = $group;
                    }
                }

                $rets[$msg['id']]['groups'] = $retgroups;
            } else {
                $rets[$msg['id']]['groups'] = Utils::presdef('groups', $msg, []);
            }

            $rets[$msg['id']]['showarea'] = TRUE;
            $rets[$msg['id']]['showpc'] = TRUE;

            # We don't use foreach with & because that copies data by reference which causes bugs.
            for ($groupind = 0; $groupind < count($rets[$msg['id']]['groups']); $groupind++ ) {
                if ($role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER || $seeall) {
                    if (Utils::pres('approvedby', $rets[$msg['id']]['groups'][$groupind])) {
                        if (!Utils::pres($rets[$msg['id']]['groups'][$groupind]['approvedby'], $approvedcache)) {
                            if ($rets[$msg['id']]['groups'][$groupind]['approvedby'] === $myid) {
                                # This saves a DB op in a common case for an active mod.
                                $approvedcache[$rets[$msg['id']]['groups'][$groupind]['approvedby']] = [
                                    'id' => $myid,
                                    'displayname' => 'you'
                                ];
                            } else {
                                $appby = $this->dbhr->preQuery("SELECT id, fullname, firstname, lastname FROM users WHERE id = ?;", [
                                    $rets[$msg['id']]['groups'][$groupind]['approvedby']
                                ]);

                                foreach ($appby as $app) {
                                    $name = Utils::pres('fullname', $app) ? $app['fullname'] : "{$app['firstname']} {$app['lastname']}";
                                    $approvedcache[$rets[$msg['id']]['groups'][$groupind]['approvedby']] = [
                                        'id' => $rets[$msg['id']]['groups'][$groupind]['approvedby'],
                                        'displayname' => $name
                                    ];
                                }
                            }

                        }

                        $rets[$msg['id']]['groups'][$groupind]['approvedby'] = $approvedcache[$rets[$msg['id']]['groups'][$groupind]['approvedby']];
                    }
                }

                $rets[$msg['id']]['groups'][$groupind]['arrival'] = Utils::ISODate($rets[$msg['id']]['groups'][$groupind]['arrival']);
                $g = Group::get($this->dbhr, $this->dbhm, $rets[$msg['id']]['groups'][$groupind]['groupid']);
                $rets[$msg['id']]['groups'][$groupind]['namedisplay'] = $g->getName();
                #error_log("Message {$group['msgid']} {$group['groupid']} {$group['namedisplay']}");

                # Work out when this message should be deemed as expired and no longer show.
                #
                # We use the max time here - reposts can finish earlier.
                $maxagetoshow = $g->getSetting('maxagetoshow', Message::EXPIRE_TIME);
                $reposts = $g->getSetting('reposts', [ 'offer' => 3, 'wanted' => 14, 'max' => 10, 'chaseups' => 2]);
                $repost = $msg['type'] == Message::TYPE_OFFER ? $reposts['offer'] : $reposts['wanted'];
                $maxreposts = $repost * ($reposts['max'] + 1);
                $rets[$msg['id']]['expiretime'] = max($maxreposts, $maxagetoshow);

                if (array_key_exists('canedit', $rets[$msg['id']]) && !$rets[$msg['id']]['canedit'] && $myid && $myid === $msg['fromuser']) {
                    # This is our own message, which we may be able to edit if the group allows it.  Allow this even if
                    # it wasn't originally posted on here.
                    $allowedits = $g->getSetting('allowedits', [ 'moderated' => TRUE, 'group' => TRUE ]);
                    $ourPS = $me->getMembershipAtt($rets[$msg['id']]['groups'][$groupind]['groupid'], 'ourPostingStatus');

                    if (((!$ourPS || $ourPS === Group::POSTING_MODERATED) && $allowedits['moderated']) ||
                        ($ourPS === Group::POSTING_DEFAULT && $allowedits['group'])) {
                        # Yes, we can edit.
                        $rets[$msg['id']]['canedit'] = TRUE;
                    }
                }

                if (!$summary) {
                    $keywords = $g->getSetting('keywords', $g->defaultSettings['keywords']);
                    $rets[$msg['id']]['keyword'] = Utils::presdef(strtolower($msg['type']), $keywords, $msg['type']);

                    # Some groups disable the area or postcode.  If so, hide that.
                    $includearea = $g->getSetting('includearea', TRUE);
                    $includepc = $g->getSetting('includepc', TRUE);
                    $rets[$msg['id']]['showarea'] = !$includearea ? FALSE : $rets[$msg['id']]['showarea'];
                    $rets[$msg['id']]['showpc'] = !$includepc ? FALSE : $rets[$msg['id']]['showpc'];

                    if (Utils::pres('mine', $rets[$msg['id']])) {
                        # Can we repost?
                        $rets[$msg['id']]['canrepost'] = FALSE;

                        $reposts = $g->getSetting('reposts', ['offer' => 3, 'wanted' => 7, 'max' => 5, 'chaseups' => 5]);
                        $interval = $msg['type'] == Message::TYPE_OFFER ? $reposts['offer'] : $reposts['wanted'];
                        $arrival = strtotime($rets[$msg['id']]['groups'][$groupind]['arrival']);

                        if ($interval < 365) {
                            # Some groups set very high values as a way of turning this off.
                            $repostat = $arrival + $interval * 3600 * 24;

                            if ($repostat < time()) {
                                # Hit max number of autoreposts.
                                $repostat = time();
                                $rets[$msg['id']]['willautorepost'] = FALSE;
                            }

                            $rets[$msg['id']]['canrepostat'] = Utils::ISODate("@$repostat");

                            if ($rets[$msg['id']]['groups'][$groupind]['hoursago'] > $interval * 24) {
                                $rets[$msg['id']]['canrepost'] = TRUE;
                            }
                        }
                    }
                }
            }
        }
    }

    public function getPublicLocation($myid, &$rets, $msgs, $roles, $seeall, &$locationlist) {
        $l = new Location($this->dbhr, $this->dbhm);

        # Cache the locations we'll need efficiently.
        $locids = array_filter(array_column($msgs, 'locationid'));
        $l->getByIds($locids, $locationlist);

        foreach ($msgs as $msg) {
            $role = $roles[$msg['id']][0];

            if (Utils::pres('locationid', $msg)) {
                $l = $this->getLocation($msg['locationid'], $locationlist);

                # We can always see any area and top-level postcode.  If we're a mod or this is our message
                # we can see the precise location.
                if (Utils::pres('showarea', $rets[$msg['id']])) {
                    $areaid = $l->getPrivate('areaid');
                    if ($areaid) {
                        # This location is quite specific.  Return the area it's in.
                        $a = $this->getLocation($areaid, $locationlist);
                        $rets[$msg['id']]['area'] = $a->getPublic();
                    } else {
                        # This location isn't in an area; it is one.  Return it.
                        $rets[$msg['id']]['area'] = $l->getPublic();
                    }
                }

                if (Utils::pres('showpc', $rets[$msg['id']])) {
                    $pcid = $l->getPrivate('postcodeid');
                    if ($pcid) {
                        $p = $this->getLocation($pcid, $locationlist);
                        $rets[$msg['id']]['postcode'] = $p->getPublic();
                    }
                }

                # Can see the location if we have been asked to, if we're a mod, if it's our message, or we have
                # consent for this partner.
                $showloc = $seeall || $role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER || ($myid && $msg['fromuser'] == $myid);

                if (!$showloc && Utils::pres('partner', $_SESSION)) {
                    # We might have given consent to this partner to see more info.
                    $consents = $this->dbhr->preQuery("SELECT * FROM partners_messages WHERE partnerid = ? AND msgid = ?", [
                        $_SESSION['partner']['id'],
                        $msg['id']
                    ]);

                    foreach ($consents as $consent) {
                        $showloc = TRUE;
                    }
                }

                if ($showloc) {
                    $rets[$msg['id']]['location'] = $l->getPublic();
                }
            }

            unset($rets[$msg['id']]['showarea']);
            unset($rets[$msg['id']]['showpc']);
        }
    }

    public function getPublicItem(&$rets, $msgs) {
        $search = [];

        foreach ($msgs as $msg) {
            if (Utils::pres('itemid', $msg)) {
                $rets[$msg['id']]['item'] = [
                    'id' => $msg['itemid'],
                    'name' => $msg['itemname']
                ];
            } else if (preg_match("/(.+)\:(.+)\((.+)\)/", $rets[$msg['id']]['subject'], $matches)) {
                # See if we can find it.
                $item = trim($matches[2]);
                $itemid = NULL;
                $search[] = [
                    'id' => $msg['id'],
                    'item' => $item
                ];
            }
        }

        if (count($search)) {
            $sql = "";
            foreach ($search as $s) {
                if ($sql !== '') {
                    $sql .= " UNION ";
                }

                $sql .= "SELECT items.id, items.name FROM items WHERE name LIKE " . $this->dbhr->quote($s['item']);
            }

            $items = $this->dbhr->preQuery($sql, NULL, FALSE, FALSE);

            foreach ($items as $item) {
                foreach ($rets as &$ret) {
                    if (!Utils::pres('item', $ret) && $ret['id'] == $s['id']) {
                        $ret['item'] = [
                            'id' => $item['id'],
                            'name' => $item['name']
                        ];
                    }
                }
            }
        }
    }

    public function getPublicReplies($me, $myid, &$rets, $msgs, $summary, $roles, $seeall, $messagehistory) {
        $allreplies = NULL;
        $lastreplies = NULL;
        $allpromises = NULL;
        $replyusers = [];

        foreach ($msgs as $msg) {
            $role = $roles[$msg['id']][0];

            if ($summary) {
                # We set this when constructing from MessageCollection.
                $rets[$msg['id']]['replycount'] = Utils::presdef('replycount', $msg, 0);
            } else if (!$summary) {
                if ($allreplies === NULL) {
                    # Get all the replies for these messages.
                    $msgids = array_filter(array_column($msgs, 'id'));
                    $allreplies = [];

                    if (count($msgids)) {
                        $sql = "SELECT DISTINCT t.* FROM (
SELECT chat_messages.id, chat_messages.refmsgid, chat_roster.status , chat_messages.userid, chat_messages.chatid, MAX(chat_messages.date) AS lastdate FROM chat_messages 
LEFT JOIN chat_roster ON chat_messages.chatid = chat_roster.chatid AND chat_roster.userid = chat_messages.userid
INNER JOIN chat_rooms ON chat_rooms.id = chat_messages.chatid 
INNER JOIN messages ON messages.id = chat_messages.refmsgid
WHERE refmsgid IN (" . implode(',', $msgids) . ") AND (messages.fromuser = chat_rooms.user1 OR messages.fromuser = chat_rooms.user2) AND reviewrejected = 0 AND reviewrequired = 0 AND chat_messages.type = ? GROUP BY chat_messages.userid, chat_messages.chatid, chat_messages.refmsgid) t 
ORDER BY lastdate DESC;";

                        $res = $this->dbhr->preQuery($sql, [
                            ChatMessage::TYPE_INTERESTED
                        ], NULL, FALSE);

                        foreach ($res as $r) {
                            if (!Utils::pres($r['refmsgid'], $allreplies)) {
                                $allreplies[$r['refmsgid']] = [$r];
                            } else {
                                $allreplies[$r['refmsgid']][] = $r;
                            }
                        }

                        $userids = array_filter(array_column($res, 'userid'));
                        if (count($userids)) {
                            $u = new User($this->dbhr, $this->dbhm);
                            $replyusers = $u->getPublicsById($userids, NULL, $messagehistory, Session::modtools(), Session::modtools(), Session::modtools(), Session::modtools(), FALSE, [MessageCollection::APPROVED], FALSE);
                            $u->getInfos($replyusers);
                        }
                    }
                }

                # Can always see the replycount.  The count should include even people who are blocked.
                $replies = Utils::presdef($msg['id'], $allreplies, []);
                $rets[$msg['id']]['replies'] = [];
                $rets[$msg['id']]['replycount'] = count($replies);

                # Can see replies if:
                # - we want everything
                # - we're on ModTools and we're a mod for this message
                # - it's our message
                if ($seeall || (Session::modtools() && ($role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER)) || ($myid && $msg['fromuser'] == $myid)) {
                    # Add replies, as long as they're not awaiting review or rejected, or blocked.
                    $ourreplies = [];
                    foreach ($replies as $reply) {
                        $ctx = NULL;
                        if ($reply['userid'] && $reply['status'] != ChatRoom::STATUS_BLOCKED) {
                            $thisone = [
                                'id' => $reply['id'],
                                'user' => $replyusers[$reply['userid']],
                                'chatid' => $reply['chatid']
                            ];

                            # Add the last reply date and a snippet.
                            if (!$lastreplies) {
                                # Get the last replies for all the relevant chats.
                                $chatids = [];
                                foreach ($allreplies as $allreplyid => $allreply) {
                                    $chatids = array_merge($chatids, array_filter(array_column($allreply, 'chatid')));
                                }

                                $sql = "SELECT DISTINCT m1.* FROM chat_messages m1 LEFT JOIN chat_messages m2 ON (m1.chatid = m2.chatid AND m1.id < m2.id) WHERE m2.id IS NULL AND m1.chatid IN (" . implode(',', $chatids) . ");";
                                $lastreplies = $this->dbhr->preQuery($sql, NULL, FALSE, FALSE);
                            }

                            foreach ($lastreplies as $lastreply) {
                                if ($lastreply['chatid'] == $reply['chatid']) {
                                    $thisone['lastdate'] = Utils::ISODate($lastreply['date']);
                                    $thisone['lastuserid'] = $lastreply['userid'];

                                    $r = new ChatRoom($this->dbhr, $this->dbhm);
                                    $thisone['snippet'] = $r->getSnippet($lastreply['type'], $lastreply['message']);
                                }
                            }

                            $ourreplies[] = $thisone;;
                        }
                    }

                    $rets[$msg['id']]['replies'] = $ourreplies;

                    # Whether or not we will auto-repost depends on whether there are replies.
                    $rets[$msg['id']]['willautorepost'] = count($rets[$msg['id']]['replies']) == 0;

                    $rets[$msg['id']]['promisecount'] = 0;
                }

                if ($msg['type'] == Message::TYPE_OFFER) {
                    # Add promises, i.e. one or more people we've said can have this.
                    if (!$allpromises) {
                        $msgids = array_filter(array_column($msgs, 'id'));
                        $sql = "SELECT * FROM messages_promises WHERE msgid IN (" . implode(',', $msgids) . ") ORDER BY id DESC;";
                        $ps = $this->dbhr->preQuery($sql, NULL, FALSE, FALSE);
                        $allpromises = [];

                        foreach ($ps as $p) {
                            if (!Utils::pres($p['msgid'], $allpromises)) {
                                $allpromises[$p['msgid']] = [ $p ];
                            } else {
                                $allpromises[$p['msgid']][] = $p;
                            }
                        }
                    }

                    $promises = Utils::presdef($msg['id'], $allpromises, []);

                    if ($seeall || (Session::modtools() && ($role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER)) || ($myid && $msg['fromuser'] == $myid)) {
                        $rets[$msg['id']]['promises'] = $promises;

                        foreach ($rets[$msg['id']]['replies'] as $key => $reply) {
                            foreach ($rets[$msg['id']]['promises'] as $promise) {
                                $rets[$msg['id']]['replies'][$key]['promised'] = Utils::presdef('promised', $reply, FALSE) || ($promise['userid'] == $reply['user']['id']);
                            }
                        }
                    }

                    $rets[$msg['id']]['promisecount'] = count($promises);
                    $rets[$msg['id']]['promised'] = count($promises) > 0;
                }
            }
        }
    }

    public function getPublicOutcomes($me, $myid, &$rets, $msgs, $summary, $roles, $seeall) {
        $outcomes = NULL;

        foreach ($msgs as $msg) {
            $role = $roles[$msg['id']][0];
            $rets[$msg['id']]['outcomes'] = [];

            # Add any outcomes.  No need to expand the user as any user in an outcome should also be in a reply.
            if ($summary) {
                # We set this when constructing.
                $rets[$msg['id']]['outcomes'] = Utils::presdef('outcomes', $msg, []);
            } else {
                if ($outcomes === NULL) {
                    $msgids = array_filter(array_column($msgs, 'id'));
                    $outcomes = [];

                    if (count($msgids)) {
                        $sql = "SELECT * FROM messages_outcomes WHERE msgid IN (" . implode(',', $msgids) . ") ORDER BY id DESC;";
                        $outcomes = $this->dbhr->preQuery($sql, [ $msg['id'] ]);
                    }
                }

                foreach ($outcomes as &$outcome) {
                    if ($outcome['msgid'] == $msg['id']) {
                        # We can only see the details of the outcome if we have access.
                        if (!($seeall || ($role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER) || ($myid && $msg['fromuser'] == $myid))) {
                            $outcome['userid'] = NULL;
                            $outcome['happiness'] = NULL;
                            $outcome['comments'] = NULL;
                        }

                        $outcome['timestamp'] = Utils::ISODate($outcome['timestamp']);

                        $rets[$msg['id']]['outcomes'][] = $outcome;
                    }
                }
            }

            if (count($rets[$msg['id']]['outcomes']) === 0) {
                # No outcomes - but has it expired?  Need to check the groups though - it might be reposted later, in
                # which case the time on messages_groups is bumped whereas the message arrival time is the same..
                foreach ($rets[$msg['id']]['groups'] as $group) {
                    $grouparrival = strtotime($group['arrival']);
                    $grouparrivalago = floor((time() - $grouparrival) / 86400);

                    if ($grouparrivalago > Utils::presdef('expiretime', $rets[$msg['id']], 0)) {
                        # Assume anything this old is no longer available.
                        $expiredat = Utils::ISODate('@' . (strtotime($group['arrival']) + $rets[$msg['id']]['expiretime'] * 86400));

                        $rets[$msg['id']]['outcomes'] = [
                            [
                                'timestamp' => $expiredat,
                                'outcome' => Message::OUTCOME_EXPIRED
                            ]
                        ];
                    }
                }
            }

            unset($rets[$msg['id']]['expiretime']);
        }
    }

    public function getPublicFromUser(&$rets, $msgs, $roles, $messagehistory, $me, $myid) {
        # Get all the fromusers in a single call - saves on DB ops.
        $u = new User($this->dbhr, $this->dbhm);
        $fromuids = [];
        $groupids = [];

        foreach ($rets as $ret) {
            if (Utils::pres('groups', $ret)) {
                foreach ($ret['groups'] as $group) {
                    $groupids[] = $group['groupid'];
                }
            }
        }

        $groupids = array_unique($groupids);

        $fromusers = [];

        foreach ($msgs as $msg) {
            if (Utils::pres('fromuser', $msg)) {
                $fromuids[] = $msg['fromuser'];
            }
        }

        $fromuids = array_unique($fromuids);
        $emails = count($fromuids) ? $u->getEmailsById($fromuids) : [];

        if (count($fromuids)) {
            $fromusers = $u->getPublicsById($fromuids, $groupids, $messagehistory, Session::modtools(), Session::modtools(), Session::modtools(), Session::modtools(), FALSE, [ MessageCollection::APPROVED ], FALSE);
            $u->getInfos($fromusers);
        }

        foreach ($msgs as $msg) {
            $role = $roles[$msg['id']][0];

            if (Utils::pres('fromuser', $rets[$msg['id']])) {
                # We know who sent this.  We may be able to return this (depending on the role we have for the message
                # and hence the attributes we have already filled in).  We also want to know if we have consent
                # to republish it.
                $rets[$msg['id']]['fromuser'] = $fromusers[$rets[$msg['id']]['fromuser']];

                if ($role == User::ROLE_OWNER || $role == User::ROLE_MODERATOR) {
                    # We can see their emails.
                    if (Utils::pres($msg['fromuser'], $emails)) {
                        $rets[$msg['id']]['fromuser']['emails'] = $emails[$msg['fromuser']];
                    }
                } else if (Utils::pres('partner', $_SESSION)) {
                    # Partners can see emails which belong to us, for the purposes of replying.
                    $es = $emails[$msg['fromuser']];
                    $rets[$msg['id']]['fromuser']['emails'] = [];
                    foreach ($es as $email) {
                        if (Mail::ourDomain($email['email'])) {
                            $rets[$msg['id']]['fromuser']['emails'][] = $email;
                        }
                    }
                } else {
                    # We should hide the emails.
                    $rets[$msg['id']]['fromuser']['emails'] = NULL;
                }

                Utils::filterResult($rets[$msg['id']]['fromuser']);
            }
        }
    }

    public function getPublicRelated(&$rets, $msgs) {
        $msgids = array_filter(array_column($msgs, 'id'));
        $relateds = [];

        if (count($msgids)) {
            $relateds = $this->dbhr->preQuery("SELECT * FROM messages_related WHERE id1 IN (" . implode(',', $msgids) . ") OR id2  IN (" . implode(',', $msgids) . ");");
        }

        foreach ($msgs as $msg) {
            # Add any related messages
            $rets[$msg['id']]['related'] = [];
            $relids = [];

            foreach ($relateds as $rel) {
                if ($rel['id1'] == $msg['id'] || $rel['id2'] == $msg['id']) {
                    $id = $rel['id1'] == $msg['id'] ? $rel['id2'] : $rel['id1'];

                    if (!array_key_exists($id, $relids)) {
                        $m = new Message($this->dbhr, $this->dbhm, $id);
                        $rets[$msg['id']]['related'][] = $m->getPublic(FALSE, FALSE);
                        $relids[$id] = TRUE;
                    }
                }
            }
        }
    }

    public function getPublicHeld(&$rets, $msgs, $messagehistory) {
        foreach ($msgs as $msg) {
            if (Utils::pres('heldby', $rets[$msg['id']])) {
                $u = User::get($this->dbhr, $this->dbhm, $rets[$msg['id']]['heldby']);
                $rets[$msg['id']]['heldby'] = $u->getPublic(NULL, FALSE, Session::modtools(), Session::modtools(), Session::modtools(), FALSE, FALSE);
                Utils::filterResult($rets[$msg['id']]);
            }
        }
    }

    public function getPublicAvailable(&$rets, $msgs) {
        $ids = [];

        foreach ($rets as $ret) {
            if ($ret['availableinitially'] > 1 && $ret['availablenow'] != $ret['availableinitially']) {
                # Partially taken - find out more.
                $ids[] = $ret['id'];
            }
        }

        if (count($ids)) {
            $bys = $this->dbhr->preQuery("SELECT DISTINCT messages_by.msgid, messages_by.userid, messages_by.timestamp, messages_by.count, 
                CASE WHEN users.fullname IS NOT NULL THEN users.fullname ELSE CONCAT(users.firstname, ' ', users.lastname) END AS displayname 
                FROM messages_by 
                LEFT JOIN users ON users.id = messages_by.userid 
            WHERE msgid IN (" . implode(', ', $ids) . ") AND count > 0 ORDER BY timestamp DESC;");

            foreach ($rets as $ix => $ret) {
                foreach ($bys as $by) {
                    if ($by['msgid'] == $ret['id']) {
                        $by['timestamp'] = Utils::ISODate($by['timestamp']);
                        $rets[$ix]['by'][] = $by;
                    }
                }
            }
        }
    }

    public function getPublicAttachments(&$rets, $msgs, $summary) {
        $msgids = array_filter(array_column($msgs, 'id'));

        $atts = NULL;

        $a = new Attachment($this->dbhr, $this->dbhm);
        $atts = $a->getByIds($msgids);

        foreach ($msgs as $msg) {
            if ($summary) {
                # Construct a minimal attachment list, i.e. just one if we have it.
                $rets[$msg['id']]['attachments'] = Utils::presdef('attachments', $msg, []);
            } else if (!$summary) {
                # Add any attachments - visible to non-members.
                $rets[$msg['id']]['attachments'] = [];
                $atthash = [];

                foreach ($atts as $att) {
                    /** @var $att Attachment */
                    $pub = $att->getPublic();

                    if ($pub['msgid'] == $msg['id']) {
                        # We suppress return of duplicate attachments by using the image hash.  This helps in the case where
                        # the same photo is (for example) included in the mail both as an inline attachment and as a link
                        # in the text.
                        $hash = $att->getHash();
                        if (!$hash || !Utils::pres($msg['id'] . '-' . $hash, $atthash)) {
                            $rets[$msg['id']]['attachments'][] = $pub;
                            $atthash[$msg['id'] . '-' . $hash] = TRUE;
                        }
                    }
                }
            }
        }
    }

    public function getPublicPostingHistory(&$rets, $msgs, $me, $myid) {
        $fetch = [];

        foreach ($rets as $ret) {
            if ($myid && Utils::pres('fromuser', $ret) && $ret['fromuser']['id'] == $myid) {
                $fetch[] = $ret['id'];
            }
        }

        if (count($fetch)) {
            # For our own messages, return the posting history.
            $posts = $this->dbhr->preQuery("SELECT * FROM messages_postings WHERE msgid IN (" . implode(',', $fetch) . ") ORDER BY date ASC;", NULL, FALSE, FALSE);

            foreach ($rets as &$ret) {
                $ret['postings'] = [];

                foreach ($posts as $post) {
                    if ($post['msgid'] == $ret['id']) {
                        $post['date'] = Utils::ISODate($post['date']);
                        $ret['postings'][] = $post;
                    }
                }
            }
        }
    }

    public function getPublicEditHistory(&$rets, $msgs, $me, $myid) {
        $doit = Session::modtools() && $me && $me->isModerator();
        $msgids = array_filter(array_column($msgs, 'id'));

        if (count($msgids)) {
            if ($doit) {
                # Return any edit history, most recent first.
                $edits = $this->dbhr->preQuery("SELECT * FROM messages_edits WHERE msgid IN (" . implode(',', $msgids) . ") ORDER BY id DESC;", [
                    $this->id
                ]);
            }

            # We can't use foreach because then data is copied by reference.
            foreach ($rets as $retind => $ret) {
                $rets[$retind]['edits'] = [];

                if ($doit) {
                    for ($editind = 0; $editind < count($edits); $editind++) {
                        if ($rets[$retind]['id'] == $edits[$editind]['msgid']) {
                            $thisedit = $edits[$editind]; 
                            $thisedit['timestamp'] = Utils::ISODate($thisedit['timestamp']);

                            if (Utils::pres('byuser', $thisedit)) {
                                $u = User::get($this->dbhr, $this->dbhm, $thisedit['byuser']);
                                $thisedit['byuser'] = $u->getPublic(NULL, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE, NULL, FALSE);
                            }
                            
                            $rets[$retind]['edits'][] = $thisedit;
                        }
                    }

                    if (count($rets[$retind]['edits']) === 0) {
                        $rets[$retind]['edits'] = NULL;
                    }
                }
            }
        }
    }

    public function getWorry(&$msgs) {
       if (Session::modtools()) {
           # We check the messages again.  This means if something is added to worry words while our message is in
           # pending, we'll see it.
           $w = new WorryWords($this->dbhr, $this->dbhm);

           foreach ($msgs as $msgind => $msg) {
               $msgs[$msgind]['worry'] = $w->checkMessage($msg['id'], Utils::pres('fromuser', $msg) ? $msg['fromuser']['id'] : NULL, $msg['subject'], $msg['textbody'], FALSE);
           }
       }
    }

    /**
     * @param bool $messagehistory
     * @param bool $related
     * @param bool $seeall
     * @param null $userlist is a way to cache users over multiple calls
     * @param null $locationlist is a way to cache users over multiple calls
     * @param bool $summary
     * @return array
     */
    public function getPublic($messagehistory = TRUE, $related = TRUE, $seeall = FALSE, &$userlist = NULL, &$locationlist = [], $summary = FALSE) {
        $msgs = $this->getThisAsArray();

        if ($summary) {
            # getPublics() is mostly aimed at the MessageCollection case, and assumes that if we
            # are doing a summary, we will have got a single attachment for the message as part of our main query.
            # In this case we haven't, so do so here.
            $this->getPublicAttachments($rets, $msgs, $summary);
            $atts = $this->dbhr->preQuery("SELECT messages_attachments.id AS attachmentid FROM messages_attachments WHERE msgid = ? ORDER BY messages_attachments.id LIMIT 1;", [
                $this->id
            ]);

            if (count($atts)) {
                $a = new Attachment($this->dbhr, $this->dbhm);

                $msgs[0]['attachments'] = [
                    [
                        'id' => $atts[0]['attachmentid'],
                        'path' => $a->getpath(false, $atts[0]['attachmentid']),
                        'paththumb' => $a->getpath(true, $atts[0]['attachmentid'])
                    ]
                ];
            }
        }

        $msgs[0]['groups'] = $this->getGroups(FALSE, FALSE);

        $rets = $this->getPublics($msgs, $messagehistory, $related, $seeall, $userlist, $locationlist, $summary);

        # When getting an individual message we return an approx distance.
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        if ($me && ($this->lat || $this->lng)) {
            list ($mylat, $mylng) = $me->getLatLng();
            $rets[$this->id]['milesaway'] = $me->getDistanceBetween($mylat, $mylng, $this->lat, $this->lng);
        }

        $ret = $rets[$this->id];
        return($ret);
    }

    public function getPublics($msgs, $messagehistory = TRUE, $related = TRUE, $seeall = FALSE, &$userlist = NULL, &$locationlist = [], $summary = FALSE) {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $myid = $me ? $me->getId() : NULL;

        # We call the methods that handle an array of messages, which are shared with MessageCollection.  Each of
        # these return their info in an array indexed by message id.
        $roles = $this->getRolesForMessages($me, $msgs);
        $rets = $this->getPublicAtts($me, $myid, $msgs, $roles, $seeall, $summary);
        $this->getPublicReplies($me, $myid, $rets, $msgs, $summary, $roles, $seeall, FALSE);
        $this->getPublicGroups($me, $myid, $userlist, $rets, $msgs, $roles, $summary, $seeall);
        $this->getPublicOutcomes($me, $myid, $rets, $msgs, $summary, $roles, $seeall);
        $this->getPublicAttachments($rets, $msgs, $summary);

        if (!$summary) {
            $this->getPublicLocation($myid, $rets, $msgs, $roles, $seeall, $locationlist);
            $this->getPublicItem($rets, $msgs);
            $this->getPublicFromUser($rets, $msgs, $roles, $messagehistory, $me, $myid);
            $this->getPublicHeld($rets, $msgs, $messagehistory);
            $this->getPublicPostingHistory($rets, $msgs, $me, $myid);
            $this->getPublicEditHistory($rets, $msgs, $me, $myid);
            $this->getWorry($rets);
            $this->getPublicAvailable($rets, $msgs);

            if ($related) {
                $this->getPublicRelated($rets, $msgs);
            }
        }

        return($rets);
    }

    /**
     * @return mixed
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @return mixed
     */
    public function getFromIP()
    {
        return $this->fromip;
    }

    /**
     * @return mixed
     */
    public function getSource()
    {
        return $this->source;
    }

    /**
     * @return mixed
     */
    public function getSourceheader()
    {
        return $this->sourceheader;
    }

    /**
     * @param mixed $fromip
     */
    public function setFromIP($fromip)
    {
        $name = NULL;

        if ($fromip) {
            # If the call returns a hostname which is the same as the IP, then it's
            # not resolvable.
            $name = gethostbyaddr($fromip);
            $name = ($name == $fromip) ? NULL : $name;

            if ($name) {
                $this->fromhost = $name;
            }
        }

        if ($fromip) {
            $this->fromip = $fromip;
            $this->dbhm->preExec("UPDATE messages SET fromip = ? WHERE id = ? AND fromip IS NULL;",
                [$fromip, $this->id]);
            $this->dbhm->preExec("UPDATE messages_history SET fromip = ?, fromhost = ? WHERE msgid = ? AND fromip IS NULL;",
                [$fromip, $name, $this->id]);
        }
    }

    /**
     * @return mixed
     */
    public function getFromhost()
    {
        if (!$this->fromhost && $this->fromip) {
            # If the call returns a hostname which is the same as the IP, then it's
            # not resolvable.
            $name = gethostbyaddr($this->fromip);
            $name = ($name == $this->fromip) ? NULL : $name;
            $this->fromhost = $name;
        }

        return $this->fromhost;
    }

    const EMAIL = 'Email';
    const PLATFORM = 'Platform'; // Us

    /**
     * @return null
     */
    public function getID()
    {
        return $this->id;
    }

    /**
     * @return mixed
     */
    public function getMessageID()
    {
        return $this->messageid;
    }

    /**
     * @return mixed
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @return mixed
     */
    public function getTnpostid()
    {
        return $this->tnpostid;
    }

    /**
     * @return mixed
     */
    public function getEnvelopefrom()
    {
        return $this->envelopefrom;
    }

    /**
     * @return mixed
     */
    public function getEnvelopeto()
    {
        return $this->envelopeto;
    }

    /**
     * @return mixed
     */
    public function getFromname()
    {
        return $this->fromname;
    }

    /**
     * @return mixed
     */
    public function getFromaddr()
    {
        return $this->fromaddr;
    }

    /**
     * @return mixed
     */
    public function getTextbody()
    {
        return $this->textbody;
    }

    /**
     * @return mixed
     */
    public function getHtmlbody()
    {
        return $this->htmlbody;
    }

    /**
     * @return mixed
     */
    public function getSubject()
    {
        return $this->subject;
    }

    /**
     * @return array
     */
    public function getInlineimgs()
    {
        return $this->inlineimgs;
    }

    /**
     * @return PhpMimeMailParser\Attachment[]
     */
    public function getParsedAttachments()
    {
        return $this->attachments;
    }

    # Get attachments which have been saved
    public function getAttachments() {
        $a = new Attachment($this->dbhr, $this->dbhm);
        $atts = $a->getById($this->getID());
        return($atts);
    }

    private static function keywords() {
        # We try various mis-spellings, and Welsh.  This is not to suggest that Welsh is a spelling error.
        return([
            Message::TYPE_OFFER => [
                'ofer', 'offr', 'offrer', 'ffered', 'offfered', 'offrered', 'offered', 'offeer', 'cynnig', 'offred',
                'offer', 'offering', 'reoffer', 're offer', 're-offer', 'reoffered', 're offered', 're-offered',
                'offfer', 'offeed', 'available'],
            Message::TYPE_TAKEN => ['collected', 'take', 'stc', 'gone', 'withdrawn', 'ta ke n', 'promised',
                'cymeryd', 'cymerwyd', 'takln', 'taken', 'cymryd'],
            Message::TYPE_WANTED => ['wnted', 'requested', 'rquested', 'request', 'would like', 'want',
                'anted', 'wated', 'need', 'needed', 'wamted', 'require', 'required', 'watnted', 'wented',
                'sought', 'seeking', 'eisiau', 'wedi eisiau', 'eisiau', 'wnated', 'wanted', 'looking', 'waned'],
            Message::TYPE_RECEIVED => ['recieved', 'reiceved', 'receved', 'rcd', 'rec\'d', 'recevied',
                'receive', 'derbynewid', 'derbyniwyd', 'received', 'recivered'],
            Message::TYPE_ADMIN => ['admin', 'sn']
        ]);
    }

    public static function determineType($subj) {
        $type = Message::TYPE_OTHER;
        $pos = PHP_INT_MAX;

        foreach (Message::keywords() as $keyword => $vals) {
            foreach ($vals as $val) {
                if (preg_match('/\b(' . preg_quote($val) . ')\b/i', $subj, $matches, PREG_OFFSET_CAPTURE)) {
                    if ($matches[1][1] < $pos) {
                        # We want the match earliest in the string - Offerton etc.
                        $type = $keyword;
                        $pos = $matches[1][1];
                    }
                }
            }
        }

        return($type);
    }

    public function getPrunedSubject() {
        $subj = $this->getSubject();

        if (preg_match('/(.*)\(.*\)/', $subj, $matches)) {
            # Strip possible location - useful for reuse groups
            $subj = $matches[1];
        }
        if (preg_match('/\[.*\](.*)/', $subj, $matches)) {
            # Strip possible group name
            $subj = $matches[1];
        }

        $subj = trim($subj);

        # Remove any odd characters.
        $subj = quoted_printable_encode($subj);

        return($subj);
    }

    public function createDraft($uid = NULL) {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $myid = $me ? $me->getId() : NULL;
        $myid = $myid ? $myid : $uid;
        $sess = session_id();

        $rc = $this->dbhm->preExec("INSERT INTO messages (source, sourceheader, date, fromip, message) VALUES(?,?, NOW(), ?, '');", [
            Message::PLATFORM,
            Message::PLATFORM,
            Utils::presdef('REMOTE_ADDR', $_SERVER, NULL)
        ]);

        $id = $rc ? $this->dbhm->lastInsertId() : NULL;

        if ($id) {
            $rc = $this->dbhm->preExec("INSERT INTO messages_drafts (msgid, userid, session) VALUES (?, ?, ?);", [ $id, $myid, $sess ]);
            $id = $rc ? $id : NULL;
        }

        #error_log("Created draft $id");
        return($id);
    }

    private function removeAttachDir() {
        # Be careful what we delete.
        if ($this->attach_dir && strpos($this->attach_dir, sys_get_temp_dir()) !== FALSE) {
            foreach ($this->attachments as $att) {
                /** @var \PhpMimeMailParser\Attachment $att */
                $fn = $this->attach_dir . DIRECTORY_SEPARATOR . $att->getFilename();
                @unlink($fn);
            }

            rmdir($this->attach_dir);
        }

        $this->attach_dir = NULL;
    }
    
    # Parse a raw SMTP message.
    public function parse($source, $envelopefrom, $envelopeto, $msg, $groupid = NULL)
    {
        $this->message = $msg;
        $this->groupid = $groupid;
        $this->source = $source;

        $Parser = new \PhpMimeMailParser\Parser();
        $this->parser = $Parser;
        $Parser->setText($msg);

        # We save the attachments to a temp directory.  This is tidied up on destruction or save.
        $this->attach_dir = Utils::tmpdir();
        try {
            $this->attach_files = $Parser->saveAttachments($this->attach_dir . DIRECTORY_SEPARATOR);
            $this->attachments = $Parser->getAttachments();
        } catch (\Exception $e) {
            # We've seen this error when some of the attachments have weird non-relative filenames, which may be
            # a hack attempt.
            error_log("Parse of attachments failed " . $e->getMessage());
            $this->attachments = [];
        }

        # Get IP
        $ip = $this->getHeader('x-freegle-ip');
        $ip = $ip ? $ip : $this->getHeader('x-trash-nothing-user-ip');
        $ip = $ip ? $ip : $this->getHeader('x-yahoo-post-ip');
        $ip = $ip ? $ip : $this->getHeader('x-originating-ip');
        $ip = preg_replace('/[\[\]]/', '', $ip);
        $this->fromip = $ip;

        $latlng = $this->getHeader('x-trash-nothing-post-coordinates');
        if ($latlng) {
            $arr = explode(',', $latlng);
            if (count($arr)) {
                $this->lat = $arr[0];
                $this->lng = $arr[1];
            }
        }

        # See if we can find a group this is intended for.  Can't trust the To header, as the client adds it,
        # and we might also be CC'd or BCC'd.
        $groupname = NULL;
        $to = $this->getApparentlyTo();

        if (count($to) == 0) {
            # ...but if we can't find it, it'll do.
            $to = $this->getTo();
        }

        $rejected = $this->getHeader('x-egroups-rejected-by');

        if (!$rejected) {
            # Rejected messages can look a bit like messages to the group, but they're not.
            $togroup = FALSE;
            $toours = FALSE;

            foreach ($to as $t) {
                # Check it's to a group (and not the owner).
                #
                # Yahoo members can do a reply to all which results in a message going to both one of our users
                # and the group, so in that case we want to ignore the group aspect.
                if (Mail::ourDomain($t['address'])) {
                    $toours = TRUE;
                }

                if (preg_match('/(.*)@yahoogroups\.co.*/', $t['address'], $matches) &&
                    strpos($t['address'], '-owner@') === FALSE) {
                    # Yahoo group.
                    $groupname = $matches[1];
                    $togroup = TRUE;
                    #error_log("Got $groupname from {$t['address']}");
                } else if (preg_match('/(.*)@' . GROUP_DOMAIN . '/', $t['address'], $matches) &&
                    strpos($t['address'], '-volunteers@') === FALSE &&
                    strpos($t['address'], '-auto@') === FALSE) {
                    # Native group.
                    $groupname = $matches[1];
                    $togroup = TRUE;
                    #error_log("Got $groupname from {$t['address']}");
                }
            }

            if ($toours && $togroup) {
                # Drop the group aspect.
                $groupname = NULL;
            }
        }

        if ($groupname) {
            if (!$this->groupid) {
                # Check if it's a group we host.
                $g = Group::get($this->dbhr, $this->dbhm);
                $this->groupid = $g->findByShortName($groupname);
            }
        }

        $this->envelopefrom = $envelopefrom;
        $this->envelopeto = $envelopeto;

        # Yahoo posts messages from the group address, but with a header showing the
        # original from address.
        $originalfrom = $Parser->getHeader('x-original-from');

        if ($originalfrom) {
            $from = mailparse_rfc822_parse_addresses($originalfrom);
        } else {
            $from = mailparse_rfc822_parse_addresses($Parser->getHeader('from'));
        }

        $this->fromname = count($from) > 0 ? $from[0]['display'] : NULL;
        $this->fromaddr = count($from) > 0 ? $from[0]['address'] : NULL;

        if (!$this->fromaddr) {
            # We have failed to parse out this message.
            $this->removeAttachDir();
            return (FALSE);
        }

        $this->date = gmdate("Y-m-d H:i:s", strtotime($Parser->getHeader('date')));

        $this->sourceheader = $Parser->getHeader('x-freegle-source');
        $this->sourceheader = ($this->sourceheader == 'Unknown' ? NULL : $this->sourceheader);

        # Store Reply-To only if different from fromaddr.
        $rh = $this->getReplyTo();
        $rh = $rh ? $rh[0]['address'] : NULL;
        $this->replyto = ($rh && strtolower($rh) != strtolower($this->fromaddr)) ? $rh : NULL;

        if (!$this->sourceheader) {
            $this->sourceheader = $Parser->getHeader('x-trash-nothing-source');
            if ($this->sourceheader) {
                $this->sourceheader = "TN-" . $this->sourceheader;
            }
        }

        if (!$this->sourceheader && $Parser->getHeader('x-mailer') == 'Yahoo Groups Message Poster') {
            $this->sourceheader = 'Yahoo-Web';
        }

        if (!$this->sourceheader && (strpos($Parser->getHeader('x-mailer'), 'Freegle Message Maker') !== FALSE)) {
            $this->sourceheader = 'MessageMaker';
        }

        if (!$this->sourceheader) {
            if (Mail::ourDomain($this->fromaddr)) {
                $this->sourceheader = 'Platform';
            } else {
                $this->sourceheader = 'Yahoo-Email';
            }
        }

        $this->subject = $Parser->getHeader('subject');
        $this->messageid = $Parser->getHeader('message-id');
        $this->messageid = str_replace('<', '', $this->messageid);
        $this->messageid = str_replace('>', '', $this->messageid);
        $this->tnpostid = $Parser->getHeader('x-trash-nothing-post-id');

        $this->textbody = $Parser->getMessageBody('text');
        $this->htmlbody = $Parser->getMessageBody('html');

        if ($this->htmlbody) {
            # The HTML body might contain images as img tags, rather than actual attachments.  Extract these too.
            $doc = new \DOMDocument();
            @$doc->loadHTML($this->htmlbody);
            $imgs = $doc->getElementsByTagName('img');

            /* @var DOMNodeList $imgs */
            foreach ($imgs as $img) {
                $src = $img->getAttribute('src');

                # We only want to get images from http or https to avoid the security risk of fetching a local file.
                #
                # Wait for 120 seconds to fetch.  We don't want to wait forever, but we see occasional timeouts from Yahoo
                # at 60 seconds.
                #
                # We don't want Yahoo's megaphone images - they're just generic footer images.  Likewise Avast.  And
                # not any from our own domain - they might be ads, or item images in chat notifications.
                #
                # And we don't want any profile images from Gravatar, Picasaweb, TN or Facebook.  These might
                # arrive back at us in quoted replies.
                if ((stripos($src, 'http://') === 0 || stripos($src, 'https://') === 0) &&
                    (stripos($src, 'https://s.yimg.com/ru/static/images/yg/img/megaphone') === FALSE) &&
                    (stripos($src, 'ilovefreegle.org') === FALSE) &&
                    (stripos($src, 'gravatar.com') === FALSE) &&
                    (stripos($src, 'picasaweb.google.com/data/entry/api/user/') === FALSE) &&
                    (stripos($src, 'trashnothing.com/api/users/') === FALSE) &&
                    (stripos($src, 'platform-lookaside.fbsbx.com') === FALSE) &&
                    (stripos($src, 'https://ipmcdn.avast.com') === FALSE)) {
                    $ctx = stream_context_create(array('http' =>
                        array(
                            'timeout' => 120
                        )
                    ));

                    $data = @file_get_contents($src, false, $ctx);

                    if ($data) {
                        # Try to convert to an image.  If it's not an image, this will fail.
                        $img = new Image($data);

                        if ($img->img) {
                            $newdata = $img->getData(100);

                            # Ignore small images - Yahoo adds small ones as (presumably) a tracking mechanism, and also their
                            # logo.
                            if ($newdata && $img->width() > 100 && $img->height() > 100) {
                                $this->inlineimgs[] = $newdata;
                            }
                        }
                    }
                }
            }
        }

        $this->textbody = $this->stripSigs($this->textbody);
        $this->textbody = $this->scrapePhotos();

        # If this is a reuse group, we need to determine the type.
        $g = Group::get($this->dbhr, $this->dbhm, $this->groupid);
        if ($g->getPrivate('type') == Group::GROUP_FREEGLE ||
            $g->getPrivate('type') == Group::GROUP_REUSE
        ) {
            $this->type = $this->determineType($this->subject);
        } else {
            $this->type = Message::TYPE_OTHER;
        }

        if ($source == Message::EMAIL) {
            # Make sure we have a user for the sender.
            $u = User::get($this->dbhr, $this->dbhm);

            # If there is a Yahoo uid in here - which there isn't always - we might be able to find them that way.
            #
            # This is important as well as checking the email address as users can send from the owner address (which
            # we do not allow to be attached to a specific user, as it can be shared by many).
            $iznikid = NULL;
            $userid = NULL;
            $yahoouid = NULL;
            $emailid = NULL;
            $this->modmail = FALSE;

            $iznikid = $Parser->getHeader('x-iznik-from-user');
            if ($iznikid) {
                # We know who claims to have sent this.  There's a slight exploit here where someone could spoof
                # the modmail setting and get a more prominent display.  I may regret writing this comment.
                $userid = $iznikid;
                $this->modmail = filter_var($Parser->getHeader('x-iznik-modmail'), FILTER_VALIDATE_BOOLEAN);
            }

            if (!$userid) {
                # Or we might have their email.
                $userid = $u->findByEmail($this->fromaddr);
            }

            if (!$userid) {
                # We don't know them.  Add.
                #
                # We don't have a first and last name, so use what we have. If the friendly name is set to an
                # email address, take the first part.
                $name = $this->fromname;
                if (preg_match('/(.*)@/', $name, $matches)) {
                    $name = $matches[1];
                }

                $userid = $u->create(NULL, NULL, $name, "Incoming message #{$this->id} from {$this->fromaddr} on $groupname");
                $u->addEmail($this->fromaddr);

                # Use the m handle to make sure we find it later.
                $this->dbhr = $this->dbhm;
            }

            $this->fromuser = $userid;
        }

        return(TRUE);
    }

    public function scrapePhotos($textbody = NULL) {
        $textbody = $textbody ? $textbody : $this->textbody;

        # Trash Nothing sends attachments too, but just as links - get those.
        #
        # - links to flic.kr, for groups which for some reason don't like images hosted on TN
        # - links to TN itself
        if (preg_match_all('/(http:\/\/flic\.kr.*)$/m', $textbody, $matches)) {
            $urls = [];
            foreach ($matches as $val) {
                foreach ($val as $url) {
                    $urls[] = $url;
                }
            }

            $urls = array_unique($urls);
            foreach ($urls as $url) {
                $ctx = stream_context_create(array('http' =>
                    array(
                        'timeout' => 120
                    )
                ));

                $data = @file_get_contents($url, false, $ctx);

                if ($data) {
                    # Now get the link to the actual image.  DOMDocument chokes on the HTML so do it the dirty way.
                    if (preg_match('#<meta property="og:image" content="(.*)"  data-dynamic="true">#', $data, $matches)) {
                        $imgurl = $matches[1];
                        $ctx = stream_context_create(array('http' =>
                            array(
                                'timeout' => 120
                            )
                        ));

                        $data = @file_get_contents($imgurl, false, $ctx);

                        if ($data) {
                            # Try to convert to an image.  If it's not an image, this will fail.
                            $img = new Image($data);

                            if ($img->img) {
                                $newdata = $img->getData(100);

                                if ($newdata && $img->width() > 50 && $img->height() > 50) {
                                    $this->inlineimgs[] = $newdata;
                                }
                            }
                        }
                    }
                }
            }
        }

        if (preg_match_all('/(https:\/\/trashnothing\.com\/pics\/.*)$/m', $textbody, $matches)) {
            $urls = [];
            foreach ($matches as $val) {
                foreach ($val as $url) {
                    $urls[] = $url;
                }
            }

            $urls = array_unique($urls);
            foreach ($urls as $url) {
                $ctx = stream_context_create(array('http' =>
                    array(
                        'timeout' => 120
                    )
                ));

                $data = @file_get_contents($url, false, $ctx);

                if ($data) {
                    # Now get the link to the actual images.
                    $doc = new \DOMDocument();
                    @$doc->loadHTML($data);
                    $imgs = $doc->getElementsByTagName('img');

                    /* @var DOMNodeList $imgs */
                    foreach ($imgs as $img) {
                        $src = $img->getAttribute('src');

                        if (strpos($src, '/img/') !== FALSE || strpos($src, '/tn-photos/')) {
                            $ctx = stream_context_create(array('http' =>
                                array(
                                    'timeout' => 120
                                )
                            ));

                            $data = @file_get_contents($src, false, $ctx);

                            if ($data) {
                                # Try to convert to an image.  If it's not an image, this will fail.
                                $img = new Image($data);

                                if ($img->img) {
                                    $newdata = $img->getData(100);

                                    if ($newdata && $img->width() > 50 && $img->height() > 50) {
                                        $this->inlineimgs[] = $newdata;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        $this->inlineimgs = array_unique($this->inlineimgs);

        # Return text without photos.
        return preg_replace('/Check out the pictures[\s\S]*?https:\/\/trashnothing[\s\S]*?pics\/[a-zA-Z0-9]*/', '', $textbody);
    }

    public function pruneMessage() {
        # We are only interested in image attachments; those are what we hive off into the attachments table,
        # and what we display.  They bulk up the message source considerably, which chews up disk space.  Worse,
        # we might have message attachments which are not even image attachments, just for messages we are
        # moderating on groups.
        #
        # So we remove all attachment data within the message.  We do this with a handrolled lame parser, as we
        # don't have a full MIME reassembler.
        $current = $this->message;
        #error_log("Start prune len " . strlen($current));

        # Might have wrong LF format.
        $current = preg_replace('~\R~u', "\r\n", $current);
        $p = 0;

        do {
            $found = FALSE;
            $p = stripos($current, 'Content-Type:', $p);
            #error_log("Found content type at $p");

            if ($p) {
                $crpos = strpos($current, "\r\n", $p);
                $ct = strtolower(substr($current, $p, $crpos - $p));
                #error_log($ct);

                $found = TRUE;

                # We don't want to prune a multipart, only the bottom level parts.
                if (stripos($ct, "multipart") === FALSE) {
                    #error_log("Prune it");
                    # Find the boundary before it.
                    $boundpos = strrpos(substr($current, 0, $p), "\r\n--");

                    if ($boundpos) {
                        #error_log("Found bound");
                        $crpos = strpos($current, "\r\n", $boundpos + 2);
                        $boundary = substr($current, $boundpos + 2, $crpos - ($boundpos + 2));

                        # Find the end of the bodypart headers.
                        $breakpos = strpos($current, "\r\n\r\n", $boundpos);

                        # Find the end of the bodypart.
                        $nextboundpos = strpos($current, $boundary, $breakpos);

                        # Always prune image and HTML bodyparts - images are stored off as attachments, and HTML
                        # bodyparts are quite long and typically there's also a text one present.  Ideally we might
                        # keep HTML, but we need to control our disk space usage.
                        #
                        # For other bodyparts keep a max of 10K. Observant readers may wish to comment on this
                        # definition of K.
                        #error_log("$ct breakpos $breakpos nextboundpos $nextboundpos size " . ($nextboundpos - $breakpos) . " strpos " . strpos($ct, 'image/') . ", " . strpos($ct, 'text/html'));
                        if ($breakpos && $nextboundpos &&
                            (($nextboundpos - $breakpos > 10000) ||
                                (strpos($ct, 'image/') !== FALSE) ||
                                (strpos($ct, 'text/html') !== FALSE))) {
                            # Strip out the bodypart data and replace it with some short text.
                            $current = substr($current, 0, $breakpos + 2) .
                                "\r\n...Content of size " . ($nextboundpos - $breakpos + 2) . " removed...\r\n\r\n" .
                                substr($current, $nextboundpos);
                            #error_log($this->id . " Content of size " . ($nextboundpos - $breakpos + 2) . " removed...");
                        }
                    }
                }
            }

            $p++;
        } while ($found);

        #error_log("End prune len " . strlen($current));

        # Something went horribly wrong?
        # TODO Test.
        $current = (strlen($current) == 0) ? $this->message : $current;

        return($current);
    }

    public function saveAttachments($msgid) {
        if ($this->type != Message::TYPE_TAKEN && $this->type != Message::TYPE_RECEIVED) {
            # Don't want attachments for TAKEN/RECEIVED.  They can occur if people forward the original message.
            #
            # If we crash or fail at this point, we would have mislaid an attachment for a message.  That's not great, but the
            # perf cost of a transaction for incoming messages is significant, and we can live with it.
            $a = new Attachment($this->dbhr, $this->dbhm);

            foreach ($this->attachments as $att) {
                /** @var \PhpMimeMailParser\Attachment $att */
                $ct = $att->getContentType();
                $fn = $this->attach_dir . DIRECTORY_SEPARATOR . $att->getFilename();

                # Can't use LOAD_FILE as server may be remote.
                $data = file_get_contents($fn);

                # Scale the image if it's large.  Ideally we'd store the full size image, but images can be many meg, and
                # it chews up disk space.
                $i = new Image($data);
                if ($i->img) {
                    $ow = $w = $i->width();
                    $oh = $h = $i->height();

                    if (strlen($data) > 300000) {
                        $w = min(1024, $w);
                        $i->scale($w, NULL);
                        $data = $i->getData();
                        $ct = 'image/jpeg';
                    }

                    if ($ow && $oh) {
                        $r = $ow / $oh;

                        # We want to remove images which are likely to be signature images.
                        #
                        # Camera use aspect ratios like 4:3, 3:2, 16:9.  If it's too far from that, then it's
                        # probably not a photo, more likely to be an image signature, if it's small.
                        #
                        # We also only want images which are a decent size; otherwise more likely to just be
                        # logos and suchlike.
                        if ($ow > 150 && $oh > 150 && ($ow > 600 || $oh > 600 || ($r >= 0.5 && $r <= 1.5))) {
                            $a->create($msgid, $ct, $data);
                        }
                    }
                }
            }

            foreach ($this->inlineimgs as $att) {
                $a->create($msgid, 'image/jpeg', $att);
            }
        }
    }

    # Save a parsed message to the DB
    public function save($log = TRUE) {
        # Despite what the RFCs might say, it's possible that a message can appear on Yahoo without a Message-ID.  We
        # require unique message ids, so this causes us a problem.  Invent one.
        $this->messageid = $this->messageid ? $this->messageid : (microtime(TRUE). '@' . USER_DOMAIN);

        # We now manipulate the message id a bit.  This is because although in future we will support the same message
        # appearing on multiple groups, and therefore have a unique key on message id, we've not yet tested this.  IT
        # will probably require client changes, and there are issues about what to do when a message is sent to two
        # groups and edited differently on both.  Meanwhile we need to be able to handle messages which are sent to
        # multiple groups, which would otherwise overwrite each other.
        #
        # There is code that does this on message submission too, so that when the message comes back we recognise it.
        $this->messageid = $this->groupid ? ($this->messageid . "-" . $this->groupid) : $this->messageid;

        # See if we have a record of approval from Yahoo.
        $approvedby = NULL;
        $approval = $this->getHeader('x-egroups-approved-by');

        if ($approval) {
            if (preg_match('/(.*?) /', $approval, $matches)) {
                $yid = $matches[1];
                $u = new User($this->dbhr, $this->dbhm);
                $approvedby = $u->findByYahooId($yid);
            }
        }

        # Reduce the size of the message source
        $this->message = $this->pruneMessage();

        $this->id = NULL;

        # Trigger mapping and get subject suggestion.
        $this->suggestedsubject = $this->suggestSubject($this->groupid, $this->subject);

        # Save into the messages table.
        try {
            $sql = "INSERT INTO messages (date, source, sourceheader, message, fromuser, envelopefrom, envelopeto, fromname, fromaddr, replyto, fromip, subject, suggestedsubject, messageid, tnpostid, textbody, type, lat, lng, locationid) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?);";
            $rc = $this->dbhm->preExec($sql, [
                $this->date,
                $this->source,
                $this->sourceheader,
                $this->message,
                $this->fromuser,
                $this->envelopefrom,
                $this->envelopeto,
                $this->fromname,
                $this->fromaddr,
                $this->replyto,
                $this->fromip,
                $this->subject,
                $this->suggestedsubject,
                $this->messageid,
                $this->tnpostid,
                $this->textbody,
                $this->type,
                $this->lat,
                $this->lng,
                $this->locationid
            ]);
        } catch (\Exception $e) {
            $rc = FALSE;
        }

        if ($rc) {
            $this->id = $this->dbhm->lastInsertId();

            if (preg_match('/.*?\:(.*)\(.*\)/', $this->suggestedsubject, $matches)) {
                # If we have a well-formed subject line, record the item.
                $i = new Item($this->dbhr, $this->dbhm);
                $name = trim($matches[1]);
                $items = $i->findByName($name);

                if (!$items || !count($items)) {
                    $itemid = $i->create($name);
                } else {
                    $itemid = $items[0]['id'];
                }

                $this->addItem($itemid);
            }

            $this->saveAttachments($this->id);

            if ($log) {
                $l = new Log($this->dbhr, $this->dbhm);
                $l->log([
                    'type' => Log::TYPE_MESSAGE,
                    'subtype' => Log::SUBTYPE_RECEIVED,
                    'msgid' => $this->id,
                    'user' => $this->fromuser,
                    'text' => $this->messageid,
                    'groupid' => $this->groupid
                ]);
            }

            # Now that we have a ID, record which messages are related to this one.
            $this->recordRelated();

            if ($this->groupid) {
                # Save the group we're on.  If we crash or fail at this point we leave the message stranded, which is ok
                # given the perf cost of a transaction.
                $this->dbhm->preExec("INSERT INTO messages_groups (msgid, groupid, msgtype, collection, approvedby,arrival) VALUES (?,?,?,?,?,NOW());", [
                    $this->id,
                    $this->groupid,
                    $this->type,
                    MessageCollection::INCOMING,
                    $approvedby
                ]);
            }

            # Also save into the history table, for spam checking.
            $this->addToMessageHistory();
        }

        # Attachments now safely stored in the DB
        $this->removeAttachDir();

        return $this->id;
    }

    public function addToMessageHistory() {
        $sql = "INSERT IGNORE INTO messages_history (groupid, source, fromuser, envelopefrom, envelopeto, fromname, fromaddr, fromip, subject, prunedsubject, messageid, msgid) VALUES(?,?,?,?,?,?,?,?,?,?,?,?);";
        $this->dbhm->preExec($sql, [
            $this->groupid,
            $this->source,
            $this->fromuser,
            $this->envelopefrom,
            $this->envelopeto,
            $this->fromname,
            $this->fromaddr,
            $this->fromip,
            $this->subject,
            $this->getPrunedSubject(),
            $this->messageid,
            $this->id
        ]);
    }

    function recordFailure($reason) {
        $this->dbhm->preExec("UPDATE messages SET retrycount = LAST_INSERT_ID(retrycount),
          retrylastfailure = NOW() WHERE id = ?;", [$this->id]);
        $count = $this->dbhm->lastInsertId();

        $l = new Log($this->dbhr, $this->dbhm);
        $l->log([
            'type' => Log::TYPE_MESSAGE,
            'subtype' => Log::SUBTYPE_FAILURE,
            'msgid' => $this->id,
            'text' => $reason
        ]);

        return($count);
    }

    public function getHeader($hdr) {
        return($this->parser->getHeader($hdr));
    }

    public function getTo() {
        return(mailparse_rfc822_parse_addresses($this->parser->getHeader('to')));
    }

    public function getApparentlyTo() {
        return(mailparse_rfc822_parse_addresses($this->parser->getHeader('x-apparently-to')));
    }

    public function getReplyTo() {
        $rt = mailparse_rfc822_parse_addresses($this->parser->getHeader('reply-to'));

        # Yahoo can save off the original Reply-To header field.
        $rt = $rt ? $rt : mailparse_rfc822_parse_addresses($this->parser->getHeader('x-original-reply-to'));
        return($rt);
    }

    public function getGroups($includedeleted = FALSE, $justids = TRUE) {
        if ($justids === FALSE || count($this->groups) === 0) {
            # Need to query for them.
            $ret = [];
            $delq = $includedeleted ? "" : " AND deleted = 0";
            $sql = "SELECT " . ($justids ? 'groupid' : '*') . " FROM messages_groups WHERE msgid = ? $delq;";
            $groups = $this->dbhr->preQuery($sql, [ $this->id ]);
            foreach ($groups as $group) {
                $ret[] = $justids ? $group['groupid'] : $group;
            }

            if (!$justids) {
                # Save groups for next time
                $this->groups = $groups;
            }
        } else {
            # We have the groups in hand.
            if ($justids) {
                $ret = array_filter(array_column($this->groups, 'groupid'));
            } else {
                $ret = $this->groups;
            }
        }

        return($ret);
    }

    public function isChatByEmail() {
        $refs = $this->dbhr->preQuery("SELECT * FROM chat_messages_byemail WHERE msgid = ?;", [ $this->id ]);
        return(count($refs) > 0);
    }

    public function isPending($groupid) {
        $sql = "SELECT msgid FROM messages_groups WHERE msgid = ? AND groupid = ? AND collection = ? AND deleted = 0;";
        $groups = $this->dbhr->preQuery($sql, [
            $this->id,
            $groupid,
            MessageCollection::PENDING
        ]);

        return(count($groups) > 0);
    }

    public function isApproved($groupid = NULL) {
        $groupq = $groupid ? " AND groupid = $groupid ": "";
        $sql = "SELECT msgid FROM messages_groups WHERE msgid = ? $groupq AND collection = ? AND deleted = 0;";
        $groups = $this->dbhr->preQuery($sql, [
            $this->id,
            MessageCollection::APPROVED
        ]);

        return(count($groups) > 0);
    }

    private function maybeMail($groupid, $subject, $body, $action) {
        if ($subject) {
            # We have a mail to send.
            $me = Session::whoAmI($this->dbhr, $this->dbhm);
            $myid = $me->getId();

            $to = $this->getEnvelopefrom();
            $to = $to ? $to : $this->getFromaddr();

            $g = Group::get($this->dbhr, $this->dbhm, $groupid);
            $atts = $g->getPublic();

            # Find who to send it from.  If we have a config to use for this group then it will tell us.
            $name = $me->getName();
            $c = new ModConfig($this->dbhr, $this->dbhm);
            $cid = $c->getForGroup($me->getId(), $groupid);
            $c = new ModConfig($this->dbhr, $this->dbhm, $cid);
            $fromname = $c->getPrivate('fromname');

            $bcc = $c->getBcc($action);

            if ($bcc) {
                $bcc = str_replace('$groupname', $atts['nameshort'], $bcc);
            }

            if ($fromname == 'Groupname Moderator') {
                $name = '$groupname Moderator';
            }

            # We can do a simple substitution in the from name.
            $name = str_replace('$groupname', $atts['namedisplay'], $name);

            # We add the message into chat.
            $r = new ChatRoom($this->dbhr, $this->dbhm);
            $rid = $r->createUser2Mod($this->getFromuser(), $groupid);
            $m = NULL;

            if ($rid) {
                # Create the message.  Mark it as needing review to prevent timing window.
                $m = new ChatMessage($this->dbhr, $this->dbhm);
                list ($mid, $banned) = $m->create($rid,
                    $myid,
                    "$subject\r\n\r\n$body",
                    ChatMessage::TYPE_MODMAIL,
                    $this->id,
                    TRUE,
                    NULL,
                    NULL,
                    NULL,
                    NULL,
                    NULL,
                    TRUE,
                    TRUE);

                $this->mailer($me, TRUE, $this->getFromname(), $bcc, NULL, $name, $g->getModsEmail(), $subject, "(This is a BCC of a message sent to Freegle user #" . $this->getFromuser() . " $to)\n\n" . $body);
            }

            if (!Mail::ourDomain($to)) {
                # For users who we host, we leave the message unseen; that will then later generate a notification
                # to them.  Otherwise we mail them the message and mark it as seen, because they would get
                # confused by a mail in our notification format.
                $this->mailer($me, TRUE, $this->getFromname(), $to, NULL, $name, $g->getModsEmail(), $subject, $body);

                # We've mailed the message out so they are up to date with this chat.
                $r->upToDate($this->getFromuser());
            }

            if ($m) {
                # Allow mailing to happen.
                $m->setPrivate('reviewrequired', 0);

                # We, as a mod, have seen this message - update the roster to show that.  This avoids this message
                # appearing as unread to us.
                $r->updateRoster($myid, $mid);

                # Ensure that the other mods are present in the roster with the message seen/unseen depending on
                # whether that's what we want.
                $mods = $g->getMods();
                foreach ($mods as $mod) {
                    if ($mod != $myid) {
                        if ($c->getPrivate('chatread')) {
                            # We want to mark it as seen for all mods.
                            $r->updateRoster($mod, $mid, ChatRoom::STATUS_AWAY);
                        } else {
                            # Leave it unseen, but make sure they're in the roster.
                            $r->updateRoster($mod, NULL, ChatRoom::STATUS_AWAY);
                        }
                    }
                }

                if ($c->getPrivate('chatread')) {
                    $m->setPrivate('mailedtoall', 1);
                    $m->setPrivate('seenbyall', 1);
                }
            }
        }
    }

    public function reject($groupid, $subject, $body, $stdmsgid) {
        # No need for a transaction - if things go wrong, the message will remain in pending, which is the correct
        # behaviour.
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $this->log->log([
            'type' => Log::TYPE_MESSAGE,
            'subtype' => $subject ? Log::SUBTYPE_REJECTED : Log::SUBTYPE_DELETED,
            'msgid' => $this->id,
            'byuser' => $me ? $me->getId() : NULL,
            'user' => $this->fromuser,
            'groupid' => $groupid,
            'text' => $subject,
            'stdmsgid' => $stdmsgid
        ]);

        # When rejecting, we put it in the appropriate collection, which means the user can potentially edit and
        # resend.
        if ($subject) {
            $sql = $subject ? "UPDATE messages_groups SET collection = ? WHERE msgid = ?;" : "UPDATE messages_groups SET deleted = 1 WHERE msgid = ?;";
            $this->dbhm->preExec($sql, [
                MessageCollection::REJECTED,
                $this->id
            ]);
        } else {
            $sql = $subject ? "UPDATE messages_groups SET collection = 'Rejected', rejectedat = NOW() WHERE msgid = ?;" : "UPDATE messages_groups SET deleted = 1 WHERE msgid = ?;";
            $this->dbhm->preExec($sql, [
                $this->id
            ]);
        }

        if ($this->heldby) {
            # Delete any message hold, which clearly no longer applies.
            $this->release();
        }

        $this->notif->notifyGroupMods($groupid);
        error_log("Reject notify $groupid");

        $this->maybeMail($groupid, $subject, $body, 'Reject');
    }

    public function approve($groupid, $subject = NULL, $body = NULL, $stdmsgid = NULL, $yahooonly = FALSE) {
        # No need for a transaction - if things go wrong, the message will remain in pending, which is the correct
        # behaviour.
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $myid = $me ? $me->getId() : NULL;

        if (!$yahooonly) {
            $this->log->log([
                'type' => Log::TYPE_MESSAGE,
                'subtype' => Log::SUBTYPE_APPROVED,
                'msgid' => $this->id,
                'user' => $this->fromuser,
                'byuser' => $myid,
                'groupid' => $groupid,
                'stdmsgid' => $stdmsgid,
                'text' => $subject
            ]);
        }

        # Update the arrival time to NOW().  This is because otherwise we will fail to send out messages which
        # were held for moderation to people who are on immediate emails.
        #
        # Make sure we don't approve multiple times, as this will lead to the same message being sent
        # out multiple times.
        $sql = "UPDATE messages_groups SET collection = ?, approvedby = ?, approvedat = NOW(), arrival = NOW() WHERE msgid = ? AND groupid = ? AND collection != ?;";
        $rc = $this->dbhm->preExec($sql, [
            MessageCollection::APPROVED,
            $myid,
            $this->id,
            $groupid,
            MessageCollection::APPROVED
        ]);

        if ($this->heldby) {
            # Delete any message hold, which clearly no longer applies.
            $this->release();
        }

        #error_log("Approve $rc from $sql, $myid, {$this->id}, $groupid");

        $this->notif->notifyGroupMods($groupid);
        $this->maybeMail($groupid, $subject, $body, 'Approve');
        $this->addToSpatialIndex();
        $this->index();
    }

    public function addToSpatialIndex() {
        if ($this->lng || $this->lat) {
            $groups = $this->getGroups(FALSE, FALSE);
            foreach ($groups as $g) {
                $gid = $g['groupid'];
                $arrival = $g['arrival'];

                $sql = "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival) VALUES (?, GeomFromText('POINT({$this->lng} {$this->lat})'), ?, ?, ?) ON DUPLICATE KEY UPDATE point = GeomFromText('POINT({$this->lng} {$this->lat})'), groupid = ?, msgtype = ?, arrival = ?;";
                $this->dbhm->preExec(
                    $sql,
                    [
                        $this->id,
                        $gid,
                        $this->getType(),
                        $arrival,
                        $gid,
                        $this->getType(),
                        $arrival,
                    ]
                );
            }
        }
    }

    public function deleteFromSpatialIndex() {
        $this->dbhm->preExec("DELETE FROM messages_spatial WHERE msgid = ?", [
            $this->id
        ]);
    }

    public function reply($groupid, $subject, $body, $stdmsgid) {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

        $this->log->log([
            'type' => Log::TYPE_MESSAGE,
            'subtype' => Log::SUBTYPE_REPLIED,
            'msgid' => $this->id,
            'user' => $this->fromuser,
            'byuser' => $me ? $me->getId() : NULL,
            'groupid' => $groupid,
            'text' => $subject,
            'stdmsgid' => $stdmsgid
        ]);

        $sql = "SELECT * FROM messages_groups WHERE msgid = ? AND groupid = ?;";
        $groups = $this->dbhr->preQuery($sql, [ $this->id, $groupid ]);
        foreach ($groups as $group) {
            $this->maybeMail($groupid, $subject, $body, $group['collection'] == MessageCollection::APPROVED ? 'Leave Approved Message' : 'Leave');
        }
    }

    function hold() {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

        $sql = "UPDATE messages SET heldby = ? WHERE id = ?;";
        $rc = $this->dbhm->preExec($sql, [ $me->getId(), $this->id ]);

        if ($rc) {
            $this->log->log([
                'type' => Log::TYPE_MESSAGE,
                'subtype' => Log::SUBTYPE_HOLD,
                'msgid' => $this->id,
                'byuser' => $me ? $me->getId() : NULL
            ]);
        }
    }

    function release() {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

        $sql = "UPDATE messages SET heldby = NULL WHERE id = ?;";
        $rc = $this->dbhm->preExec($sql, [ $this->id ]);

        if ($rc) {
            $this->log->log([
                'type' => Log::TYPE_MESSAGE,
                'subtype' => Log::SUBTYPE_RELEASE,
                'msgid' => $this->id,
                'byuser' => $me ? $me->getId() : NULL
            ]);
        }
    }

    function delete($reason = NULL, $groupid = NULL, $subject = NULL, $body = NULL, $stdmsgid = NULL, $localonly = FALSE)
    {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $rc = true;

        if ($this->attach_dir) {
            rrmdir($this->attach_dir);
        }

        if ($this->id) {
            # Delete from a specific or all groups that it's on.
            $sql = "SELECT * FROM messages_groups WHERE msgid = ? AND " . ($groupid ? " groupid = ?" : " ?") . ";";
            $groups = $this->dbhr->preQuery($sql,
                [
                    $this->id,
                    $groupid ? $groupid : 1
                ]);

            $logged = FALSE;

            foreach ($groups as $group) {
                $groupid = $group['groupid'];

                $this->log->log([
                    'type' => Log::TYPE_MESSAGE,
                    'subtype' => Log::SUBTYPE_DELETED,
                    'msgid' => $this->id,
                    'user' => $this->fromuser,
                    'byuser' => $me ? $me->getId() : NULL,
                    'text' => $reason,
                    'groupid' => $groupid,
                    'stdmsgid' => $stdmsgid
                ]);

                # The message has been allocated to a group; mark it as deleted.  We keep deleted messages for
                # PD.
                $rc = $this->dbhm->preExec("UPDATE messages_groups SET deleted = 1 WHERE msgid = ? AND groupid = ?;", [
                    $this->id,
                    $groupid
                ]);

                if ($groupid) {
                    $this->notif->notifyGroupMods($groupid);
                    error_log("Delete notify $groupid");

                    $this->maybeMail($groupid, $subject, $body, $group['collection'] == MessageCollection::APPROVED ? 'Delete Approved Message' : 'Delete');
                }
            }

            # If we have deleted this message from all groups, mark it as deleted in the messages table.
            $sql = "SELECT * FROM messages_groups WHERE msgid = ? AND deleted = 0;";
            $groups = $this->dbhr->preQuery($sql, [ $this->id ]);

            if (count($groups) === 0) {
                # We must zap the messageid as we have a unique index on it
                $rc = $this->dbhm->preExec("UPDATE messages SET deleted = NOW(), messageid = NULL WHERE id = ?;", [ $this->id ]);

                # Remove from the search index.
                $this->deindex();
            }
        }

        return($rc);
    }

    public function index() {
        $groups = $this->getGroups(FALSE, FALSE);
        foreach ($groups as $group) {
            # Add into the search index.  If we can identify the item, we just had that rather than
            # the whole subject.
            $toadd = $this->subject;
            if (preg_match("/(.+)\:(.+)\((.+)\)/", $this->subject, $matches)) {
                $toadd = trim($matches[2]);
            }

            $this->s->add($this->id, $toadd, strtotime($group['arrival']), $group['groupid']);
        }
    }

    public function deindex() {
        $this->s->delete($this->id);
    }

    /**
     * @return mixed
     */
    public function getFromuser()
    {
        return $this->fromuser;
    }

    public function findFromReply($userid) {
        # TN puts a useful header in.
        $msgid = $this->getHeader('x-fd-msgid');

        if ($msgid) {
            # Check the message exists.
            $m = new Message($this->dbhr, $this->dbhm, $msgid);

            if ($m->getID() === $msgid) {
                return($msgid);
            }
        }

        # Unfortunately, it's fairly common for people replying by email to compose completely new
        # emails with subjects of their choice, or reply from Yahoo Groups which doesn't add
        # In-Reply-To headers.  So we just have to do the best we can using the email subject.  The Damerau–Levenshtein
        # distance does this for us - if we get a subject which is just "Re: " and the original, then that will come
        # top.  We can't do that in the DB, though, as we need to strip out some stuff.
        #
        # We only expect to be matching replies for reuse/Freegle groups, and it's not worth matching against any
        # old messages.
        $sql = "SELECT messages.id, subject, messages.date FROM messages INNER JOIN messages_groups ON messages_groups.msgid = messages.id AND fromuser = ? INNER JOIN groups ON groups.id = messages_groups.groupid AND groups.type IN ('Freegle', 'Reuse') AND DATEDIFF(NOW(), messages.arrival) < 90 LIMIT 1000;";
        $messages = $this->dbhr->preQuery($sql, [ $userid ]);

        $thissubj = Message::canonSubj($this->subject);

        # This is expected to be a reply - so remove the most common reply tag.
        $thissubj = preg_replace('/^Re\:/i', '', $thissubj);

        # Remove any punctuation and whitespace from the purported item.
        $thissubj = preg_replace('/\-|\,|\.| /', '', $thissubj);

        $mindist = PHP_INT_MAX;
        $match = FALSE;
        $matchmsg = NULL;

        foreach ($messages as $message) {
            $subj1 = $thissubj;
            $subj2 = Message::canonSubj($message['subject']);

            # Remove any punctuation and whitespace from the purported item.
            $subj2 = preg_replace('/\-|\,|\.| /', '', $subj2);

            # Find the distance.  We do this in PHP rather than in MySQL because we have done all this
            # munging on the subject.
            $d = new DamerauLevenshtein(strtolower($subj1), strtolower($subj2));
            $message['dist'] = $d->getSimilarity();
            $mindist = min($mindist, $message['dist']);

            error_log("Compare subjects $subj1 vs $subj2 dist {$message['dist']} min $mindist lim " . (strlen($subj1) * 3 / 4));

            if ($message['dist'] <= $mindist && $message['dist'] <= strlen($subj1) * 3 / 4) {
                # This is the closest match, but not utterly different.
                #error_log("Closest");
                $match = TRUE;
                $matchmsg = $message;
            }
        }

        return(($match && $matchmsg['id']) ? $matchmsg['id'] : NULL);
    }
    
    public function stripQuoted() {
        # Try to get the text we care about by stripping out quoted text.  This can't be
        # perfect - quoting varies and it's a well-known hard problem.
        $htmlbody = $this->getHtmlbody();
        $textbody = $this->getTextbody();

        if ($htmlbody && !$textbody) {
            $html = new \Html2Text\Html2Text($htmlbody);
            $textbody = $html->getText();
            #error_log("Converted HTML text $textbody");
        }

        # Convert unicode spaces to ascii spaces
        $textbody = str_replace("\xc2\xa0", "\x20", $textbody);

        # Remove basic quoting.
        #error_log("BEfore quote $textbody");
        $textbody = trim(preg_replace('#(^(>|\|).*(\n|$))+#mi', "", $textbody));
        #error_log("After squote $textbody");

        # We might have a section like this, for example from eM Client, which could be top or bottom-quoted.
        #
        # ------ Original Message ------
        # From: "Edward Hibbert" <notify-5147-16226909@users.ilovefreegle.org>
        # To: log@ehibbert.org.uk
        # Sent: 14/05/2016 14:19:19
        # Subject: Re: [FreeglePlayground] Offer: chair (Hesketh Lane PR3)
        $p = strpos($textbody, '------ Original Message ------');

        if ($p !== FALSE) {
            $q = strpos($textbody, "\r\n\r\n", $p);
            $textbody = ($q !== FALSE) ? (substr($textbody, 0, $p) . substr($textbody, $q)) : substr($textbody, 0, $p);
        }

        # Or this similar one, which is top-quoted.
        #
        # ----Original message----
        $p = strpos($textbody, '----Original message----');
        $textbody = $p ? substr($textbody, 0, $p) : $textbody;

        # Or this, which is top-quoted.
        $p = strpos($textbody, '--------------------------------------------');
        $textbody = $p ? substr($textbody, 0, $p) : $textbody;

        # Language, language.
        $p = strpos($textbody, '-------- Mensagem original --------');
        $textbody = $p ? substr($textbody, 0, $p) : $textbody;

        # Or TN's
        #
        # _________________________________________________________________
        $p = strpos($textbody, '_________________________________________________________________');
        $textbody = $p ? substr($textbody, 0, $p) : $textbody;

        # Or this:
        #
        # -------- Original message --------
        $p = strpos($textbody, '-------- Original message --------');
        $textbody = $p ? substr($textbody, 0, $p) : $textbody;

        # Or this:
        #
        # ----- Original Message -----
        $p = strpos($textbody, '----- Original Message -----');
        $textbody = $p ? substr($textbody, 0, $p) : $textbody;

        # Or this:
        # _____
        $p = strpos($textbody, '_____');
        $textbody = $p ? substr($textbody, 0, $p) : $textbody;

        # Or this:
        # _____
        $p = strpos($textbody, '-----Original Message-----');
        $textbody = $p ? substr($textbody, 0, $p) : $textbody;

        # Or Windows phones:
        #
        # ________________________________
        $p = strpos($textbody, '________________________________');
        $textbody = $p ? substr($textbody, 0, $p) : $textbody;

        # Some group sigs
        $p = strpos($textbody, '~*~*~*~*~*~*');
        $textbody = $p ? substr($textbody, 0, $p) : $textbody;

        # Or maybe just the headers with no preceding line.
        if (preg_match('/(.*)^From\:.*?ilovefreegle.org$(.*)/ms', $textbody, $matches)) {
            $textbody = $matches[1] . $matches[2];
        }
        if (preg_match('/(.*)^From\:.*?trashnothing.com$(.*)/ms', $textbody, $matches)) {
            $textbody = $matches[1] . $matches[2];
        }

        # A reply from us.
        $p = strpos($textbody, "You can respond by just replying to this email");
        $textbody = $p ? substr($textbody, 0, $p) : $textbody;

        # Or we might have this, for example from GMail:
        #
        # On Sat, May 14, 2016 at 2:19 PM, Edward Hibbert <
        # notify-5147-16226909@users.ilovefreegle.org> wrote:
        #
        # We're assuming here that everyone replies at the top.  Standard mail clients do this.
        if (preg_match('/(.*)^\s*On.*?wrote\:(\s*)/ms', $textbody, $matches)) {
            $textbody = $matches[1];
        }

        # Or we might have this, as a reply from a Yahoo Group message.
        if (preg_match('/(.*)^To\:.*yahoogroups.*$.*__,_._,___(.*)/ms', $textbody, $matches)) {
            $textbody = $matches[1] . $matches[2];
        }

        if (preg_match('/(.*?)__,_._,___(.*)/ms', $textbody, $matches)) {
            $textbody = $matches[1];
        }

        if (preg_match('/(.*?)__._,_.___(.*)/ms', $textbody, $matches)) {
            $textbody = $matches[1];
        }

        # Or we might have some headers, possibly indented with space.
        $textbody = preg_replace('/[\r\n](\s*)To:.*?$/is', '', $textbody);
        $textbody = preg_replace('/[\r\n](\s*)From:.*?$/is', '', $textbody);
        $textbody = preg_replace('/[\r\n](\s*)Sent:.*?$/is', '', $textbody);
        $textbody = preg_replace('/[\r\n](\s*)Date:.*?$/is', '', $textbody);
        $textbody = preg_replace('/[\r\n](\s*)Subject:.*?$/is', '', $textbody);

        # Get rid of sigs
        $textbody = $this->stripSigs($textbody);

        // Duff text added by Yahoo Mail app.
        $textbody = str_replace('blockquote, div.yahoo_quoted { margin-left: 0 !important; border-left:1px #715FFA solid !important; padding-left:1ex !important; background-color:white !important; }', '', $textbody);
        $textbody = preg_replace('/\#yiv.*\}\}/', '', $textbody);

        #error_log("Pruned text to $textbody");

        // We might have links to our own site with login information.
        $textbody = preg_replace('/(https:\/\/' . USER_SITE . '\S*)(k=\S*)/', '$1', $textbody);

        // Redundant line breaks.
        $textbody = preg_replace('/(?:(?:\r\n|\r|\n)\s*){2}/s', "\r\n\r\n", $textbody);

        // Our own footer.
        $textbody = preg_replace('/This message was from user .*?, and this mail was sent to .*?$/mi', "", $textbody);
        $textbody = preg_replace('/Freegle is registered as a charity.*?nice./mi', "", $textbody);
        $textbody = preg_replace('/This mail was sent to.*?/mi', "", $textbody);
        $textbody = preg_replace('/You can change your settings by clicking here.*?/mi', "", $textbody);

        # | is used to quote.
        $textbody = preg_replace('/^\|/mi', '', $textbody);

        $textbody = trim($textbody);
        if (substr($textbody, -1) == '|') {
            $textbody = substr($textbody, 0, strlen($textbody) - 1);
        }

        // Left over inline image references
        $textbody = preg_replace('/\[cid\:.*?\]/', '', $textbody);

        # Strip underscores and dashes, which can arise due to quoting issues.
        return(trim($textbody, " \t\n\r\0\x0B_-"));
    }

    public function stripSigs($textbody) {
        $textbody = preg_replace('/^Get Outlook for Android.*/ims', '', $textbody);
        $textbody = preg_replace('/^Get Outlook for IOS.*/ims', '', $textbody);
        $textbody = preg_replace('/^Sent from my Xperia.*/ims', '', $textbody);
        $textbody = preg_replace('/^Sent from my BlueMail/ims', '', $textbody);
        $textbody = preg_replace('/^Sent using the mail.com mail app.*/ims', '', $textbody);
        $textbody = preg_replace('/^Sent from my phone.*/ims', '', $textbody);
        $textbody = preg_replace('/^Sent from my iPad.*/ims', '', $textbody);
        $textbody = preg_replace('/^Sent from my .*smartphone./ims', '', $textbody);
        $textbody = preg_replace('/^Sent from my iPhone.*/ims', '', $textbody);
        $textbody = preg_replace('/Sent.* from my iPhone/i', '', $textbody);
        $textbody = preg_replace('/^Sent from EE.*/ims', '', $textbody);
        $textbody = preg_replace('/^Sent from my Samsung device.*/ims', '', $textbody);
        $textbody = preg_replace('/^Sent from my Galaxy smartphone.*/ims', '', $textbody);
        $textbody = preg_replace('/^Sent from my Samsung Galaxy smartphone.*/ims', '', $textbody);
        $textbody = preg_replace('/^Sent from my Windows Phone.*/ims', '', $textbody);
        $textbody = preg_replace('/^Sent from the trash nothing! Mobile App.*/ims', '', $textbody);
        $textbody = preg_replace('/^Sent from my account on trashnothing.com.*/ims', '', $textbody);
        $textbody = preg_replace('/^Save time browsing & posting to.*/ims', '', $textbody);
        $textbody = preg_replace('/^Sent on the go from.*/ims', '', $textbody);
        $textbody = preg_replace('/^Sent from Yahoo Mail.*/ims', '', $textbody);
        $textbody = preg_replace('/^Sent from Windows Mail.*/ims', '', $textbody);
        $textbody = preg_replace('/^Sent from Mail.*/ims', '', $textbody);
        $textbody = preg_replace('/^Sent from my BlackBerry.*/ims', '', $textbody);
        $textbody = preg_replace('/^Sent from my Huawei Mobile.*/ims', '', $textbody);
        $textbody = preg_replace('/^Sent from my Huawei phone.*/ims', '', $textbody);
        $textbody = preg_replace('/^Sent from myMail for iOS.*/ims', '', $textbody);
        $textbody = preg_replace('/^Von meinem Samsung Galaxy Smartphone gesendet.*/ims', '', $textbody);
        $textbody = preg_replace('/^Sent from Samsung Mobile.*/ims', '', $textbody);
        $textbody = preg_replace('/^(\r\n|\r|\n)---(\r\n|\r|\n)This email has been checked for viruses.*/ims', '', $textbody);
        $textbody = preg_replace('/^Sent from TypeApp.*/ims', '', $textbody);
        $textbody = preg_replace('/^Enviado a partir do meu smartphone.*/ims', '', $textbody);
        $textbody = preg_replace('/^Getting too many emails from.*Free your inbox.*trashnothing.com/ims', '', $textbody);
        $textbody = preg_replace('/^Use your phone to browse and post to.*trashnothing.com/ims', '', $textbody);
        $textbody = preg_replace('/^Get instant email alerts when items.*trashnothing.com/ims', '', $textbody);
        $textbody = preg_replace('/^Try trashnothing.com for quicker and easier access.*!/ims', '', $textbody);
        $textbody = preg_replace('/^Discover a better way to browse.*trashnothing.com/ims', '', $textbody);

        return(trim($textbody));
    }
    
    public static function canonSubj($subj, $lower = TRUE) {
        if ($lower) {
            $subj = strtolower($subj);
        }

        // Remove any group tag
        $subj = preg_replace('/^\[.*?\](.*)/', "$1", $subj);

        // Remove duplicate spaces
        $subj = preg_replace('/\s+/', ' ', $subj);

        $subj = trim($subj);

        return($subj);
    }

    public static function removeKeywords($type, $subj) {
        $keywords = Message::keywords();
        if (Utils::pres($type, $keywords)) {
            foreach ($keywords[$type] as $keyword) {
                $subj = preg_replace('/(^|\b)' . preg_quote($keyword) . '\b/i', '', $subj);
            }
        }

        return($subj);
    }

    public function recordRelated() {
        # Message A is related to message B if:
        # - they are from the same underlying sender (people may post via multiple routes)
        # - A is an OFFER and B a TAKEN, or A is a WANTED and B is a RECEIVED
        # - the TAKEN/RECEIVED is more recent than the OFFER/WANTED (because if it's earlier, it can't be a TAKEN for this OFFER)
        # - the OFFER/WANTED is more recent than any previous previous similar TAKEN/RECEIVED (because then we have a repost
        #   or similar items scenario, and the earlier TAKEN will be related to still earlier OFFERs
        #
        # We might explicitly flag a message using X-Iznik-Related-To
        switch ($this->type) {
            case Message::TYPE_OFFER: $type = Message::TYPE_TAKEN; $datedir = 1; break;
            case Message::TYPE_TAKEN: $type = Message::TYPE_OFFER; $datedir = -1; break;
            case Message::TYPE_WANTED: $type = Message::TYPE_RECEIVED; $datedir = 1; break;
            case Message::TYPE_RECEIVED: $type = Message::TYPE_WANTED; $datedir = -1; break;
            default: $type = NULL;
        }

        $found = 0;
        $loc = NULL;

        $thissubj = Message::canonSubj($this->subject);

        if (preg_match('/.*?\:.*\((.*)\)/', $thissubj, $matches)) {
            $loc = trim($matches[1]);
        }

        if (preg_match('/.*?\:(.*)\(.*\)/', $this->subject, $matches)) {
            # Standard format - extract the item.
            $thissubj = trim($matches[1]);
        } else {
            # Non-standard format.  Remove the keywords.
            $thissubj = Message::removeKeywords($this->type, $thissubj);
        }

        # Remove any punctuation and whitespace from the purported item.
        $thissubj = preg_replace('/\-|\,|\.| /', '', $thissubj);

        if ($type) {
            # Don't want to look for any messages which already have an outcome, otherwise we would fail to handle
            # crosspost messages correctly - we'd link all TAKENs to the same OFFER.
            #
            # A consequence of this is that we may not relate TAKEN/RECEIVED for platform messages correctly as
            # we create an outcome first and then send the message to Yahoo.  But that is less bad, and Yahoo is
            # on its way out.
            #
            # No point caching as we are unlikely to repeat this query.
            $groupq = $this->groupid ? (" INNER JOIN messages_groups ON messages_groups.msgid = messages.id AND messages_groups.groupid = " . $this->dbhr->quote($this->groupid) . " ") : '';
            $sql = "SELECT messages.id, subject, date FROM messages LEFT JOIN messages_outcomes ON messages.id = messages_outcomes.msgid $groupq WHERE fromuser = ? AND type = ? AND DATEDIFF(NOW(), messages.arrival) <= 60 AND messages_outcomes.id IS NULL;";
            $messages = $this->dbhr->preQuery($sql, [ $this->fromuser, $type ], FALSE);
            #error_log($sql . var_export([ $thissubj, $thissubj, $this->fromuser, $type ], TRUE));
            $thistime = strtotime($this->date);

            $mindist = PHP_INT_MAX;
            $match = FALSE;
            $matchmsg = NULL;

            foreach ($messages as $message) {
                $messsubj = Message::canonSubj($message['subject']);
                #error_log("Compare {$message['date']} vs {$this->date}, " . strtotime($message['date']) . " vs $thistime");

                if ((($datedir == 1) && strtotime($message['date']) >= $thistime) ||
                    (($datedir == -1) && strtotime($message['date']) <= $thistime)) {
                    if (preg_match('/.*?\:(.*)\(.*\)/', $messsubj, $matches)) {
                        # Standard format = extract the item.
                        $subj2 = trim($matches[1]);
                    } else {
                        # Non-standard - remove keywords.
                        $subj2 = Message::removeKeywords($type, $messsubj);

                        # We might have identified a valid location in the original message which appears in a non-standard
                        # way.
                        $subj2 = $loc ? str_ireplace($loc, '', $subj2) : $subj2;
                    }

                    $subj1 = $thissubj;

                    # Remove any punctuation and whitespace from the purported item.
                    $subj2 = preg_replace('/\-|\,|\.| /', '', $subj2);

                    # Find the distance.  We do this in PHP rather than in MySQL because we have done all this
                    # munging on the subject to extract the relevant bit.
                    $d = new DamerauLevenshtein(strtolower($subj1), strtolower($subj2));
                    $message['dist'] = $d->getSimilarity();
                    $mindist = min($mindist, $message['dist']);

                    #error_log("Compare subjects $subj1 vs $subj2 dist {$message['dist']} min $mindist lim " . (strlen($subj1) * 3 / 4));

                    if (strtolower($subj1) == strtolower($subj2)) {
                        # Exact match
                        #error_log("Exact");
                        $match = TRUE;
                        $matchmsg = $message;
                    } else if ($message['dist'] <= $mindist && $message['dist'] <= strlen($subj1) * 3 / 4) {
                        # This is the closest match, but not utterly different.
                        #error_log("Closest");
                        $match = TRUE;
                        $matchmsg = $message;
                    }
                }
            }

            #error_log("Match $match message " . var_export($matchmsg, TRUE));

            if ($match && $matchmsg['id']) {
                # We seem to get a NULL returned in circumstances I don't quite understand but but which relate to
                # the use of DAMLEVLIM.
                #error_log("Best match {$matchmsg['subject']}");
                $sql = "INSERT IGNORE INTO messages_related (id1, id2) VALUES (?,?);";
                $this->dbhm->preExec($sql, [ $this->id, $matchmsg['id']] );

                if ($this->getSourceheader() != Message::PLATFORM &&
                    ($this->type == Message::TYPE_TAKEN || $this->type == Message::TYPE_RECEIVED)) {
                    # Also record an outcome on the original message.  We only need to do this when the message didn't
                    # come from our platform, because if it did that has already happened.  This also avoids the
                    # situation where we match against the wrong message because of the order messages arrive from Yahoo.
                    $tnwithdraw = $this->getHeader('x-trash-nothing-withdrawn');

                    if ($tnwithdraw) {
                        $outcome = Message::OUTCOME_WITHDRAWN;
                    } else {
                        $outcome = $this->type == Message::TYPE_TAKEN ? Message::OUTCOME_TAKEN : Message::OUTCOME_RECEIVED;
                    }

                    $this->dbhm->preExec("INSERT INTO messages_outcomes (msgid, outcome, happiness, comments) VALUES (?,?,?,?);", [
                        $matchmsg['id'],
                        $outcome,
                        NULL,
                        $this->interestingComment($this->stripQuoted($this->getTextbody()))
                    ]);
                }

                $found++;
            }
        }

        return($found);
    }

    public function spam($groupid) {
        # We mark is as spam on all groups, and delete it on the specific one in question.
        $this->dbhm->preExec("UPDATE messages_groups SET collection = ? WHERE msgid = ?;", [ MessageCollection::SPAM, $this->id ]);
        $this->delete("Deleted as spam", $groupid);

        # Record for training.
        $this->dbhm->preExec("REPLACE INTO messages_spamham (msgid, spamham) VALUES (?, ?);", [ $this->id , Spam::SPAM ]);
    }

    public function sendForReview($reason)
    {
        # Send for review.
        $this->dbhm->preExec("UPDATE messages SET spamreason = ? WHERE id = ?", [
            $reason,
            $this->id
        ]);

        $this->dbhm->preExec("UPDATE messages_groups SET collection = ? WHERE msgid = ?;",
            [MessageCollection::SPAM, $this->id]
        );
    }

    public function notSpam() {
        if ($this->spamtype == Spam::REASON_SUBJECT_USED_FOR_DIFFERENT_GROUPS) {
            # This subject is probably fine, then.
            $s = new Spam($this->dbhr, $this->dbhm);
            $s->notSpamSubject($this->getPrunedSubject());
        }

        # We leave the spamreason and type set in the message, because it can be useful for later PD.
        #
        # Record for training.
        $this->dbhm->preExec("REPLACE INTO messages_spamham (msgid, spamham) VALUES (?, ?);", [ $this->id , Spam::HAM ]);
    }

    public function suggestSubject($groupid, $subject) {
        $newsubj = $subject;
        $type = $this->determineType($subject);

        # We only need to do this for OFFER/WANTED messages (TAKEN/RECEIVED are less important and tend to be
        # automatically generated), and also for messages which aren't from a platform which constructs the subject
        # line in a trustworthy way (i.e. us and TN).
        $srchdr = $this->getSourceheader();

        if ($srchdr != Message::PLATFORM && strpos($srchdr, 'TN-') !== 0 &&
            ($type == Message::TYPE_OFFER || $type == Message::TYPE_WANTED)) {
            # We only need to do this if the message came from elsewhere - ones we composed are well formatted and
            # already mapped.
            $g = Group::get($this->dbhr, $this->dbhm, $groupid);

            # This method is used to improve subjects, and also to map - because we need to make sure we understand the
            # subject format before can map.
            $keywords = $g->getSetting('keywords', []);

            # Remove any subject tag.
            $subject = preg_replace('/\[.*?\]\s*/', '', $subject);

            $pretag = $subject;

            # Strip any of the keywords.
            foreach ($this->keywords()[$type] as $keyword) {
                $subject = preg_replace('/(^|\b)' . preg_quote($keyword) . '\b/i', '', $subject);
            }

            # Only proceed if we found the type tag.
            if ($subject != $pretag) {
                # Shrink multiple spaces
                $subject = preg_replace('/\s+/', ' ', $subject);
                $subject = trim($subject);

                # Find a location in the subject.  Only seek ) at end because if it's in the middle it's probably
                # not a location.
                $loc = NULL;
                $l = new Location($this->dbhr, $this->dbhm);

                if (preg_match('/(.*)\((.*)\)$/', $subject, $matches)) {
                    # Find the residue, which will be the item, and tidy it.
                    $residue = trim($matches[1]);

                    $aloc = $matches[2];

                    # Check if it's a good location.
                    #error_log("Check loc $aloc");
                    $locs = $l->search($aloc, $groupid, 1);
                    #error_log(var_export($locs, TRUE));

                    if (count($locs) == 1) {
                        # Take the name we found, which may be better than the one we have, if only in capitalisation.
                        $loc = $locs[0];
                    }
                } else {
                    # The subject is not well-formed.  But we can try anyway.
                    #
                    # Look for an exact match for a known location in the subject.
                    $locs = $l->locsForGroup($groupid);
                    $bestpos = 0;
                    $bestlen = 0;
                    $loc = NULL;

                    foreach ($locs as $aloc) {
                        #error_log($aloc['name']);
                        $xp = '/\b' . preg_quote($aloc['name'],'/') . '\b/i';
                        #error_log($xp);
                        $p = preg_match($xp, $subject, $matches, PREG_OFFSET_CAPTURE);
                        #error_log("$subject matches as $p with $xp");
                        $p = $p ? $matches[0][1] : FALSE;
                        #error_log("p2 $p");

                        if ($p !== FALSE &&
                            (strlen($aloc['name']) > $bestlen ||
                                (strlen($aloc['name']) == $bestlen && $p > $bestpos))) {
                            # The longer a location is, the more likely it is to be the correct one.  If we get a
                            # tie, then the further right it is, the more likely to be a location.
                            $loc = $aloc;
                            $bestpos = $p;
                            $bestlen = strlen($loc['name']);
                        }
                    }

                    $residue = preg_replace('/' . preg_quote($loc['name']) . '/i', '', $subject);
                }

                if ($loc) {
                    $punc = '\(|\)|\[|\]|\,|\.|\-|\{|\}|\:|\;| ';
                    $residue = preg_replace('/^(' . $punc . ')*/','', $residue);
                    $residue = preg_replace('/(' . $punc . '){2,}$/','', $residue);
                    $residue = trim($residue);

                    if ($residue == strtoupper($residue)) {
                        # All upper case.  Stop it being shouty.
                        $residue = strtolower($residue);
                    }

                    $typeval = Utils::presdef(strtolower($type), $keywords, strtoupper($type));
                    $newsubj = $typeval . ": $residue ({$loc['name']})";

                    $this->lat = $loc['lat'];
                    $this->lng = $loc['lng'];
                    $this->locationid = $loc['id'];

                    if ($this->fromuser) {
                        # Save off this as the last known location for this user.
                        $this->dbhm->preExec("UPDATE users SET lastlocation = ? WHERE id = ?;", [
                            $this->locationid,
                            $this->fromuser
                        ]);
                        User::clearCache($this->fromuser);
                    }
                }
            }
        }

        return($newsubj);
    }

    public function replaceAttachments($atts) {
        # We have a list of attachments which may or may not currently be attached to the message we're interested in,
        # which might have other attachments which need zapping.
        $oldids = [];
        $oldatts = $this->dbhm->preQuery("SELECT id FROM messages_attachments WHERE msgid = ?;", [ $this->id ]);
        foreach ($oldatts as $oldatt) {
            $oldids[] = $oldatt['id'];
        }

        foreach ($atts as $attid) {
            if ($attid) {
                $this->dbhm->preExec("UPDATE messages_attachments SET msgid = ? WHERE id = ?;", [ $this->id, $attid ]);
                $key = array_search($attid, $oldids);
                if ($key !== FALSE) {
                    unset($oldids[$key]);
                }
            }
        }

        foreach ($oldids as $oldid) {
            $this->dbhm->preExec("DELETE FROM messages_attachments WHERE id = ?;", [ $oldid ]);
        }
    }

    public function deleteAllAttachments() {
        $this->dbhm->preExec("DELETE FROM messages_attachments WHERE msgid = ?;", [ $this->id ]);
    }

    public function searchActiveInGroups($string, $messagetype, $exactonly, $groupids) {
        # Now find the messages in that area.  Need a high limit because we need to see them all, e.g. on a map.
        $ctx = NULL;
        $searched = $this->search($string, $ctx, 1000, NULL, $groupids, NULL, $exactonly);
        $msgids = array_filter(array_column($searched, 'id'));

        if (count($msgids)) {
            # Find which of these messages are on a group, not deleted, have no outcome, are the
            # right type.
            $typeq = '';

            if ($messagetype === Message::TYPE_OFFER) {
                $typeq = " AND messages.type = 'Offer'";
            } else if ($messagetype === Message::TYPE_WANTED) {
                $typeq = " AND messages.type = 'Wanted'";
            }

            $sql = "SELECT messages.id, messages.lat, messages.lng, messages.type, groupid, messages_groups.arrival FROM messages INNER JOIN messages_groups ON messages_groups.msgid = messages.id LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages.id WHERE messages.id IN (" . implode(',', $msgids) . ") AND messages.deleted IS NULL AND collection = ? AND messages_outcomes.msgid IS NULL $typeq;";
            $ret = $this->dbhr->preQuery($sql, [
                MessageCollection::APPROVED
            ]);

            # We need to return the info about why we matched.
            foreach ($ret as &$r) {
                foreach ($searched as $s) {
                    if ($r['id'] == $s['id']) {
                        $r['matchedon'] = $s['matchedon'];
                    }
                }
            }
        }

        return $ret;
    }

    public function searchActiveInBounds($string, $messagetype, $swlat, $swlng, $nelat, $nelng, $groupid = NULL, $exactonly = FALSE) {
        $ret = [];

        # First get the groups which overlap the bounds.
        $poly = "POLYGON(($swlng $swlat, $swlng $nelat, $nelng $nelat, $nelng $swlat, $swlng $swlat))";

        if (!$groupid) {
            $sql = "SELECT id FROM groups WHERE ST_Intersects(polyindex, GeomFromText('$poly')) AND onmap = 1 AND publish = 1;";
            $groups = $this->dbhr->preQuery($sql);
            $groupids = array_filter(array_column($groups, 'id'));
        } else {
            $groupids = [ $groupid ];
        }

        if (count($groupids)) {
            # Now find the messages in that area.  Need a high limit because we need to see them all, e.g. on a map.
            $ctx = NULL;
            $searched = $this->search($string, $ctx, 1000, NULL, $groupids, NULL, $exactonly);
            $msgids = array_filter(array_column($searched, 'id'));

            if (count($msgids)) {
                # Find which of these messages are within the bounds, on a group, not deleted, have no outcome, are the
                # right type.
                $typeq = '';

                if ($messagetype === Message::TYPE_OFFER) {
                    $typeq = " AND messages.type = 'Offer'";
                } else if ($messagetype === Message::TYPE_WANTED) {
                    $typeq = " AND messages.type = 'Wanted'";
                }

                $sql = "SELECT messages.id, messages.lat, messages.lng, messages.type, groupid, messages_groups.arrival FROM messages INNER JOIN messages_groups ON messages_groups.msgid = messages.id LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages.id WHERE messages.id IN (" . implode(',', $msgids) . ") AND $swlat <= lat AND $swlng <= lng AND $nelat >= lat AND $nelng >= lng AND messages.deleted IS NULL AND messages_outcomes.msgid IS NULL AND collection = ? $typeq;";
                $ret = $this->dbhr->preQuery($sql, [
                    MessageCollection::APPROVED
                ]);

                # We need to return the info about why we matched.
                foreach ($ret as &$r) {
                    foreach ($searched as $s) {
                        if ($r['id'] == $s['id']) {
                            $r['matchedon'] = $s['matchedon'];
                        }
                    }
                }
            }
        }

        return($ret);
    }

    public function search($string, &$context, $limit = Search::Limit, $restrict = NULL, $groups = NULL, $locationid = NULL, $exactonly = FALSE) {
        # First find the items that match this string.  Join on the spatial index so that we only identify items which
        # are actually active at the moment.
        $ret = [];
        $ctx = NULL;
        $joinq = "INNER JOIN messages_items ON items_index.itemid = messages_items.itemid INNER JOIN messages_spatial ON messages_spatial.msgid = messages_items.msgid";

        $matches = $this->si->search($string, $ctx, 10000, NULL, NULL, FALSE, NULL, $joinq);
        $items = [];
        $itemids = [];

        foreach ($matches as $match) {
            if (!array_key_exists($match['id'], $itemids)) {
                $itemids[$match['id']] = $match['id'];
                $items[] = [
                    'item' => $match['id'],
                    'count' => PHP_INT_MAX,
                    'matchedon' => $match['matchedon']
                ];
            }
        }

        if (count($itemids)) {
            if (!$exactonly) {
                # Add any items which we have been told are related.
                $itemq = implode(',', $itemids);
                $sql = "SELECT item2 AS item, COUNT(*) AS count FROM `microactions` WHERE `item1` IN ($itemq) HAVING count > 2 UNION
            SELECT item1 AS item, COUNT(*) AS count FROM `microactions` WHERE `item2` IN ($itemq) HAVING count > 2 ORDER BY count DESC;";
                #error_log("Look for similar $sql");
                $related = $this->dbhr->preQuery($sql);

                foreach ($related as $related) {
                    if (!array_key_exists($related['item'], $itemids)) {
                        $itemids[$related['item']] = $related['item'];
                        $thisone = [
                            'item' => $related['item'],
                            'count' => $related['count'],
                        ];

                        foreach ($matches as $match) {
                            if ($match['id'] == $related['item']) {
                                $thisone['matchedon'] = $match['matchedon'];
                                $thisone['matchedon']['type'] = 'Related';
                            }
                        }

                        $items[] = $thisone;
                    }
                }
            }

            # Don't allow silly numbers of matches.
            $items = array_slice($items, 0, 10000);

            # Now we have a list of item ids which are relevant to the search and are for extant messages. Maybe
            # we need to filter by groupid.
            $joinq = $groups ? " INNER JOIN messages_groups ON messages_groups.msgid = messages_items.msgid AND messages_groups.groupid IN (" . implode(',', $groups) . ")" : '';
            $sql = "SELECT messages_spatial.msgid AS id, messages_items.itemid FROM messages_spatial INNER JOIN
                messages_items ON messages_items.msgid = messages_spatial.msgid
                $joinq
                WHERE itemid IN (" . implode(',', array_column($items, 'item')) . ")";
            #error_log("Search on items $sql");

            $msgs = $this->dbhr->preQuery($sql);

            foreach ($msgs as &$msg) {
                foreach ($items as $item) {
                    if ($msg['itemid'] == $item['item']) {
                        $msg['count'] = $item['count'];
                        $msg['matchedon'] = $item['matchedon'];
                    }
                }
            }

            # Now sort with the item matches at the start and related matches at the end, and within those
            # by how related.
            usort($msgs, function ($a, $b) {
                if ($a['matchedon']['type'] == 'Related' && $b['matchedon']['type'] != 'Related') {
                    return 1;
                } else if ($b['matchedon']['type'] == 'Related' && $a['matchedon']['type'] != 'Related') {
                    return -1;
                } else {
                    return ($b['count'] - $a['count']);
                }
            });

            $ret = $msgs;
        }

        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $myid = $me ? $me->getId() : NULL;

        if (count($ret) > 0) {
            $maxid = $ret[0]['id'];
            $s = new UserSearch($this->dbhr, $this->dbhm);
            $s->create($myid, $maxid, $string, $locationid);
        }

        if ($myid) {
            $this->dbhm->preExec("INSERT INTO search_history (userid, term, locationid, groups) VALUES (?, ?, ?, ?);", [
                $myid,
                $string,
                $locationid,
                $groups ? implode(',', $groups) : NULL
            ]);
        }

        return($ret);
    }

    public function constructSubject($groupid) {
        # Construct the subject - do this now as it may get displayed to the user before we get the membership.
        $g = Group::get($this->dbhr, $this->dbhm, $groupid);
        $keywords = $g->getSetting('keywords', $g->defaultSettings['keywords']);

        $locationid = $this->getPrivate('locationid');
        $items = $this->getItems();

        if ($locationid && count($items) > 0) {
            $l = new Location($this->dbhr, $this->dbhm, $locationid);
            $areaid = $l->getPrivate('areaid');
            $pcid = $l->getPrivate('postcodeid');

            # Normally we should have an area and postcode to use, but as a fallback we use the area we have.
            if ($areaid && $pcid) {
                $includearea = $g->getSetting('includearea', TRUE);
                $includepc = $g->getSetting('includepc', TRUE);

                if ($includearea && $includepc) {
                    # We want the area in the group, e.g. Edinburgh EH4.
                    $la = new Location($this->dbhr, $this->dbhm, $areaid);
                    $loc = $la->getPrivate('name') . ' ' . $l->ensureVague();
                } else if ($includepc) {
                    # Just postcode, e.g. EH4
                    $loc = $l->ensureVague();
                } else  {
                    # Just area or foolish settings, e.g. Edinburgh
                    $la = new Location($this->dbhr, $this->dbhm, $areaid);
                    $loc = $la->getPrivate('name');
                }
            } else {
                $l = new Location($this->dbhr, $this->dbhm, $locationid);
                $loc = $l->ensureVague();
            }

            $subject = Utils::presdef(strtolower($this->type), $keywords, strtoupper($this->type)) . ': ' . $items[0]['name'] . " ($loc)";
            $this->setPrivate('subject', $subject);
        }
    }

    public function addItem($itemid) {
        # Ignore duplicate msgid/itemid.
        $this->dbhm->preExec("INSERT IGNORE INTO messages_items (msgid, itemid) VALUES (?, ?);", [ $this->id, $itemid]);
    }

    public function getItems() {
        return($this->dbhr->preQuery("SELECT * FROM messages_items INNER JOIN items ON messages_items.itemid = items.id WHERE msgid = ?;", [ $this->id ]));
    }

    public function submit(User $fromuser, $fromemail, $groupid) {
        $rc = FALSE;
        $this->setPrivate('fromuser', $fromuser->getId());

        # Submit a draft or repost a message. Either way, it currently has:
        #
        # - a locationid
        # - a type
        # - an item
        # - a subject
        # - a fromuser
        # - a textbody
        # - zero or more attachments
        #
        # We need to turn this into a full message:
        # - create a Message-ID
        # - other bits and pieces
        # - create a full MIME message
        # - send it
        # - remove it from the drafts table
        # - remove any previous outcomes.
        $atts = $this->getPublic(FALSE, FALSE, TRUE);

        if (Utils::pres('location', $atts)) {
            $messageid = $this->id . '@' . USER_DOMAIN;
            $this->setPrivate('messageid', $messageid);

            $this->setPrivate('fromaddr', $fromemail);
            $this->setPrivate('fromaddr', $fromemail);
            $this->setPrivate('fromname', $fromuser->getName());
            $this->setPrivate('lat', $atts['location']['lat']);
            $this->setPrivate('lng', $atts['location']['lng']);

            # Save off this as the last known location for this user.
            $fromuser->setPrivate('lastlocation', $atts['location']['id']);

            $g = Group::get($this->dbhr, $this->dbhm, $groupid);
            $this->setPrivate('envelopeto', $g->getGroupEmail());

            $this->dbhm->preExec("DELETE FROM messages_outcomes WHERE msgid = ?;", [ $this->id ]);
            $this->dbhm->preExec("DELETE FROM messages_outcomes_intended WHERE msgid = ?;", [ $this-> id ]);

            # The from IP and country.
            $ip = Utils::presdef('REMOTE_ADDR', $_SERVER, NULL);

            if ($ip) {
                $this->setPrivate('fromip', $ip);

                try {
                    $reader = new Reader(MMDB);
                    $record = $reader->country($ip);
                    $this->setPrivate('fromcountry', $record->country->isoCode);
                } catch (\Exception $e) {
                    # Failed to look it up.
                    error_log("Failed to look up $ip " . $e->getMessage());
                }
            }

            $txtbody = $this->textbody;
            $this->setPrivate('textbody', $txtbody);

            # Strip possible group name.
            $subject = $this->subject;
            if (preg_match('/\[.*?\](.*)/', $subject, $matches)) {
                $subject = trim($matches[1]);
            }

            # Notify the group mods.
            $rc = TRUE;
            $n = new PushNotifications($this->dbhr, $this->dbhm);
            $n->notifyGroupMods($groupid);
            error_log("Submit notify $groupid");

            # This message is now not a draft.
            $this->dbhm->preExec("DELETE FROM messages_drafts WHERE msgid = ?;", [ $this->id ]);

            # Record the posting, which is also used in producing the messagehistory.
            $this->dbhm->preExec("INSERT INTO messages_postings (msgid, groupid) VALUES (?,?);", [ $this->id, $groupid ]);
        }

        return($rc);
    }

    public function promise($userid) {
        # Promise this item to a user.  A message can be promised to multiple users.
        $sql = "REPLACE INTO messages_promises (msgid, userid) VALUES (?, ?);";
        $this->dbhm->preExec($sql, [
            $this->id,
            $userid
        ]);
    }

    public function renege($userid) {
        # Unpromise this item.
        #
        # Record it - we use this to determine member reliability.
        if ($userid !== $this->getFromuser()) {
            $this->dbhm->preExec("INSERT INTO messages_reneged (userid, msgid) VALUES (?, ?);", [
                $userid,
                $this->id
            ]);
        }

        $sql = "DELETE FROM messages_promises WHERE msgid = ? AND userid = ?;";
        $this->dbhm->preExec($sql, [
            $this->id,
            $userid
        ]);
    }

    public function reverseSubject() {
        $subj = $this->getSubject();
        $type = $this->getType();

        # Remove any group tag at the start.
        if (preg_match('/^\[.*?\](.*)/', $subj, $matches)) {
            # Strip possible group name
            $subj = trim($matches[1]);
        }

        # Strip any attachments tag put in by Yahoo
        if (preg_match('/(.*)\[.*? Attachment.*\].*/', $subj, $matches)) {
            # Strip possible group name
            $subj = trim($matches[1]);
        }

        # Strip the relevant keywords.
        $keywords = Message::keywords()[$type];

        foreach ($keywords as $keyword) {
            if (preg_match('/ ' . preg_quote($keyword) . '\:(.*)/i', $subj, $matches)) {
                $subj = $matches[1];
            }
        }

        foreach ($keywords as $keyword) {
            if (preg_match('/.*' . preg_quote($keyword) . '.*\:(.*)/i', $subj, $matches)) {
                $subj = $matches[1];
            }
        }

        foreach ($keywords as $keyword) {
            if (preg_match('/^' . preg_quote($keyword) . '\b(.*)/i', $subj, $matches)) {
                $subj = $matches[1];
            }
        }

        # Now we have to add in the corresponding keyword.  The message should be on at least one group; if the
        # groups have different keywords then there's not much we can do.
        $groups = $this->getGroups();
        $key = strtoupper($type == Message::TYPE_OFFER ? Message::TYPE_TAKEN : Message::TYPE_RECEIVED);
        
        foreach ($groups as $groupid) {
            $g = Group::get($this->dbhr, $this->dbhm, $groupid);
            $defs = $g->getDefaults()['keywords'];
            $keywords = $g->getSetting('keywords', $defs);

            foreach ($keywords as $word => $val) {
                if (strtoupper($word) == $key) {
                    $key = $val;
                }
            }
            break;
        }

        $subj = substr($subj, 0, 1) == ':' ? $subj : ":$subj";
        $subj = $key . $subj;

        return($subj);
    }

    public function intendedOutcome($outcome) {
        $sql = "INSERT INTO messages_outcomes_intended (msgid, outcome) VALUES (?, ?) ON DUPLICATE KEY UPDATE outcome = ?;";
        $this->dbhm->preExec($sql, [
            $this->id,
            $outcome,
            $outcome
        ]);
    }

    public function mark($outcome, $comment, $happiness, $userid) {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $intcomment = $this->interestingComment($comment);

        $this->dbhm->preExec("DELETE FROM messages_outcomes_intended WHERE msgid = ?;", [ $this->id ]);
        $this->dbhm->preExec("INSERT INTO messages_outcomes (msgid, outcome, happiness, comments) VALUES (?,?,?,?);", [
            $this->id,
            $outcome,
            $happiness,
            $intcomment
        ]);

        if (($outcome == Message::OUTCOME_TAKEN || $outcome == Message::OUTCOME_RECEIVED) && $userid) {
            # Record that this item was taken/received by this user.  Assume they took any remaining (usually 1).
            $this->dbhm->preExec("INSERT INTO messages_by (msgid, userid, count) VALUES (?, ?, ?);", [
                $this->id,
                $userid,
                $this->availablenow
            ]);
        }

        # You might think that if we are passed a $userid then we could log a renege for any other users to whom
        # this was promised - but we can promise to multiple users, whereas we can only mark a single user in the
        # TAKEN (which is probably a bug).  And if we are withdrawing it, then we don't really know why - it could
        # be that we changed our minds, which isn't the fault of the person we promised it to.

        $groups = $this->getGroups();

        foreach ($groups as $groupid) {
            # We might be a mod marking on a member's behalf, so might need to set byuser.
            $this->log->log([
                                'type' => Log::TYPE_MESSAGE,
                                'subtype' => Log::SUBTYPE_OUTCOME,
                                'msgid' => $this->id,
                                'user' => $this->getFromuser(),
                                'byuser' => ($me && $me->getId()) != $this->getFromuser() ? $this->getFromuser() : NULL,
                                'groupid' => $groupid,
                                'text' => "$outcome $intcomment"
                            ]);
        }

        # Let anyone who was interested (replied referencing the message), and who didn't get it (not now in
        # messages_by), know that it is no longer available.
        $sql = "SELECT DISTINCT chatid FROM chat_messages 
INNER JOIN chat_rooms ON chat_rooms.id = chat_messages.chatid AND chat_rooms.chattype = ? 
LEFT JOIN messages_by ON messages_by.msgid = chat_messages.refmsgid AND messages_by.userid IN (chat_rooms.user1, chat_rooms.user2) 
WHERE refmsgid = ? AND chat_messages.type = ? AND reviewrejected = 0 AND messages_by.id IS NULL;";
        $replies = $this->dbhr->preQuery($sql, [ ChatRoom::TYPE_USER2USER, $this->id, ChatMessage::TYPE_INTERESTED ]);

        $cm = new ChatMessage($this->dbhr, $this->dbhm);

        foreach ($replies as $reply) {
            list ($mid, $banned) = $cm->create($reply['chatid'], $this->getFromuser(), NULL, ChatMessage::TYPE_COMPLETED, $this->id);

            # Make sure this message is highlighted in chat/email.
            $r = new ChatRoom($this->dbhr, $this->dbhm, $reply['chatid']);
            $r->upToDate($this->getFromuser());
        }
    }

    public function withdraw($comment, $happiness) {
        $intcomment = $this->interestingComment($comment);
        $this->dbhm->preExec("DELETE FROM messages_outcomes_intended WHERE msgid = ?;", [ $this-> id ]);

        $this->dbhm->preExec("INSERT INTO messages_outcomes (msgid, outcome, happiness, comments) VALUES (?,?,?,?);", [
            $this->id,
            Message::OUTCOME_WITHDRAWN,
            $happiness,
            $intcomment
        ]);

        $me = Session::whoAmI($this->dbhr, $this->dbhm);

        $this->log->log([
            'type' => Log::TYPE_MESSAGE,
            'subtype' => Log::SUBTYPE_OUTCOME,
            'msgid' => $this->id,
            'user' => $this->getFromuser(),
            'byuser' => $me ? $me->getId() : NULL,
            'text' => $intcomment ? "Withdrawn: $comment" : "Withdrawn"
        ]);
    }

    public function backToDraft() {
        # Convert a message back to a draft.
        $rollback = FALSE;
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $myid = $me ? $me->getId() : NULL;

        if ($this->dbhm->beginTransaction()) {
            $rollback = TRUE;

            if ($this->id) {
                # This might already be a draft, so ignore dups.
                $rc = $this->dbhm->preExec("INSERT IGNORE INTO messages_drafts (msgid, userid, session) VALUES (?, ?, ?);", [ $this->id, $myid, session_id() ]);

                if ($rc) {
                    $rc = $this->dbhm->preExec("DELETE FROM messages_groups WHERE msgid = ?;", [ $this->id ]);

                    if ($rc) {
                        $rc = $this->dbhm->commit();

                        if ($rc) {
                            $rollback = FALSE;
                        }
                    }
                }
            }
        }

        if ($rollback) {
            $this->dbhm->rollBack();
        }

        return(!$rollback);
    }

    public function autoRepostGroup($type, $mindate, $groupid = NULL, $msgid = NULL) {
        $count = 0;
        $warncount = 0;
        $groupq = $groupid ? " AND id = $groupid " : "";
        $msgq = $msgid ? " AND messages_groups.msgid = $msgid " : "";

        # Randomise the order to give all groups a chance if the script gets killed or something.
        $groups = $this->dbhr->preQuery("SELECT id FROM groups WHERE type = ? AND onhere = 1 $groupq ORDER BY RAND();", [ $type ]);

        foreach ($groups as $group) {
            $g = Group::get($this->dbhr, $this->dbhm, $group['id']);

            if (!$g->getSetting('closed', FALSE) && !$g->getPrivate('autofunctionoverride')) {
                $reposts = $g->getSetting('reposts', [ 'offer' => 3, 'wanted' => 7, 'max' => 5, 'chaseups' => 5]);

                # We want approved messages which haven't got an outcome, aren't promised, don't have any replies and
                # which we originally sent.
                #
                # The replies part is because we can't really rely on members to let us know what happens to a message,
                # especially if they are not receiving emails reliably.  At least this way it avoids the case where a
                # message gets resent repeatedly and people keep replying and not getting a response.
                #
                # The sending user must also still be a member of the group.
                $sql = "SELECT messages_groups.msgid, messages_groups.groupid, TIMESTAMPDIFF(HOUR, messages_groups.arrival, NOW()) AS hoursago, autoreposts, lastautopostwarning, messages.type, messages.fromaddr 
FROM messages_groups INNER JOIN messages ON messages.id = messages_groups.msgid 
INNER JOIN memberships ON memberships.userid = messages.fromuser AND memberships.groupid = messages_groups.groupid 
LEFT OUTER JOIN messages_outcomes ON messages.id = messages_outcomes.msgid 
LEFT OUTER JOIN messages_promises ON messages_promises.msgid = messages.id 
LEFT OUTER JOIN chat_messages ON messages.id = chat_messages.refmsgid AND chat_messages.type != 'ModMail' 
WHERE messages_groups.arrival > ? AND messages_groups.groupid = ? AND messages_groups.collection = 'Approved' AND messages_outcomes.msgid IS NULL AND messages_promises.msgid IS NULL AND messages.type IN ('Offer', 'Wanted') AND sourceheader IN ('Platform', 'FDv2') AND messages.deleted IS NULL AND chat_messages.refmsgid IS NULL $msgq;";
                #error_log("$sql, $mindate, {$group['id']}");
                $messages = $this->dbhr->preQuery($sql, [
                    $mindate,
                    $group['id']
                ]);

                $now = time();

                $loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig');
                $twig = new \Twig_Environment($loader);

                foreach ($messages as $message) {
                    if (Mail::ourDomain($message['fromaddr'])) {
                        if ($message['autoreposts'] < $reposts['max']) {
                            # We want to send a warning 24 hours before we repost.
                            $lastwarnago = $message['lastautopostwarning'] ? ($now - strtotime($message['lastautopostwarning'])) : NULL;
                            $interval = $message['type'] == Message::TYPE_OFFER ? $reposts['offer'] : $reposts['wanted'];

                            # If we have messages which are older than we could have been trying for, ignore them.
                            $maxage = $interval * ($reposts['max'] + 1);

                            error_log("Consider repost {$message['msgid']}, posted {$message['hoursago']} interval $interval lastwarning $lastwarnago maxage $maxage");

                            if ($message['hoursago'] < $maxage * 24) {
                                # Reposts might be turned off.
                                if ($interval > 0 && $reposts['max'] > 0) {
                                    if ($message['hoursago'] <= $interval * 24 &&
                                        $message['hoursago'] > ($interval - 1) * 24 &&
                                        ($lastwarnago === NULL || $lastwarnago > 24)
                                    ) {
                                        # We will be reposting within 24 hours, and we've either not sent a warning, or the last one was
                                        # an old one (probably from the previous repost).
                                        if (!$message['lastautopostwarning'] || ($lastwarnago > 24 * 60 * 60)) {
                                            # And we haven't sent a warning yet.
                                            $this->dbhm->preExec("UPDATE messages_groups SET lastautopostwarning = NOW() WHERE msgid = ?;", [$message['msgid']]);
                                            $warncount++;

                                            $m = new Message($this->dbhr, $this->dbhm, $message['msgid']);
                                            $u = new User($this->dbhr, $this->dbhm, $m->getFromuser());
                                            $g = new Group($this->dbhr, $this->dbhm, $message['groupid']);
                                            $gatts = $g->getPublic();

                                            if ($u->getId()) {
                                                $to = $u->getEmailPreferred();
                                                $subj = $m->getSubject();

                                                # Remove any group tag.
                                                $subj = trim(preg_replace('/^\[.*?\](.*)/', "$1", $subj));

                                                $completed = $u->loginLink(USER_SITE, $u->getId(), "/mypost/{$message['msgid']}/completed", User::SRC_REPOST_WARNING);
                                                $withdraw = $u->loginLink(USER_SITE, $u->getId(), "/mypost/{$message['msgid']}/withdraw", User::SRC_REPOST_WARNING);
                                                $promise = $u->loginLink(USER_SITE, $u->getId(), "/mypost/{$message['msgid']}/promise", User::SRC_REPOST_WARNING);
                                                $othertype = $m->getType() == Message::TYPE_OFFER ? Message::OUTCOME_TAKEN : Message::OUTCOME_RECEIVED;
                                                $text = "We will automatically repost your message $subj soon, so that more people will see it.  If you don't want us to do that, please go to $completed to mark as $othertype or $withdraw to withdraw it.";
                                                $html = $twig->render('autorepost.html', [
                                                    'subject' => $subj,
                                                    'name' => $u->getName(),
                                                    'email' => $to,
                                                    'type' => $othertype,
                                                    'completed' => $completed,
                                                    'withdraw' => $withdraw,
                                                    'promise' => $promise
                                                ]);

                                                list ($transport, $mailer) = Mail::getMailer();

                                                if (\Swift_Validate::email($to)) {
                                                    $message = \Swift_Message::newInstance()
                                                        ->setSubject("Re: " . $subj)
                                                        ->setFrom([$g->getAutoEmail() => $gatts['namedisplay']])
                                                        ->setReplyTo([$g->getModsEmail() => $gatts['namedisplay']])
                                                        ->setTo($to)
                                                        ->setBody($text);

                                                    # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                                                    # Outlook.
                                                    $htmlPart = \Swift_MimePart::newInstance();
                                                    $htmlPart->setCharset('utf-8');
                                                    $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                                                    $htmlPart->setContentType('text/html');
                                                    $htmlPart->setBody($html);
                                                    $message->attach($htmlPart);

                                                    $mailer->send($message);
                                                }
                                            }
                                        }
                                    } else if ($message['hoursago'] > $interval * 24) {
                                        # We can autorepost this one.
                                        $m = new Message($this->dbhr, $this->dbhm, $message['msgid']);
                                        error_log($g->getPrivate('nameshort') . " #{$message['msgid']} " . $m->getFromaddr() . " " . $m->getSubject() . " repost due");
                                        $m->autoRepost($message['autoreposts'] + 1, $reposts['max']);

                                        $count++;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        return([$count, $warncount]);
    }

    public function chaseUp($type, $mindate, $groupid = NULL) {
        $count = 0;
        $groupq = $groupid ? " AND id = $groupid " : "";

        # Randomise the order in case the script gets killed or something - gives all groups a chance.
        $groups = $this->dbhr->preQuery("SELECT id FROM groups WHERE type = ? $groupq ORDER BY RAND();", [ $type ]);

        $loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig');
        $twig = new \Twig_Environment($loader);

        foreach ($groups as $group) {
            $g = Group::get($this->dbhr, $this->dbhm, $group['id']);

            # Don't chase up on closed groups.
            if (!$g->getSetting('closed', FALSE)) {
                $reposts = $g->getSetting('reposts', ['offer' => 3, 'wanted' => 7, 'max' => 5, 'chaseups' => 5]);

                # We want approved messages which haven't got an outcome, i.e. aren't TAKEN/RECEIVED, which don't have
                # some other outcome (e.g. withdrawn), aren't promised, have any replies and which we originally sent.
                #
                # The sending user must also still be a member of the group.
                #
                # Using UNION means we can be more efficiently indexed.
                $sql = "SELECT messages_groups.msgid, messages_groups.groupid, TIMESTAMPDIFF(HOUR, messages_groups.arrival, NOW()) AS hoursago, lastchaseup, messages.type, messages.fromaddr FROM messages_groups INNER JOIN messages ON messages.id = messages_groups.msgid INNER JOIN memberships ON memberships.userid = messages.fromuser AND memberships.groupid = messages_groups.groupid LEFT OUTER JOIN messages_related ON id1 = messages.id LEFT OUTER JOIN messages_outcomes ON messages.id = messages_outcomes.msgid LEFT OUTER JOIN messages_promises ON messages_promises.msgid = messages.id INNER JOIN chat_messages ON messages.id = chat_messages.refmsgid WHERE messages_groups.arrival > ? AND messages_groups.groupid = ? AND messages_groups.collection = 'Approved' AND messages_related.id1 IS NULL AND messages_outcomes.msgid IS NULL AND messages_promises.msgid IS NULL AND messages.type IN ('Offer', 'Wanted') AND sourceheader IN ('Platform', 'FDv2') AND messages.deleted IS NULL
                        UNION SELECT messages_groups.msgid, messages_groups.groupid, TIMESTAMPDIFF(HOUR, messages_groups.arrival, NOW()) AS hoursago, lastchaseup, messages.type, messages.fromaddr FROM messages_groups INNER JOIN messages ON messages.id = messages_groups.msgid INNER JOIN memberships ON memberships.userid = messages.fromuser AND memberships.groupid = messages_groups.groupid LEFT OUTER JOIN messages_related ON id2 = messages.id LEFT OUTER JOIN messages_outcomes ON messages.id = messages_outcomes.msgid LEFT OUTER JOIN messages_promises ON messages_promises.msgid = messages.id INNER JOIN chat_messages ON messages.id = chat_messages.refmsgid WHERE messages_groups.arrival > ? AND messages_groups.groupid = ? AND messages_groups.collection = 'Approved' AND messages_related.id1 IS NULL AND messages_outcomes.msgid IS NULL AND messages_promises.msgid IS NULL AND messages.type IN ('Offer', 'Wanted') AND sourceheader IN ('Platform', 'FDv2') AND messages.deleted IS NULL;";
                #error_log("$sql, $mindate, {$group['id']}");
                $messages = $this->dbhr->preQuery(
                    $sql,
                    [
                        $mindate,
                        $group['id'],
                        $mindate,
                        $group['id']
                    ]
                );

                $now = time();

                foreach ($messages as $message) {
                    if (Mail::ourDomain($message['fromaddr'])) {
                        # Find the last reply.
                        $m = new Message($this->dbhr, $this->dbhm, $message['msgid']);

                        if ($m->canChaseup()) {
                            $matts = $m->getPublic(FALSE, FALSE, TRUE);

                            $sql = "SELECT MAX(date) AS latest FROM chat_messages WHERE chatid IN (SELECT chatid FROM chat_messages WHERE refmsgid = ?);";
                            $replies = $this->dbhr->preQuery($sql, [$message['msgid']]);
                            $lastreply = $replies[0]['latest'];
                            $age = ($now - strtotime($lastreply)) / (60 * 60);
                            $interval = array_key_exists('chaseups', $reposts) ? $reposts['chaseups'] : 2;
                            error_log("#{$message['msgid']} Consider chaseup $age vs $interval");

                            if ($interval > 0 && $age > $interval * 24) {
                                # We can chase up.
                                $u = new User($this->dbhr, $this->dbhm, $m->getFromuser());
                                $g = new Group($this->dbhr, $this->dbhm, $message['groupid']);
                                $gatts = $g->getPublic();

                                if ($u->getId() && $m->canRepost()) {
                                    error_log(
                                        $g->getPrivate('nameshort') . " #{$message['msgid']} " . $m->getFromaddr(
                                        ) . " " . $m->getSubject() . " chaseup due"
                                    );
                                    $count++;

                                    $to = $u->getEmailPreferred();
                                    $subj = $m->getSubject();

                                    # Remove any group tag.
                                    $subj = trim(preg_replace('/^\[.*?\](.*)/', "$1", $subj));

                                    $completed = $u->loginLink(
                                        USER_SITE,
                                        $u->getId(),
                                        "/mypost/{$message['msgid']}/completed",
                                        User::SRC_CHASEUP
                                    );
                                    $withdraw = $u->loginLink(
                                        USER_SITE,
                                        $u->getId(),
                                        "/mypost/{$message['msgid']}/withdraw",
                                        User::SRC_CHASEUP
                                    );
                                    $repost = $u->loginLink(
                                        USER_SITE,
                                        $u->getId(),
                                        "/mypost/{$message['msgid']}/repost",
                                        User::SRC_CHASEUP
                                    );

                                    $lovejunk = NULL;

                                    if ($m->getType() == Message::TYPE_OFFER && Utils::pres('lovejunkhash', $matts)) {
                                        $lovejunk = $u->loginLink(
                                            USER_SITE,
                                            $u->getId(),
                                            "/mypost/{$message['msgid']}/lovejunk",
                                            User::SRC_CHASEUP
                                        );
                                    }

                                    $othertype = $m->getType(
                                    ) == Message::TYPE_OFFER ? Message::OUTCOME_TAKEN : Message::OUTCOME_RECEIVED;
                                    $text = "Can you let us know what happened with this?  Click $repost to post it again, or $completed to mark as $othertype, or $withdraw to withdraw it.  Thanks.";

                                    $this->dbhm->preExec(
                                        "UPDATE messages_groups SET lastchaseup = NOW() WHERE msgid = ?;",
                                        [$message['msgid']]
                                    );

                                    $html = $twig->render(
                                        'chaseup.html',
                                        [
                                            'subject' => $subj,
                                            'name' => $u->getName(),
                                            'email' => $to,
                                            'type' => $othertype,
                                            'repost' => $repost,
                                            'completed' => $completed,
                                            'withdraw' => $withdraw,
                                            'lovejunk' => $lovejunk
                                        ]
                                    );

                                    list ($transport, $mailer) = Mail::getMailer();

                                    if (\Swift_Validate::email($to)) {
                                        $message = \Swift_Message::newInstance()
                                            ->setSubject("Re: " . $subj)
                                            ->setFrom([$g->getAutoEmail() => $gatts['namedisplay']])
                                            ->setReplyTo([$g->getModsEmail() => $gatts['namedisplay']])
                                            ->setTo($to)
                                            ->setBody($text);

                                        # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                                        # Outlook.
                                        $htmlPart = \Swift_MimePart::newInstance();
                                        $htmlPart->setCharset('utf-8');
                                        $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                                        $htmlPart->setContentType('text/html');
                                        $htmlPart->setBody($html);
                                        $message->attach($htmlPart);

                                        $mailer->send($message);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        return($count);
    }

    public function notifyLanguishing($mid) {
        # Notify users about posts which seem to be stuck doing nothing.
        #
        # First find recent posts which are not promised, deleted, completed.
        $count = 0;
        $start = date('Y-m-d', strtotime("midnight 31 days ago"));
        $end = date('Y-m-d', strtotime("48 hours ago"));
        $mq = $mid ? " AND messages.id = $mid " : "";
        $msgs = $this->dbhr->preQuery("SELECT messages_groups.msgid, messages_groups.msgtype, messages_groups.autoreposts, messages_groups.groupid, messages_groups.collection, messages.fromuser, messages.fromaddr FROM messages_groups 
LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages_groups.msgid 
LEFT JOIN messages_promises ON messages_promises.msgid = messages_groups.msgid
INNER JOIN messages ON messages.id = messages_groups.msgid
WHERE messages_groups.arrival BETWEEN ? AND ?
AND messages_outcomes.id IS NULL
AND messages_promises.id IS NULL
AND messages.deleted IS NULL
AND messages.heldby IS NULL
$mq", [
    $start,
    $end
        ]);

        $languishing = [];

        foreach ($msgs as $msg) {
            # Indexing works better if we handle this with an if test rather than in the query.
            if ($msg['collection'] == MessageCollection::APPROVED &&
                ($msg['msgtype'] == Message::TYPE_OFFER || $msg['msgtype'] == Message::TYPE_WANTED) &&
                Mail::ourDomain($msg['fromaddr'])
            ) {
                # We have a post.  Check when the last activity on any chat referencing it was.
                #error_log("Consider {$msg['msgid']}");
                $chats = $this->dbhr->preQuery("SELECT MAX(date) AS max FROM chat_messages WHERE chatid IN (SELECT chatid FROM chat_messages WHERE refmsgid = ?) AND date >= ?;", [
                    $msg['msgid'],
                    $end
                ]);

                if (!$chats[0]['max']) {
                    #error_log("...no recent chats");

                    # No recent chatting about this message.
                    #
                    # If the group doesn't have autoreposting on, or we've finished doing it, then
                    # this message is languishing.
                    $g = Group::get($this->dbhr, $this->dbhm, $msg['groupid']);
                    $reposts = $g->getSetting('reposts', ['offer' => 3, 'wanted' => 7, 'max' => 5, 'chaseups' => 5]);
                    #error_log("...reposts {$msg['autoreposts']} vs {$reposts['max']}");
                    if (!$reposts['max'] || $msg['autoreposts'] > $reposts['max']) {
                        #error_log("{$msg['msgid']} from {$msg['fromuser']} is languishing");
                        $count++;
                        if (!array_key_exists($msg['fromuser'], $languishing)) {
                            $languishing[$msg['fromuser']] = 1;
                        } else {
                            $languishing[$msg['fromuser']];
                        }
                    }
                }
            }
        }

        #error_log("Found " . count($languishing) . " users with $count languishing posts");

        foreach ($languishing as $user => $count) {
            # Only want one outstanding notification of this type.
            $n = new Notifications($this->dbhr, $this->dbhm);
            $n->deleteUserType($user, Notifications::TYPE_OPEN_POSTS);
            $n->add(NULL, $user, Notifications::TYPE_OPEN_POSTS, NULL, NULL, NULL, NULL, $count);
        }

        return $count;
    }

    public function dullComment($comment) {
        $dull = TRUE;

        $comment = $comment ? trim($comment) : '';

        if (strlen($comment)) {
            $dull = FALSE;

            foreach ([
                         'Sorry, this is no longer available.',
                         'Thanks, this has now been taken.',
                         "Thanks, I'm no longer looking for this.",
                         'Sorry, this has now been taken.',
                         'Thanks for the interest, but this has now been taken.',
                         'Thanks, these have now been taken.',
                         'Thanks, this has now been received.',
                         'Sorry, this is no longer available'
                     ] as $bland) {
                if (strcmp($comment, $bland) === 0) {
                    $dull = TRUE;
                }
            }
        }

        return $dull;
    }

    public function interestingComment($comment) {
        return !$this->dullComment($comment) ? $comment : NULL;
    }

    public function tidyOutcomes($since) {
        $count = 0;
        $outcomes = $this->dbhr->preQuery("SELECT * FROM messages_outcomes WHERE timestamp >= '$since' AND comments IS NOT NULL;");
        $total = count($outcomes);
        $processed = 0;

        foreach ($outcomes as $outcome) {
            if ($this->dullComment($outcome['comments'])) {
                $this->dbhm->preExec("UPDATE messages_outcomes SET comments = NULL, timestamp = ? WHERE id = ?;", [
                    $outcome['timestamp'],
                    $outcome['id']
                ]);
                $count++;
            }

            $processed++;

            if ($processed % 1000 === 0) {
                error_log("...$processed / $total");
            }
        }

        return $count;
    }

    public function processIntendedOutcomes($msgid = NULL) {
        $count = 0;

        # If someone responded to a chaseup mail, but didn't complete the process in half an hour, we do it for them.
        #
        # This is quite common, and helps get more activity even from members who are put to shame by goldfish.
        $msgq = $msgid ? " AND msgid = $msgid " : "";
        $intendeds = $this->dbhr->preQuery("SELECT * FROM messages_outcomes_intended WHERE TIMESTAMPDIFF(MINUTE, timestamp, NOW()) > 30 AND TIMESTAMPDIFF(DAY, timestamp, NOW()) <= 7 $msgq;");
        foreach ($intendeds as $intended) {
            $m = new Message($this->dbhr, $this->dbhm, $intended['msgid']);

            if (!$m->hasOutcome()) {
                switch ($intended['outcome']) {
                    case 'Taken':
                        $m->mark(Message::OUTCOME_TAKEN, NULL, NULL, NULL);
                        $count++;
                        break;
                    case 'Received':
                        $m->mark(Message::OUTCOME_RECEIVED, NULL, NULL, NULL);
                        $count++;
                        break;
                    case 'Withdrawn':
                        $m->withdraw(NULL, NULL);
                        $count++;
                        break;
                    case 'Repost':
                        # We might get an intended outcome of repost multiple times if they click on the reminder
                        # multiple times with more than 30 minutes in between.  So we shouldn't repost if the
                        # message is not currently eligible to be reposted.
                        if ($m->canRepost()) {
                            $m->repost();
                            $count++;
                        }
                        break;
                }
            }
        }

        return($count);
    }

    public function canRepost() {
        $ret = FALSE;
        $groups = $this->dbhr->preQuery("SELECT groupid, TIMESTAMPDIFF(HOUR, arrival, NOW()) AS hoursago FROM messages_groups WHERE msgid = ?;", [ $this->id ]);

        foreach ($groups as $group) {
            $g = Group::get($this->dbhr, $this->dbhm, $group['groupid']);
            $reposts = $g->getSetting('reposts', ['offer' => 3, 'wanted' => 7, 'max' => 5, 'chaseups' => 5]);
            $interval = $this->getType() == Message::TYPE_OFFER ? $reposts['offer'] : $reposts['wanted'];

            if ($group['hoursago'] > $interval * 24) {
                $ret = TRUE;
            }
        }

        return($ret);
    }

    public function canChaseup() {
        $ret = FALSE;
        $groups = $this->dbhr->preQuery("SELECT groupid, lastchaseup FROM messages_groups WHERE msgid = ?;", [ $this->id ]);

        foreach ($groups as $group) {
            $g = Group::get($this->dbhr, $this->dbhm, $group['groupid']);
            $reposts = $g->getSetting('reposts', ['offer' => 3, 'wanted' => 7, 'max' => 5, 'chaseups' => 5]);
            $interval = $this->getType() == Message::TYPE_OFFER ? $reposts['offer'] : $reposts['wanted'];
            $interval = max($interval, (array_key_exists('chaseups', $reposts) ? $reposts['chaseups'] : 2) * 24);

            $ret = TRUE;

            if ($group['lastchaseup']) {
                $age = (time() - strtotime($group['lastchaseup'])) / (60 * 60);
                $ret = $age > $interval * 24;
            }
        }

        return($ret);
    }

    public function repost() {
        # Make sure we don't keep doing this.
        $this->dbhm->preExec("DELETE FROM messages_outcomes_intended WHERE msgid = ?;", [ $this-> id ]);

        $u = new User($this->dbhr, $this->dbhm, $this->getFromuser());
        $groups = $this->getGroups(FALSE, FALSE);

        $ret = NULL;

        foreach ($groups as $group) {
            # Consider the posting status on this group.  The group might have a setting for moderation; failing
            # that we use the posting status on the group.
            $g = Group::get($this->dbhr, $this->dbhm, $group['groupid']);
            $postcoll = ($g->getSetting('moderated', 0) || $g->getSetting(
                    'closed',
                    0
                )) ? MessageCollection::PENDING : $u->postToCollection($group['groupid']);

            if ($group['collection'] === MessageCollection::APPROVED &&
                $postcoll === MessageCollection::PENDING) {
                # This message is approved, but the member is moderated.  That means the message must previously
                # have been approved.  So this repost also needs approval.  Move it to Pending.
                $this->dbhm->preExec("UPDATE messages_groups SET arrival = NOW(), collection = ? WHERE msgid = ?;", [ MessageCollection::PENDING, $this->id ]);
                $this->s->delete($this->id);
                $ret = MessageCollection::PENDING;
            } else {
                # All we need to do to repost is update the arrival time - that will cause the message to appear on the site
                # near the top, and get mailed out again.
                $this->dbhm->preExec("UPDATE messages_groups SET arrival = NOW() WHERE msgid = ?;", [ $this->id ]);
                # ...and update the search index.
                $this->s->bump($this->id, time());
                $ret = MessageCollection::APPROVED;
            }
        }

        # Record that we've done this.
        $groups = $this->getGroups();
        foreach ($groups as $groupid) {
            $sql = "INSERT INTO messages_postings (msgid, groupid, repost, autorepost) VALUES(?,?,?,?);";
            $this->dbhm->preExec($sql, [
                $this->id,
                $groupid,
                1,
                0
            ]);
        }

        return $ret;
    }

    public function autoRepost($reposts, $max) {
        # All we need to do to repost is update the arrival time - that will cause the message to appear on the site
        # near the top, and get mailed out again.
        #
        # Don't resend to Yahoo - the complexities of trying to keep the single message we have in sync
        # with multiple copies on Yahoo are just too horrible to be worth trying to do.
        $this->dbhm->preExec("UPDATE messages_groups SET arrival = NOW(), autoreposts = autoreposts + 1 WHERE msgid = ?;", [ $this->id ]);

        # ...and update the search index.
        $this->s->bump($this->id, time());

        # Record that we've done this.
        $groups = $this->getGroups();
        foreach ($groups as $groupid) {
            $this->log->log([
                'type' => Log::TYPE_MESSAGE,
                'subtype' => Log::SUBTYPE_AUTO_REPOSTED,
                'msgid' => $this->id,
                'groupid' => $groupid,
                'user' => $this->getFromuser(),
                'text' => "$reposts / $max"
            ]);

            $sql = "INSERT INTO messages_postings (msgid, groupid, repost, autorepost) VALUES(?,?,?,?);";
            $this->dbhm->preExec($sql, [
                $this->id,
                $groupid,
                1,
                1
            ]);
        }
    }

    public function isBounce()
    {
        $bounce = FALSE;

        foreach ($this->bounce_subjects as $subj) {
            if (stripos($this->subject, $subj) !== FALSE) {
                $bounce = TRUE;
            }
        }

        if (!$bounce) {
            foreach ($this->bounce_bodies as $body) {
                if (stripos($this->message, $body) !== FALSE) {
                    $bounce = TRUE;
                }
            }
        }

        return ($bounce);
    }
    
    public function isAutoreply()
    {
        $autoreply = FALSE;

        foreach ($this->autoreply_subjects as $subj) {
            if (stripos($this->subject, $subj) !== FALSE) {
                $autoreply = TRUE;
            }
        }

        if (!$autoreply) {
            foreach ($this->autoreply_bodies as $body) {
                if (stripos($this->message, $body) !== FALSE) {
                    $autoreply = TRUE;
                }
            }
        }

        if (!$autoreply) {
            foreach ($this->autoreply_text_start as $body) {
                if (stripos($this->textbody, $body) === 0) {
                    $autoreply = TRUE;
                }
            }
        }

        if (!$autoreply) {
            $auto = $this->getHeader('auto-submitted');
            if ($auto && stripos($auto, 'auto-') !== FALSE) {
                $autoreply = TRUE;
            }
        }

        if (!$autoreply && stripos($this->getFromaddr(), 'notify@yahoogroups.com') !== FALSE) {
            # Some Yahoo system message we don't want to see.
            $autoreply = TRUE;
        }

        return ($autoreply);
    }

    public function hasOutcome() {
        $sql = "SELECT * FROM messages_outcomes WHERE msgid = ? ORDER BY id DESC;";
        $outcomes = $this->dbhr->preQuery($sql, [ $this->id ]);
        return(count($outcomes) > 0 ? $outcomes[0]['outcome'] : NULL);
    }

    public function promisedButNotTo($userid) {
        $sql = "SELECT * FROM messages_promises WHERE msgid = ?;";
        $ret = FALSE;

        $promises = $this->dbhr->preQuery($sql, [ $this->id ]);

        if (count($promises)) {
            $ret = TRUE;

            foreach ($promises as $promise) {
                if ($promise['userid'] == $userid) {
                    $ret = FALSE;
                }
            }
        }

        return $ret;
    }

    public function isEdited() {
        return($this->editedby !== NULL);
    }

    public function quickDelete($schema, $id) {
        # This bypasses referential integrity checks, but honours them by querying the schema.  It's intended for
        # when we are deleting large numbers of messages and want to avoid blocking the server because of
        # cascaded deletes.  This is particularly true on a Percona cluster where a stream of DELETE ops tends
        # to cripple things.
        $this->dbhm->preExec("SET FOREIGN_KEY_CHECKS=0;", NULL, FALSE);

        foreach ($schema as $table) {
            $todel = $this->dbhm->preQuery("SELECT {$table['COLUMN_NAME']} FROM {$table['TABLE_NAME']} WHERE {$table['COLUMN_NAME']} = $id", NULL, FALSE, FALSE);
            #error_log("$id ..." . count($todel) . " from {$table['TABLE_NAME']}");
            if (count($todel) > 0) {
                $this->dbhm->preExec("DELETE FROM {$table['TABLE_NAME']} WHERE {$table['COLUMN_NAME']} = $id", NULL, FALSE);
            }
        }
        
        $this->dbhm->preExec("DELETE FROM messages WHERE id = $id;", NULL, FALSE);
        $this->dbhm->preExec("SET FOREIGN_KEY_CHECKS=1;", NULL, FALSE);
    }

    public function like($userid, $type) {
        $this->dbhm->preExec("INSERT INTO messages_likes (msgid, userid, type) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE timestamp = NOW(), count = count + 1 ;", [
            $this->id,
            $userid,
            $type
        ]);
    }

    public function unlike($userid, $type) {
        $this->dbhm->preExec("DELETE FROM messages_likes WHERE msgid = ? AND userid = ? AND type = ?;", [
            $this->id,
            $userid,
            $type
        ]);
    }

    public function getLikes($type) {
        return $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM messages_likes WHERE msgid = ? AND type = ?;", [
            $this->id,
            $type
        ])[0]['count'];
    }

    public function move($groupid) {
        $ret = [ 'ret' => 2, 'status' => 'Permission denied' ];

        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $groups = $this->getGroups(FALSE, TRUE);

        if ($me->isModOrOwner($groups[0]) && $me->isModOrOwner($groupid)) {
            # Always move to Pending, so the new group owner can review.
            $ret = [ 'ret' => 3, 'status' => 'Failed' ];
            $this->dbhm->beginTransaction();

            $this->dbhm->preExec("DELETE FROM messages_groups WHERE msgid = ?;", [
                $this->id
            ]);

            if ($this->dbhm->rowsAffected() == 1) {
                $this->dbhm->preExec("INSERT INTO messages_groups (msgid, groupid, collection, arrival, msgtype) VALUES (?, ?, ?, NOW(), ?);", [
                    $this->id,
                    $groupid,
                    MessageCollection::PENDING,
                    $this->getType()
                ]);

                if ($this->dbhm->rowsAffected() == 1) {
                    $rc = $this->dbhm->commit();

                    $ret = $rc ? ['ret' => 0, 'status' => 'Success'] : FALSE;
                }
            }
        }

        $ret = $ret ? $ret : ($this->dbhm->rollBack() && FALSE);
        return $ret;
    }

    public function autoapprove($id = NULL) {
        # Look for messages which have been pending for too long.  This fallback catches cases where the group is not being
        # regularly moderated.
        #
        # Even if the group is closed we still autoapprove - they won't get mailed out and can't be replied to on the
        # site but this stops a flood when we reopen.
        $ret = 0;
        $idq = $id ? " AND msgid = $id " : "";
        $sql = "SELECT msgid, groupid, TIMESTAMPDIFF(HOUR, messages_groups.arrival, NOW()) AS ago FROM messages_groups INNER JOIN messages ON messages.id = messages_groups.msgid WHERE collection = ? AND heldby IS NULL HAVING ago > 48 $idq;";
        $messages = $this->dbhr->preQuery($sql, [
            MessageCollection::PENDING
        ]);

        foreach ($messages as $message) {
            $m = new Message($this->dbhr, $this->dbhm, $message['msgid']);
            $uid = $m->getFromuser();
            $u = new User($this->dbhr, $this->dbhm, $uid);

            $gids = $m->getGroups();

            foreach ($gids as $gid) {
                $g = new Group($this->dbhr, $this->dbhm, $gid);

                error_log("Group $gid " . $g->getName() . " " . $g->getSetting('closed', FALSE) . "," . $g->getPrivate('autofunctionoverride'));

                if (!$g->getSetting('closed', FALSE) && !$g->getPrivate('autofunctionoverride')) {
                    error_log("will do it");
                    $joined = $u->getMembershipAtt($gid, 'added');
                    $hoursago = round((time() - strtotime($joined)) / 3600);

                    error_log("{$message['msgid']} has been pending for {$message['ago']}, membership $hoursago");

                    if ($hoursago > 48) {
                        error_log("...approve");
                        $m->approve($message['groupid']);

                        $this->log->log([
                                            'type' => Log::TYPE_MESSAGE,
                                            'subtype' => Log::SUBTYPE_AUTO_APPROVED,
                                            'groupid' => $message['groupid'],
                                            'msgid' => $message['msgid'],
                                            'user' => $m->getFromuser()
                                        ]);

                        $ret++;
                    }
                }
            }
        }

        return $ret;
    }

    public function updateSpatialIndex() {
        $mysqltime = date("Y-m-d", strtotime(MessageCollection::RECENTPOSTS));

        # Add/update messages which are recent or have changed location or group or been reposted.
        error_log("Add recent");
        $sql = "SELECT DISTINCT messages.id, messages.lat, messages.lng, messages_groups.groupid, messages_groups.arrival, messages_groups.msgtype FROM messages 
    INNER JOIN messages_groups ON messages_groups.msgid = messages.id
    LEFT JOIN messages_spatial ON messages_spatial.msgid = messages_groups.msgid
    WHERE messages_groups.arrival >= ? AND messages.lat IS NOT NULL AND messages.lng IS NOT NULL AND messages.deleted IS NULL AND messages_groups.collection = ? AND 
          (messages_spatial.msgid IS NULL OR X(point) != messages.lng OR Y(point) != messages.lat OR messages_spatial.groupid IS NULL OR messages_spatial.groupid != messages_groups.groupid OR messages_groups.arrival != messages_spatial.arrival);";
        $msgs = $this->dbhr->preQuery($sql, [
            $mysqltime,
            MessageCollection::APPROVED
        ]);

        foreach ($msgs as $msg) {
            $sql = "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival) VALUES (?, GeomFromText('POINT({$msg['lng']} {$msg['lat']})'), ?, ?, ?) ON DUPLICATE KEY UPDATE point = GeomFromText('POINT({$msg['lng']} {$msg['lat']})'), groupid = ?, msgtype = ?, arrival = ?;";
            $this->dbhm->preExec($sql, [
                $msg['id'],
                $msg['groupid'],
                $msg['msgtype'],
                $msg['arrival'],
                $msg['groupid'],
                $msg['msgtype'],
                $msg['arrival']
            ]);
        }

        # Update any message outcomes.
        error_log("Update outcomes");
        $sql = "SELECT messages_spatial.id, messages_spatial.msgid, messages_spatial.successful, messages_outcomes.outcome FROM messages_spatial LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages_spatial.msgid ORDER BY messages_outcomes.timestamp DESC;";
        $msgs = $this->dbhr->preQuery($sql);

        foreach ($msgs as $msg) {
            if ($msg['outcome'] == Message::OUTCOME_WITHDRAWN || $msg['outcome'] == Message::OUTCOME_EXPIRED) {
                # Remove from the index.
                error_log("{$msg['msgid']} expired or withdrawn, remove from index");
                $this->dbhm->preExec("DELETE FROM messages_spatial WHERE id = ?;", [
                    $msg['id']
                ]);
            } else if ($msg['outcome'] == Message::OUTCOME_TAKEN || $msg['outcome'] == Message::OUTCOME_RECEIVED) {
                if (!$msg['successful']) {
                    error_log("{$msg['msgid']} taken or received, update");
                    $this->dbhm->preExec("UPDATE messages_spatial SET successful = 1 WHERE id = ?;", [
                        $msg['id']
                    ]);
                }
            } else if ($msg['successful']) {
                error_log("{$msg['msgid']} no longer taken or received, update");
                $this->dbhm->preExec("UPDATE messages_spatial SET successful = 0 WHERE id = ?;", [
                    $msg['id']
                ]);
            }
        }

        # Remove any messages which are deleted.
        error_log("Remove deleted");
        $sql = "SELECT DISTINCT messages.id FROM messages_spatial INNER JOIN messages ON messages_spatial.msgid = messages.id AND messages.deleted IS NOT NULL";
        $msgs = $this->dbhr->preQuery($sql);

        foreach ($msgs as $msg) {
            $this->dbhm->preExec("DELETE FROM messages_spatial WHERE id = ?;", [
                $msg['id']
            ]);
        }

        # Remove any messages which are now old.
        error_log("Remove old");
        $sql = "SELECT DISTINCT messages_spatial.id FROM messages_spatial INNER JOIN messages_groups ON messages_groups.msgid = messages_spatial.msgid WHERE messages_groups.arrival < ?;";
        $msgs = $this->dbhr->preQuery($sql, [
            $mysqltime
        ]);

        foreach ($msgs as $msg) {
            $this->dbhm->preExec("DELETE FROM messages_spatial WHERE id = ?;", [
                $msg['id']
            ]);
        }

        # Remove any messages which are no longer in Approved.  This can happen (e.g. for edits).
        error_log("Remove no longer approved");
        $sql = "SELECT DISTINCT messages_spatial.id, messages_spatial.msgid FROM messages_spatial INNER JOIN messages_groups ON messages_groups.msgid = messages_spatial.msgid WHERE collection != ?;";
        $msgs = $this->dbhr->preQuery($sql, [
            MessageCollection::APPROVED
        ]);

        foreach ($msgs as $msg) {
            error_log("{$msg['msgid']} no longer approved, remove from index");
            $this->dbhm->preExec("DELETE FROM messages_spatial WHERE id = ?;", [
                $msg['id']
            ]);
        }
    }

    public function addBy($userid, $count) {
        # Need a transaction.  We maintain the values in messages and messages_by in parallel; that's riskier, but
        # we need fast access to the message values, and that's what transactions are for.
        $this->dbhm->beginTransaction();

        # We need to find the current available, as we can't exceed that.
        $current = $this->dbhm->preQuery("SELECT availablenow FROM messages WHERE id = ?;", [
            $this->id
        ]);

        # We might be replacing an old value, in which case we should restore the number available to the message.
        if ($userid) {
            $existing = $this->dbhm->preQuery("SELECT * FROM messages_by WHERE msgid = ? AND userid = ?;", [
                $this->id,
                $userid
            ]);
        } else {
            $existing = $this->dbhm->preQuery("SELECT * FROM messages_by WHERE msgid = ? AND userid IS NULL;", [
                $this->id
            ]);
        }

        $found = FALSE;

        foreach ($existing as $e) {
            $this->dbhm->preExec("UPDATE messages SET availablenow = LEAST(availableinitially, availablenow + ?) WHERE id = ?;", [
                $e['count'],
                $this->id
            ]);

            $this->dbhm->preExec("UPDATE messages_by SET count = ? WHERE id = ?;", [
                min($count, $current[0]['availablenow'] + $e['count']),
                $e['id']
            ]);

            $found = TRUE;
        }

        if (!$found) {
            $this->dbhm->preExec("INSERT INTO messages_by (userid, msgid, count) VALUES (?, ?, ?);", [
                $userid,
                $this->id,
                min($count, $current[0]['availablenow'])
            ]);
        }

        // Update the count in the message.
        $this->dbhm->preExec("UPDATE messages SET availablenow = LAST_INSERT_ID(GREATEST(availablenow - ?, 0)) WHERE id = ?;", [
            $count,
            $this->id
        ]);

        $this->dbhm->commit();
    }

    public function removeBy($userid) {
        $this->dbhm->beginTransaction();
        error_log("Remove $userid");

        # We might be replacing an old value, in which case we should restore the number available to the message.
        if ($userid) {
            $existing = $this->dbhm->preQuery("SELECT * FROM messages_by WHERE msgid = ? AND userid = ?;", [
                $this->id,
                $userid
            ]);
        } else {
            $existing = $this->dbhm->preQuery("SELECT * FROM messages_by WHERE msgid = ? AND userid IS NULL;", [
                $this->id
            ]);
        }

        foreach ($existing as $e) {
            $this->dbhm->preExec("UPDATE messages SET availablenow = LEAST(availableinitially, LAST_INSERT_ID(availablenow) + ?) WHERE id = ?;", [
                $e['count'],
                $this->id
            ]);

            $this->dbhm->preExec("DELETE FROM messages_by WHERE id = ?;", [
                $e['id']
            ]);
        }

        $this->dbhm->commit();
    }

    public function partnerConsent($partner) {
        # Give consent to a partner to seem more info about this message.
        $ret = FALSE;

        $partners = $this->dbhr->preQuery("SELECT id FROM partners_keys WHERE partner LIKE ?;", [
            $partner
        ]);

        foreach ($partners as $p) {
            $ret = TRUE;
            $this->dbhm->preExec("INSERT INTO partners_messages (msgid, partnerid) VALUES (?, ?) ON DUPLICATE KEY UPDATE msgid = ?", [
                $this->id,
                $p['id'],
                $this->id
            ]);
        }

        return $ret;
    }
}