<?php
namespace Freegle\Iznik;

function relevant_one($subject, $href, $matched, $reason) {
    $html = "<a href=\"$href\">";
    $s = str_ireplace($matched, "<b style=\"color: #FF8C00\">$matched</b>", $subject);
    $html .= "$s</a> <span style=\"color: grey\">because you ";
    $s = $reason['type'] == Relevant::MATCH_VIEWED ? $reason['term'] : preg_replace('/\[.*?\]\s*/', '', $reason['subject']);

    $s = str_ireplace($matched, "<b style=\"color: #FF8C00\">$matched</b>", $s);
    $html .= $reason['type'] == Relevant::MATCH_VIEWED ? "looked at a post containing '$s' " : "posted $s";
    $html .= " on " . date('d M', strtotime($reason['date']));
    $html .= "</span><br />";

    return($html);
}
