<?php
/**
 * This example updates a product template by adding a new GeoTarget to its
 * targeting. To determine which product templates exist, run
 * GetAllProductTemplates.php.
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
 * @subpackage v201605
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
require_once 'Google/Api/Ads/Dfp/Util/v201605/StatementBuilder.php';
require_once dirname(__FILE__) . '/../../../Common/ExampleUtils.php';

// Set the ID of the product template to update.
$productTemplateId = 'INSERT_PRODUCT_TEMPLATE_ID_HERE';

try {
  // Get DfpUser from credentials in "../auth.ini"
  // relative to the DfpUser.php file's directory.
  $user = new DfpUser();

  // Log SOAP XML request and response.
  $user->LogDefaults();

  // Get the ProductTemplateService.
  $productTemplateService = $user->GetService('ProductTemplateService',
      'v201605');

  // Create a statement to select a single product template by ID.
  $statementBuilder = new StatementBuilder();
  $statementBuilder->Where('id = :id')
      ->OrderBy('id ASC')
      ->Limit(1)
      ->WithBindVariableValue('id', $productTemplateId);

  // Get the product template.
  $page = $productTemplateService->getProductTemplatesByStatement(
      $statementBuilder->ToStatement());
  $productTemplate = $page->results[0];

  // Add geo targeting for Canada to the product template.
  $countryLocation = new DfpLocation();
  $countryLocation->id = 2124;

  $productTemplateTargeting = $productTemplate->builtInTargeting;

  if (isset($productTemplateTargeting->geoTargeting)) {
    $productTemplateTargeting->geoTargeting[] = $countryLocation;
  } else {
    $productTemplateTargeting->geoTargeting = array($countryLocation);
  }

  $productTemplate->builtInTargeting = $productTemplateTargeting;

  // Update the product template on the server.
  $productTemplates =
      $productTemplateService->updateProductTemplates(array($productTemplate));

  foreach ($productTemplates as $updatedProductTemplate) {
    printf("Product template with ID %d and name '%s' was updated.\n",
        $updatedProductTemplate->id, $updatedProductTemplate->name);
  }
} catch (OAuth2Exception $e) {
  ExampleUtils::CheckForOAuth2Errors($e);
} catch (ValidationException $e) {
  ExampleUtils::CheckForOAuth2Errors($e);
} catch (Exception $e) {
  printf("%s\n", $e->getMessage());
}

