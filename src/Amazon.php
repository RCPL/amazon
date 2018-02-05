<?php
/**
 * @file
 * Contains Drupal\amazon\Amazon
 */

namespace Drupal\amazon;

use Drupal\amazon\AmazonRequest;
use Drupal\Core\Cache\CacheBackendInterface;
use ApaiIO\Configuration\GenericConfiguration;
use ApaiIO\Operations\BrowseNodeLookup;
use ApaiIO\Operations\Lookup;
use ApaiIO\Operations\Search;
use ApaiIO\Operations\SimilarityLookup;
use ApaiIO\ApaiIO;
use GuzzleHttp\Exception;

/**
 * Provides methods that interfaces with the Amazon Product Advertising API.
 *
 * @package Drupal\amazon
 */
class Amazon {

  /**
   * The server environment variables for (optionally) specifying the access
   * key and secret.
   */
  const AMAZON_ACCESS_KEY = 'AMAZON_ACCESS_KEY';
  const AMAZON_ACCESS_SECRET = 'AMAZON_ACCESS_SECRET';

  /**
   * @var \ApaiIO\ApaiIO
   *   The Amazon API object.
   */
  protected $apaiIO;

  /**
   * Provides an Amazon object for calling the Amazon API.
   *
   * @param string $associatesId
   *   The Amazon Associates ID (a.k.a. tag).
   * @param string $accessKey
   *   (optional) Access key to use for all API requests. If not specified, the
   *   access key is determined from other system variables.
   * @param string $accessSecret
   *   (optional) Access secret to use for all API requests. If not specified,
   *   the access key is determined from other system variables.
   * @param string $locale
   *   (optional) Which locale to run queries against. Valid values include: de,
   *   com, co.uk, ca, fr, co.jp, it, cn, es, in.
   */
  public function __construct($associatesId, $locale = 'com', $accessKey = '', $accessSecret = '') {
    if (empty($accessKey)) {
      $accessKey = self::getAccessKey();
      if (!$accessKey) {
        throw new \InvalidArgumentException('Configuration missing: Amazon access key.');
      }
    }
    if (empty($accessSecret)) {
      $accessSecret = self::getAccessSecret();
      if (!$accessSecret) {
        throw new \InvalidArgumentException('Configuration missing: Amazon access secret.');
      }
    }

    $client = new \GuzzleHttp\Client();
    $request = new \ApaiIO\Request\GuzzleRequest($client);

    $conf = new GenericConfiguration();
    try {
      $conf
        ->setCountry($locale)
        ->setAccessKey($accessKey)
        ->setSecretKey($accessSecret)
        ->setAssociateTag($associatesId)
        ->setRequest($request)
        ->setResponseTransformer(new \ApaiIO\ResponseTransformer\XmlToSimpleXmlObject());
    }
    catch (Exception $e) {
      drupal_set_message($e->getMessage(), 'error');
    }
    $this->apaiIO = new ApaiIO($conf);
  }

  /**
   * Returns the secret key needed for API calls.
   *
   * @return string|bool
   *   String on success, FALSE otherwise.
   */
  static public function getAccessSecret() {
    // Use credentials from environment variables, if available.
    $secret = getenv(self::AMAZON_ACCESS_SECRET);
    if ($secret) {
      return $secret;
    }

    // If not, use Drupal config variables. (Automatically handles overrides
    // in settings.php.)
    $secret = \Drupal::config('amazon.settings')->get('access_secret');
    if ($secret) {
      return $secret;
    }

    return FALSE;
  }

  /**
   * Returns the access key needed for API calls.
   *
   * @return string|bool
   *   String on success, FALSE otherwise.
   */
  static public function getAccessKey() {
    // Use credentials from environment variables, if available.
    $key = getenv(self::AMAZON_ACCESS_KEY);
    if ($key) {
      return $key;
    }

    // If not, use Drupal config variables. (Automatically handles overrides
    // in settings.php.)
    $key = \Drupal::config('amazon.settings')->get('access_key');
    if ($key) {
      return $key;
    }

    return FALSE;
  }

  /**
   * Gets information about an item, or array of items, from Amazon.
   *
   * @param array|string $items
   *   A string containing a single ASIN, or an array of ASINs, to look up.
   * @param string $type
   *   A string containing a type of lookup to do (e.g., ASIN, UPC, SKU,
   *   EAN, ISBN).
   *
   * @return array
   *   An array of SimpleXMLElement objects representing the response from
   *   Amazon.
   */
  public function lookup($items, $type = 'ASIN') {
    if (empty($items)) {
      throw new \InvalidArgumentException('Calling lookup without anything to lookup!');
    }
    if (!is_array($items)) {
      $items = [$items];
    }

    $results = [];
    // Cannot ask for info from more than 10 items in a single call.
    foreach(array_chunk($items, 10) as $asins) {
      if (count($asins) > 0) {
        $lookup = new Lookup();
        $lookup->setItemIds($asins);
        if ($type != 'ASIN') {
          $lookup->setIdType($type);
        }
        $lookup->setResponseGroup(['Large']);
        $result = $this->apaiIO->runOperation($lookup);
        $json = json_encode($result);
        $results = json_decode($json, TRUE);
      }
    }
    //drupal_set_message('results');dump($results);
    return $results;
  }

