<?php
require_once(IZNIK_BASE . '/mailtemplates/header.php');
require_once(IZNIK_BASE . '/mailtemplates/footer.php');

function digest_multiple($available, $availablesumm, $unavailable, $siteurl, $domain, $logo, $groupname, $subject, $fromname, $reply) {
    $newsfeed = "https://" . USER_SITE . "/newsfeed";

    $html = <<<EOT
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html>
<head>
    <title>$subject</title>
EOT;

    $html .= mail_header();
    $html .= <<<EOT
<!-- Start Background -->
<table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#F7F5EB">
    <tr>
        <td width="100%" valign="top" align="center">

            <!-- Start Wrapper  -->
            <table width="95%" cellpadding="0" cellspacing="0" border="0" class="wrapper" bgcolor="#FFFFFF">
                <tr>
                    <td height="10" style="font-size:10px; line-height:10px;">   </td><!-- Spacer -->
                </tr>
                <tr>
                    <td align="center">

                        <!-- Start Container  -->
                        <table width="100%" cellpadding="0" cellspacing="0" border="0" class="container">
                            <tr>
                                <td width="100%" class="mobile" style="font-family:arial; font-size:12px; line-height:18px;">
                                    <table width="95%" cellpadding="0" cellspacing="0" border="0" class="wrapper" bgcolor="#FFFFFF">
                                        <tr>
                                            <td height="20" style="font-size:10px; line-height:10px;"> </td><!-- Spacer -->
                                        </tr>
                                        <tr>
                                            <td align="center">
                                                <table width="95%" cellpadding="0" cellspacing="0" border="0" class="container">
                                                    <tbody>
                                                        <tr>
                                                            <td width="150" class="mobileOff">
                                                                <table class="button" width="90%" cellpadding="0" cellspacing="0" align="left" border="0">
                                                                    <tr>
                                                                        <td>                                                           
                                                                            <a href="$siteurl">
                                                                                <img src="$logo" style="width: 100px; height: 100px; border-radius:3px; margin:0; padding:0; border:none; display:block;" alt="" class="imgClass" />
                                                                            </a>
                                                                        </td>
                                                                    </tr>
                                                                </table>               
                                                            </td>    
                                                            <td>
                                                                <p>You've received this automated mail because you're a member of <a href="{{visit}}">$groupname</a>.</p>
                                                                <table width="100%">
                                                                    <tr>
                                                                        <td>
                                                                            <table class="button" width="90%" cellpadding="0" cellspacing="0" align="left" border="0">
                                                                                <tr>
                                                                                    <td width="50%" height="36" bgcolor="#377615" align="center" valign="middle"
                                                                                        style="font-family: Century Gothic, Arial, sans-serif; font-size: 16px; color: #ffffff;
                                                                                            line-height:18px; border-radius:3px;">
                                                                                        <a href="{{post}}" alias="" style="font-family: Century Gothic, Arial, sans-serif; text-decoration: none; color: #ffffff;">&nbsp;Freegle&nbsp;something!&nbsp;</a>
                                                                                    </td>
                                                                                </tr>
                                                                            </table>
                                                                         </td>
                                                                        <td>
                                                                            <table class="button" width="90%" cellpadding="0" cellspacing="0" align="left" border="0">
                                                                                <tr>
                                                                                    <td width="50%" height="36" bgcolor="#377615" align="center" valign="middle"
                                                                                        style="font-family: Century Gothic, Arial, sans-serif; font-size: 16px; color: #ffffff;
                                                                                            line-height:18px; border-radius:3px;">
                                                                                        <a href="{{visit}}" alias="" style="font-family: Century Gothic, Arial, sans-serif; text-decoration: none; color: #ffffff;">&nbsp;Browse&nbsp;the&nbsp;group&nbsp;</a>
                                                                                    </td>
                                                                                </tr>
                                                                            </table>
                                                                        </td>
                                                                        <td>
                                                                            <table class="button" width="90%" cellpadding="0" cellspacing="0" align="left" border="0">
                                                                                <tr>
                                                                                    <td width="50%" height="36" bgcolor="#336666" align="center" valign="middle"
                                                                                        style="font-family: Century Gothic, Arial, sans-serif; font-size: 16px; color: #ffffff;
                                                                                            line-height:18px; border-radius:3px;">
                                                                                        <a href="{{unsubscribe}}" alias="" style="font-family: Century Gothic, Arial, sans-serif; text-decoration: none; color: #ffffff;">&nbsp;Unsubscribe&nbsp;</a>
                                                                                    </td>
                                                                                </tr>
                                                                            </table>
                                                                         </td>
                                                                    </tr>                                                                    
                                                                </table>
                                                            </td>
                                                        </tr>        
                                                        <tr>
                                                            <td height="20" style="font-size:10px; line-height:10px;"> </td><!-- Spacer -->
                                                        </tr>
                                                        <tr>
                                                            <td colspan="2">
                                                                <font color=gray><hr></font>
                                                            </td>
                                                        </tr>        
                                                        <tr>
                                                            <td colspan="2">
                                                                <table border="0" cellpadding="0" cellspacing="0" ><tr><td colspan="2"><a style="display: block; width: 300px; height: 250px;" href="http://li.ilovefreegle.org/click?s=285301&sz=300x250&sh={{LI_HASH}}&p={{LI_PLACEMENT_ID}}" rel="nofollow"><img src="http://li.ilovefreegle.org/imp?s=285301&sz=300x250&sh={{LI_HASH}}&p={{LI_PLACEMENT_ID}}" border="0" width="300" height="250"/></a></td></tr><tr style="display:block; height:1px; line-height:1px;"><td><img src="http://li.ilovefreegle.org/imp?s=285302&sz=1x1&sh={{LI_HASH}}&p={{LI_PLACEMENT_ID}}" height="1" width="10" /></td><td><img src="http://li.ilovefreegle.org/imp?s=285303&sz=1x1&sh={{LI_HASH}}&p={{LI_PLACEMENT_ID}}" height="1" width="10" /></td></tr><tr><td align="left"><a href="http://li.ilovefreegle.org/click?s=285298&sz=116x15&sh={{LI_HASH}}&p={{LI_PLACEMENT_ID}}" rel="nofollow"><img src="http://li.ilovefreegle.org/imp?s=285298&sz=116x15&sh={{LI_HASH}}&p={{LI_PLACEMENT_ID}}" border="0"/></a></td><td align="right"><a href="http://li.ilovefreegle.org/click?s=285299&sz=69x15&sh={{LI_HASH}}&p={{LI_PLACEMENT_ID}}" rel="nofollow"><img src="http://li.ilovefreegle.org/imp?s=285299&sz=69x15&sh={{LI_HASH}}&p={{LI_PLACEMENT_ID}}" border="0"/></a></td></tr></table>                                                           
                                                            </td>
                                                        </tr>        
                                                        <tr>
                                                            <td colspan="2">
                                                                <font color=gray><hr></font>
                                                            </td>
                                                        </tr>        
EOT;

    if ($available != '') {
        $html .= '<tr><td colspan="2" class="mobile" valign="top">';
        $html .= '<h2><span style="color:green;">New Posts</span></h2>';
        $html .= "<p>Here's what people are freegling since we last mailed you.</p></td></tr>";
        $html .= '<tr><td colspan="2"><strong>' . $availablesumm . '</strong></td></tr>';
        $html .= '<tr><td colspan="2"><p>Scroll down for details and to reply.</p></td></tr>';
        $html .= '<tr><td colspan="2"><font color=gray><hr></font></td></tr>';
        $html .= '<tr><td colspan="2">' . $available . '</td></tr>';
    }

    if ($unavailable != '') {
        $html .= '<tr><td colspan="2" class="mobile" valign="top">';
        $html .= '<h2><span style=\"color:green\">Completed Posts</span></h2></td></tr>';
        $html .= '<tr><td colspan="2"><p>These posts have been completed.</p></td></tr>';
        $html .= '<tr><td colspan="2">' . $unavailable . '</td></tr>';
    }
    
    $html .= <<<EOT
                                                        <tr>
                                                            <td colspan="2" style="color: grey; font-size:10px;">
                                                                <p>This mail was sent to {{email}}.  You are set to receive updates for $groupname {{frequency}}.</p>
                                                                <p>You can change your settings by clicking <a href="$siteurl/settings">here</a>, or turn these mails off by emailing <a href="mailto:{{noemail}}">{{noemail}}</a></p>
                                                                <p>Freegle is registered as a charity with HMRC (ref. XT32865) and is run by volunteers. Which is nice.</p> 
                                                            </td>
                                                        </tr>        
                                                    </tbody>
                                                </table>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td height=\"10\" style=\"font-size:10px; line-height:10px;\"> </td>
                </tr>
           </table>
       </td>
       </tr>
</table>
<table cellpadding="0" cellspacing="0" border="0" width="40" height="6"><tbody><tr><td><img src="http://li.ilovefreegle.org/imp?s=125043500&sz=2x1&sh={{LI_HASH}}&p={{LI_PLACEMENT_ID}}" width="2" height="6" border="0" /></td><td><img src="http://li.ilovefreegle.org/imp?s=125043501&sz=2x1&sh={{LI_HASH}}&p={{LI_PLACEMENT_ID}}" width="2" height="6" border="0" /></td><td><img src="http://li.ilovefreegle.org/imp?s=125043502&sz=2x1&sh={{LI_HASH}}&p={{LI_PLACEMENT_ID}}" width="2" height="6" border="0" /></td><td><img src="http://li.ilovefreegle.org/imp?s=125043503&sz=2x1&sh={{LI_HASH}}&p={{LI_PLACEMENT_ID}}" width="2" height="6" border="0" /></td><td><img src="http://li.ilovefreegle.org/imp?s=125043504&sz=2x1&sh={{LI_HASH}}&p={{LI_PLACEMENT_ID}}" width="2" height="6" border="0" /></td><td><img src="http://li.ilovefreegle.org/imp?s=125043505&sz=2x1&sh={{LI_HASH}}&p={{LI_PLACEMENT_ID}}" width="2" height="6" border="0" /></td><td><img src="http://li.ilovefreegle.org/imp?s=125043506&sz=2x1&sh={{LI_HASH}}&p={{LI_PLACEMENT_ID}}" width="2" height="6" border="0" /></td><td><img src="http://li.ilovefreegle.org/imp?s=125043507&sz=2x1&sh={{LI_HASH}}&p={{LI_PLACEMENT_ID}}" width="2" height="6" border="0" /></td><td><img src="http://li.ilovefreegle.org/imp?s=125043508&sz=2x1&sh={{LI_HASH}}&p={{LI_PLACEMENT_ID}}" width="2" height="6" border="0" /></td><td><img src="http://li.ilovefreegle.org/imp?s=125043509&sz=2x1&sh={{LI_HASH}}&p={{LI_PLACEMENT_ID}}" width="2" height="6" border="0" /></td><td><img src="http://li.ilovefreegle.org/imp?s=125043510&sz=2x1&sh={{LI_HASH}}&p={{LI_PLACEMENT_ID}}" width="2" height="6" border="0" /></td><td><img src="http://li.ilovefreegle.org/imp?s=125043511&sz=2x1&sh={{LI_HASH}}&p={{LI_PLACEMENT_ID}}" width="2" height="6" border="0" /></td><td><img src="http://li.ilovefreegle.org/imp?s=125043512&sz=2x1&sh={{LI_HASH}}&p={{LI_PLACEMENT_ID}}" width="2" height="6" border="0" /></td><td><img src="http://li.ilovefreegle.org/imp?s=125043513&sz=2x1&sh={{LI_HASH}}&p={{LI_PLACEMENT_ID}}" width="2" height="6" border="0" /></td><td><img src="http://li.ilovefreegle.org/imp?s=125043514&sz=2x1&sh={{LI_HASH}}&p={{LI_PLACEMENT_ID}}" width="2" height="6" border="0" /></td><td><img src="http://li.ilovefreegle.org/imp?s=125043515&sz=2x1&sh={{LI_HASH}}&p={{LI_PLACEMENT_ID}}" width="2" height="6" border="0" /></td><td><img src="http://li.ilovefreegle.org/imp?s=125043516&sz=2x1&sh={{LI_HASH}}&p={{LI_PLACEMENT_ID}}" width="2" height="6" border="0" /></td><td><img src="http://li.ilovefreegle.org/imp?s=125043517&sz=2x1&sh={{LI_HASH}}&p={{LI_PLACEMENT_ID}}" width="2" height="6" border="0" /></td><td><img src="http://li.ilovefreegle.org/imp?s=125043518&sz=2x1&sh={{LI_HASH}}&p={{LI_PLACEMENT_ID}}" width="2" height="6" border="0" /></td><td><img src="http://li.ilovefreegle.org/imp?s=125043519&sz=2x1&sh={{LI_HASH}}&p={{LI_PLACEMENT_ID}}" width="2" height="6" border="0" /></td></tr></tbody></table>
</body>
</html>
EOT;

    return($html);
}