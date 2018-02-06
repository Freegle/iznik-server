<?php
/**
 * This example gets geographic criteria from the Geo_Target table, such as
 * all cities available to target. Other types include 'Country', 'Region',
 * 'State', 'Postal_Code', and 'DMA_Region' (i.e. Metro). This example may take
 * a while to run.
 *
 * NOTE: Since this example loads all results into memory, your PHP memory_limit
 *       may need to be raised for this example to work properly.
 *
 * A full list of available geo target types can be found at:
 * https://developers.google.com/doubleclick-publishers/docs/reference/v201608/PublisherQueryLanguageService
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
require_once 'Google/Api/Ads/Dfp/Util/v201608/Pql.php';
require_once 'Google/Api/Ads/Dfp/Util/v201608/StatementBuilder.php';
require_once dirname(__FILE__) . '/../../../Common/ExampleUtils.php';

try {
  // Get DfpUser from credentials in "../auth.ini"
  // relative to the DfpUser.php file's directory.
  $user = new DfpUser();

  // Log SOAP XML request and response.
  $user->LogDefaults();

  // Get the PublisherQueryLanguageService.
  $pqlService = $user->GetService('PublisherQueryLanguageService', 'v201608');

  // Set the type of geo target.
  $geoTargetType = 'City';

  // Create statement to select all line items.
  $statementBuilder = new StatementBuilder();
  $statementBuilder->Select(
      'Id, Name, CanonicalParentId, ParentIds, CountryCode, Type, Targetable')
      ->From('Geo_Target')
      ->Where('Type = :Type AND Targetable = true')
      ->OrderBy('CountryCode ASC, Name ASC')
      ->Limit(StatementBuilder::SUGGESTED_PAGE_LIMIT)
      ->WithBindVariableValue('Type', $geoTargetType);

  // Default for result sets.
  $resultSet = null;
  $combinedResultSet = null;
  $i = 0;

  do {
    // Get all cities.
    $resultSet = $pqlService->select($statementBuilder->ToStatement());

    // Combine result sets with previous ones.
    $combinedResultSet = (!isset($combinedResultSet))
        ? $resultSet
        : Pql::CombineResultSets($combinedResultSet, $resultSet);

    printf("%d) %d geo targets beginning at offset %d were found.\n", $i++,
        isset($resultSet->rows) ? count($resultSet->rows) : 0,
        $statementBuilder->GetOffset());

    $statementBuilder->IncreaseOffsetBy(StatementBuilder::SUGGESTED_PAGE_LIMIT);
  } while (isset($resultSet->rows) && count($resultSet->rows) > 0);

  // Change to your file location.
  $filePath = sprintf("%s/%s-%s.csv", sys_get_temp_dir(), $geoTargetType,
      uniqid());
  $fp = fopen($filePath, 'w');

  // Write the result set to a CSV.
  fputcsv($fp, Pql::GetColumnLabels($combinedResultSet));
  foreach ($combinedResultSet->rows as $row) {
     fputcsv($fp, Pql::GetRowStringValues($row));
  }
  fclose($fp);

  printf("Geo targets saved to %s\n", $filePath);
} catch (OAuth2Exception $e) {
  ExampleUtils::CheckForOAuth2Errors($e);
} catch (ValidationException $e) {
  ExampleUtils::CheckForOAuth2Errors($e);
} catch (Exception $e) {
  printf("%s\n", $e->getMessage());
}