  public function browseNodeLookup($browseNodeId) {
    if (empty($browseNodeId)) {
      throw new \InvalidArgumentException('Calling lookup without browse node ID to lookup!');
    }

    $browseNodeLookup = new BrowseNodeLookup();
    $browseNodeLookup->setNodeId($browseNodeId);
    $browseNodeLookup->setResponseGroup(array('BrowseNodeInfo', 'TopSellers'));

    try {
      $result = $this->apaiIO->runOperation($browseNodeLookup);
    }
    catch (\Exception $e) {
      $result = NULL;
      drupal_set_message($e->getMessage(), 'error');
    }

    return $result;
  }

  /**
   * Gets information about an item, or array of items, from Amazon.
   *
   * @param string $code
   *   A string containing a single item code to look up.
   *
   * @return array
   *   An array of items representing the response from Amazon.
   */
  public function similarityLookup($code) {

    $similarityLookup = new SimilarityLookup();
    $similarityLookup->setItemId($code);
    $similarityLookup->setResponseGroup(['Large']);
    $result = $this->apaiIO->runOperation($similarityLookup);
    $json = json_encode($result);
    $similarItems = json_decode($json, TRUE);

    return $similarItems['Items']['Item'];
  }

  /**
   * Gets a list of similar item UPCs from Amazon (or cache if possible)
   *
   * @param string $code
   *   A string containing a single item code to look up.
   * @param string $type
   *   A string containing a type of lookup to do (e.g., ASIN, UPC, SKU,
   *   EAN, ISBN).
   *
   * @return string
   *   A single ASIN matching the code.
   */
  public function lookupASINfromUPC($code, $type = 'UPC') {
    // Store UPC lookup information in its own table so we don't
    // have to do this every time the cache is cleared for all items.
    $asin = db_query('SELECT asin FROM {amazon_upc} WHERE upc = :upc', array(':upc' => $code))->fetchField();
    if (empty($asin)) {
      // If we don't get anything back from the database, look it up, then add it to the db.
      $asin_lookup = self::lookup($code, $type);
      $asin = $asin_lookup['Items']['Item']['ASIN'];
      if (!empty($asin)) {
        db_insert('amazon_upc')
        ->fields(array(
          'asin' => $asin,
          'upc' => $code,
        ))
        ->execute();
      }
    }
    return $asin;
  }

  /**
   * Gets a list of similar item UPCs from Amazon (or cache if possible)
   *
   * @param string $code
   *   A string containing a single item code to look up.
   * @param string $type
   *   A string containing a type of lookup to do (e.g., ASIN, UPC, SKU,
   *   EAN, ISBN).
   *
   * @return array
   *   An array of items representing the response from Amazon.
   */
  public function getSimilarItemUPCs($code, $type = 'ASIN', $reset = FALSE) {
    $cid = 'Amazon:getSimilarItemUPCs:' . $code;
    $cache = \Drupal::cache()->get($cid);
    if (empty($cache) || $reset == TRUE) {
      try {
        // If it's not ASIN, we need to do a lookup to find ASIN here.
        if ($type == 'ASIN') {
          $asin = $code;
        }
        else if ($type == 'UPC') {
          $asin = self::lookupASINfromUPC($code);
        }
        $similarItems = self::similarityLookup($asin);
        $similarItemUPCs = [];
        foreach ($similarItems as $key => $similarItem) {
          // Store image information for each item to amazon_item_image table.
          // First check to see if it's already stored.
          $asin = db_query('SELECT asin FROM {amazon_item_image} WHERE asin = :asin', array(':asin' => $similarItem['ASIN']))->fetchField();
          if (empty($asin) && isset($similarItem['ImageSets'])) {
            foreach ($similarItem['ImageSets']['ImageSet'] as $imageset) {
              if (isset($imageset['@attributes']) && $imageset['@attributes']['Category'] == 'primary') {
                foreach ($imageset as $size => $data) {
                  if ($size != '@attributes') {
                    $image = array('asin' => $similarItem['ASIN'], 'size' => $size, 'height' => $data['Height'], 'width' => $data['Width'], 'url' => $data['URL']);
                    try {
                      db_insert('amazon_item_image')
                      ->fields($image)
                      ->execute();
                    }
                    catch (Exception $e) {
                      amazon_db_error_watchdog("Failed to insert item into amazon_item_image table", $e, $image);
                    }
                  }
                }
              }
            }
          }

          // Add any UPCs uncovered to the list.
          if (isset($similarItem['ItemAttributes']['UPCList']['UPCListElement'])) {
            if (is_array($similarItem['ItemAttributes']['UPCList']['UPCListElement'])) {
              $similarItemUPCs = array_merge($similarItemUPCs, $similarItem['ItemAttributes']['UPCList']['UPCListElement']);
            }
            else {
              array_push($similarItemUPCs, $similarItem['ItemAttributes']['UPCList']['UPCListElement']);
            }
            // Also store the UPC to ASIN translation.
            $result = self::lookupASINfromUPC($similarItem['ItemAttributes']['UPC']);
          }
        }
        \Drupal::cache()->set($cid, $similarItemUPCs, CacheBackendInterface::CACHE_PERMANENT, ['rendered']);
      }
      catch(Exception $e) {
        cache_clear_all($cid, 'cache');
        \Drupal::logger('amazon')->error('There was an issue executing getSimilarItemUPCs: @exception', ['@exception' => $e->getMessage()]);
        return;
      }
    }
    else {
      $similarItemUPCs = $cache->data;
    }
    return $similarItemUPCs;
  }

}
