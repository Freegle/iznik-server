<?php
namespace Freegle\Iznik;

# Standard email footer

function footer($unsub) {
    $html = <<<EOT
<table width="95%" cellpadding="0" cellspacing="0" border="0" class="wrapper" bgcolor="#F7F5EB">
    <tr>
        <td style="padding-left: 10px; color: grey; font-size:10px;">
            <p>You've got this mail because you're a member of Freegle.  
EOT;
if ($unsub) {
    $html .= <<<EOT
        <a href="$unsub">Unsubscribe</a></p>
EOT;
}

    $html .= <<<EOT
        </td>
    </tr>
    <tr>
        <td style="padding-left: 10px; color: grey; font-size:10px;">
            <p>Freegle is registered as a charity with HMRC (ref. XT32865) and is run by volunteers.  Which is nice.</p>
        </td>
    </tr>
</table>
EOT;

    return($html);
}