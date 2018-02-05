<?php
/**
 * @file
 * Contains Drupal\amazon\LookupXmlToItemsArray
 */

namespace Drupal\amazon;

use ApaiIO\ResponseTransformer\ResponseTransformerInterface;

class LookupXmlToItemsArray implements ResponseTransformerInterface {

  /**
   * @param string $response
   *   XML response from Amazon's REST services.
   *
   * @return array
   *   Array of SimpleXMLElement objects representing the response from Amazon.
   */
  public function transform($response) {
    $xml = simplexml_load_string($response);
    $xml->registerXPathNamespace("amazon", "http://webservices.amazon.com/AWSECommerceService/2011-08-01");
    $elements = $xml->xpath('//amazon:ItemLookupResponse/amazon:Items/amazon:Item');
    return $elements;
  }

}
