<?php
namespace Freegle\Iznik;

function story_one($groupname, $headline, $story, $hr = TRUE) {
    $html = '<h3>' . htmlspecialchars(trim($headline)) . '</h3>' .
        '<span style="color: gray">From a freegler on&nbsp;' . htmlspecialchars($groupname) . '</span><br />' .
        '<p>' . nl2br(trim($story)) . '</p>';

    if ($hr) {
        $html .= '<p style="font-size:3px; line-height:3px; border-top:1px solid grey;">&nbsp;</p>';
    }

    return($html);
}
