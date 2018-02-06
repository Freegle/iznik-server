<?php
namespace PayPal\EBLBaseComponents;

use PayPal\Core\PPXmlMessage;

/**
 * Information that is used to indentify the Buyer. This is
 * used for auto authorization. Mandatory if Authorization is
 * requested.
 */
class BuyerDetailType
  extends PPXmlMessage
{

    /**
     * Information that is used to indentify the Buyer. This is
     * used for auto authorization. Mandatory if Authorization is
     * requested.
     * @access    public
     * @namespace ebl
     * @var \PayPal\EBLBaseComponents\IdentificationInfoType
     */
    public $IdentificationInfo;

    /**
     * Correlation id related to risk process done for the device.
     * Max length is 36 Chars.
     * @access    public
     * @namespace ebl
     * @var string
     */
    public $RiskSessionCorrelationID;

    /**
     * Buyer's IP Address
     * @access    public
     * @namespace ebl
     * @var string
     */
    public $BuyerIPAddress;

}
