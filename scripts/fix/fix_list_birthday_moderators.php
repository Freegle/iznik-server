<?php

namespace Freegle\Iznik;

/**
 * Script to list users who qualify as moderators for inclusion in birthday mailings
 * Based on the logic from Donations.php around line 485
 *
 * Usage: php fix_list_birthday_moderators.php -g <groupid>
 */

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

// Parse command line arguments
$options = getopt("g:", ["group:"]);

if (!isset($options['g']) && !isset($options['group'])) {
    echo "Usage: php fix_list_birthday_moderators.php -g <groupid>\n";
    echo "Example: php fix_list_birthday_moderators.php -g 12345\n";
    exit(1);
}

$groupId = intval($options['g'] ?? $options['group']);

if ($groupId <= 0) {
    echo "Error: Invalid group ID. Must be a positive integer.\n";
    exit(1);
}

try {
    // Load the group
    $g = Group::get($dbhr, $dbhm, $groupId);

    if (!$g || !$g->getId()) {
        echo "Error: Group with ID $groupId not found.\n";
        exit(1);
    }

    $groupName = $g->getPrivate('namefull');
    $groupShort = $g->getPrivate('nameshort');

    echo "=== Birthday Mailing Moderators for Group: $groupName ($groupShort) ===\n";
    echo "Group ID: $groupId\n\n";

    // Get moderators using the same logic as the API
    $ctx = NULL;
    $mods = $g->getMembers(100, NULL, $ctx, NULL, MembershipCollection::APPROVED, NULL, NULL, NULL, NULL, Group::FILTER_MODERATORS);

    if (!$mods || count($mods) == 0) {
        echo "No moderators found for this group.\n";
        exit(0);
    }

    echo "Total moderators in group: " . count($mods) . "\n";
    echo "Checking qualification criteria...\n\n";

    $qualifyingModerators = [];
    $oneYearAgo = date('Y-m-d H:i:s', strtotime('-1 year'));

    echo "Qualification criteria:\n";
    echo "- Must be an approved moderator\n";
    echo "- Must have accessed the system after: $oneYearAgo\n";
    echo "- Must have publish consent enabled\n\n";

    foreach ($mods as $mod) {
        $modUser = new User($dbhr, $dbhm, $mod['userid']);
        $lastAccess = $modUser->getPrivate('lastaccess');
        $publishConsent = $modUser->getPrivate('publishconsent');
        $displayName = $modUser->getName();
        $email = $modUser->getEmailPreferred();

        // Check qualification criteria
        $isActive = $lastAccess && $lastAccess > $oneYearAgo;
        $hasConsent = $publishConsent == 1;
        $qualifies = $isActive && $hasConsent;

        // Extract first name
        $firstName = explode(' ', $displayName)[0];

        $moderator = [
            'id' => $mod['userid'],
            'displayname' => $displayName,
            'firstname' => $firstName,
            'email' => $email,
            'lastaccess' => $lastAccess,
            'publishconsent' => $publishConsent,
            'qualifies' => $qualifies,
            'reasons' => []
        ];

        // Add reasons for non-qualification
        if (!$isActive) {
            $moderator['reasons'][] = $lastAccess ? "Last access too old: $lastAccess" : "Never accessed system";
        }
        if (!$hasConsent) {
            $moderator['reasons'][] = "No publish consent";
        }

        if ($qualifies) {
            $qualifyingModerators[] = $moderator;
        }

        // Display details for all moderators
        $status = $qualifies ? "✅ QUALIFIES" : "❌ DOES NOT QUALIFY";
        echo sprintf("%-20s | %-30s | %-25s | %s\n",
            $firstName,
            $email ?: 'No email',
            $lastAccess ?: 'Never',
            $status
        );

        if (!$qualifies && !empty($moderator['reasons'])) {
            echo sprintf("%20s   Reasons: %s\n", '', implode(', ', $moderator['reasons']));
        }
    }

    echo "\n" . str_repeat("=", 80) . "\n";
    echo "SUMMARY:\n";
    echo "Total moderators: " . count($mods) . "\n";
    echo "Qualifying moderators: " . count($qualifyingModerators) . "\n";
    echo "Non-qualifying moderators: " . (count($mods) - count($qualifyingModerators)) . "\n\n";

    if (count($qualifyingModerators) > 0) {
        echo "QUALIFYING MODERATORS (for birthday mailing inclusion):\n";
        echo str_repeat("-", 80) . "\n";

        foreach ($qualifyingModerators as $mod) {
            echo sprintf("ID: %-8s | Name: %-25s | First: %-15s | Email: %s\n",
                $mod['id'],
                $mod['displayname'],
                $mod['firstname'],
                $mod['email'] ?: 'No email'
            );
        }

        echo "\nJSON Output (for programmatic use):\n";
        echo json_encode($qualifyingModerators, JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "⚠️  No moderators qualify for birthday mailing inclusion.\n";
        echo "Consider reviewing qualification criteria or moderator activity.\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}