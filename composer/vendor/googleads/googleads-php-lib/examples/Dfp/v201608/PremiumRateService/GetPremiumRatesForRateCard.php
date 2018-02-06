<?php
/**
 * This example gets all premium rates on a specific rate card. To see what rate
 * cards exist, please run GetAllRateCards.php. To create premium rates, run
 * CreatePremiumRates.php.
 *
 * PHP version 5
 *
 * Copyright 2014, Google Inc. All Rights Reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @package    GoogleApiAdsDfp
 * @subpackage v201608
 * @category   WebServices
 * @copyright  2014, Google Inc. All Rights Reserved.
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache License,
 *             Version 2.0
 */
error_reporting(E_STRICT | E_ALL);

// You can set the include path to src directory or reference
// DfpUser.php directly via require_once.
// $path = '/path/to/dfp_api_php_lib/src';
$path = dirname(__FILE__) . '/../../../../src';
set_include_path(get_include_path() . PATH_SEPARATOR . $path);

require_once 'Google/Api/Ads/Dfp/Lib/DfpUser.php';
require_once 'Google/Api/Ads/Dfp/Util/v201608/StatementBuilder.php';
require_once dirname(__FILE__) . '/../../../Common/ExampleUtils.php';

// Set the ID of the rate card to get premium rates for.
$rateCardId = 'INSERT_RATE_CARD_ID_HERE';

try {
  // Get DfpUser from credentials in "../auth.ini"
  // relative to the DfpUser.php file's directory.
  $user = new DfpUser();

  // Log SOAP XML request and response.
  $user->LogDefaults();

  // Get the PremiumRateService.
  $premiumRateService = $user->GetService('PremiumRateService', 'v201608');

  // Create a statement to select only premium rates on a specific rate card.
  $statementBuilder = new StatementBuilder();
  $statementBuilder->Where('rateCardId = :rateCardId')
      ->OrderBy('id ASC')
      ->Limit(StatementBuilder::SUGGESTED_PAGE_LIMIT)
      ->WithBindVariableValue('rateCardId', $rateCardId);

  // Default for total result set size.
  $totalResultSetSize = 0;

  do {
    // Get premium rates by statement.
    $page = $premiumRateService->getPremiumRatesByStatement(
        $statementBuilder->ToStatement());

    // Display results.
    if (isset($page->results)) {
      $totalResultSetSize = $page->totalResultSetSize;
      $i = $page->startIndex;
      foreach ($page->results as $premiumRate) {
        printf("%d) Premium rate with ID %d, of type '%s', assigned to rate "
            . "card with ID %d was found.\n",
            $i++, $premiumRate->id,
            get_class($premiumRate->premiumFeature),
            $premiumRate->rateCardId
        );
      }
    }

    $statementBuilder->IncreaseOffsetBy(StatementBuilder::SUGGESTED_PAGE_LIMIT);
  } while ($statementBuilder->GetOffset() < $totalResultSetSize);

  printf("Number of results found: %d\n", $totalResultSetSize);
} catch (OAuth2Exception $e) {
  ExampleUtils::CheckForOAuth2Errors($e);
} catch (ValidationException $e) {
  ExampleUtils::CheckForOAuth2Errors($e);
} catch (Exception $e) {
  printf("%s\n", $e->getMessage());
}

