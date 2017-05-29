<?php 
namespace MCS;

use DateTime;
use Exception;
use DOMDocument;
use DateInterval;
use SimpleXMLElement;
use MCS\BolPlazaOrder;
use MCS\BolPlazaReturn;
use Holiday\Netherlands;

class BolPlazaClient{
  
    const CONTENT_TYPE = 'application/xml';
    const DATE_FORMAT = 'D, d M Y H:i:s T';
    const USER_AGENT = 'MCS/BolPlazaClient (https://github.com/meertensm/bol-plaza-v2)';
    
    protected $publicKey;
    protected $privateKey;
    protected $url;
    protected $test = false;
    
    public $endPoints = [
        'orders' => '/services/rest/orders/v2',
        'shipments' => '/services/rest/shipments/v2',
        'returns' => '/services/rest/return-items/v2/unhandled',
        'cancellations' => '/services/rest/order-items/v2/:id/cancellation',
        'process-status' => '/services/rest/orders/v2/process/:id',
        'shipping-status' => '/services/rest/process-status/v2/:id',
        'shipping-label' => '/services/rest/transports/v2/:transportId/shipping-label/:labelId', 
        'shipping-labels' => '/services/rest/purchasable-shipping-labels/v2?orderItemId=:id',     

        'commission' => '/commission/v2/:ean',
        'payments' => '/services/rest/payments/v2/:month',
        
        'offers-get' => '/offers/v2/:ean',
        'offers-upsert' => '/offers/v2/',
        'offers-export' => '/offers/v2/export',
        'offers-delete' => '/offers/v2/',
        'reductions' => '/reductions',
    ];
    
    public $deliveryCodes = [
        '24uurs-23',
        '24uurs-22',
        '24uurs-21',
        '24uurs-20',
        '24uurs-19',
        '24uurs-18',
        '24uurs-17',
        '24uurs-16',
        '24uurs-15',
        '24uurs-14',
        '24uurs-13',
        '24uurs-12',
        '1-2d',
        '2-3d',
        '3-5d',
        '4-8d',
        '1-8d'
    ];
    
    /**
     *Available fulfillment methods
     */
    private $fulfilmentMethods = [
        'FBR', 
        'FBB',
        'ALL'
    ];
    
    
    /**
     * Construct the client
     * @param string  $publicKey      
     * @param string  $privateKey     
     * @param boolean $test
     */
    public function __construct($publicKey, $privateKey, $test = false)
    {
        if (!$publicKey or !$privateKey) {
            throw new Exception('Either `$publicKey` or `$privateKey` not set');    
        } else {
            $this->publicKey = $publicKey;
            $this->privateKey = $privateKey;
            $this->test = (bool) $test;
            if ($this->test) {
                $this->url = 'https://test-plazaapi.bol.com';   
            } else {
                $this->url = 'https://plazaapi.bol.com';  
            }
        }
    }
    
    /**
     * Convert an object to an array
     * @param  mixed $mixed 
     * @return array
     */
    private function toArray($mixed)
    {
        return json_decode(
            json_encode($mixed),
        true);    
    }
    
    /**
     * Calculate the authorisation header
     * @param  string $httpMethod 
     * @param  string $endPoint   
     * @param  string $date       
     * @return string 
     */
    private function signature($httpMethod, $endPoint, $date)
    {   
        $newline = "\n";
        $signature_string = $httpMethod . $newline . $newline;
        $signature_string .= self::CONTENT_TYPE . $newline;
        $signature_string .= $date . $newline;
        $signature_string .= "x-bol-date:" . $date . $newline;
        $signature_string .= preg_replace('/\?.*/', '', $endPoint);
        return $this->publicKey . ':' . base64_encode(
            hash_hmac('SHA256', $signature_string, $this->privateKey, true)
        );
    }
    
    /**
     * Request the Bol.com Plaza Api
     * @param  string   $endPoint   
     * @param  string   $httpMethod 
     * @param  string   $body
     * @return array / string
     */
    public function request($endPoint, $httpMethod, $body = null)
    {
        
        $date = gmdate(self::DATE_FORMAT);
        $httpMethod = strtoupper($httpMethod);
        $config = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CUSTOMREQUEST => $httpMethod,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_URL => $this->url . $endPoint,
            CURLOPT_HTTPHEADER => [
                'Content-type: ' . self::CONTENT_TYPE, 
                'X-BOL-Date:' . $date, 
                'X-BOL-Authorization: ' . $this->signature($httpMethod, $endPoint, $date)
            ]
        ];
        
        if ($httpMethod !== 'GET' && !is_null($body)) {
            $config[CURLOPT_POSTFIELDS] = $body;    
        }

        $ch = curl_init();
        curl_setopt_array($ch, $config);
        $result = curl_exec($ch);
        
        $info = curl_getinfo($ch);
     
        if ($result === false) {
            throw new BolPlazaClientHttpException(curl_error($ch)); 
        }
        
        $info = curl_getinfo($ch);

        if (strpos($info['content_type'], 'xml') !== false) {
            $result = simplexml_load_string(strtr($result, [
                'bns:1' => '',
                'bns:' => '',
                'ns1:' => ''
            ]));
        }
        
        if (isset($result->ErrorCode)) {
            throw new BolPlazaClientHttpException($result->ErrorCode); 
        } else if (substr($info['http_code'], 0, 1) === 4) {
            throw new BolPlazaClientHttpException($info['http_code']); 
        }
        return $result ? (is_object($result) ? $this->toArray($result) : $result) : $info;
    }
    
    /**
     * Get the processingstatus from a request
     * @param  string $id 
     * @return array  
     */
    public function getProcessingStatus($id)
    {
        return $this->request(
            str_replace(':id', urlencode($id), $this->endPoints['process-status']), 
        'GET');
    }
  
    public function getShippingStatus($id)
    {
        return $this->request(
            str_replace(':id', urlencode($id), $this->endPoints['shipping-status']), 
        'GET');
    }
  
    public function getShippingLabel($transportId, $labelId)
    {
        $result = $this->request(str_replace(array(':transportId', ':labelId'), array(urlencode($transportId), urlencode($labelId)), $this->endPoints['shipping-label']),'GET');
        return($result);
    }
  
     public function getShippingLabels($orderItemId)
    {
        $result = $this->request(str_replace(':id', urlencode($orderItemId), $this->endPoints['shipping-labels']),'GET');
        return($result);
    }


    
    /**
     * Get all payments for the provided month, 
     * if no datatime is provided, the current month will be used 
     * @return array array of payments
     */
    public function getPayments(DateTime $date = null)
    {
        if (is_null($date)) {
            $date = new DateTime();
        }
     
        return $this->request(
            str_replace(':month', urlencode($date->format('Ym')), $this->endPoints['payments']), 
        'GET');
    }

    
    /**
     * Determine if an array is associative
     * @param array $array 
     * @return boolean 
     */
    private function is_assoc(array $array) {
      return (bool) count(array_filter(array_keys($array), 'is_string'));
    }
        
    
    
    /**
     * Retrieves commission information on a single offer
     * @param  integer $ean
     */
    public function getOffer($ean, $condition = 'NEW')
    {
        $offer = $this->request(
            str_replace(':ean', urlencode($ean), $this->endPoints['offers-get']) . '?condition=' . $condition, 
        'GET');
        if(isset($offer['RetailerOffers']['RetailerOffer']))
        {
            return $offer['RetailerOffers']['RetailerOffer'];
        }
    }
    
    /**
     * Get all current offers by using csv export file / parsing it.
     * @return array((reference) => (offer data))
     */
    public function getOffers()
    {
        $result_file = $this->request($this->endPoints['offers-export'], 'GET');
        if(!isset($result_file['Url'])) {
            return;
        }
        
        $url_no_domain = substr($result_file['Url'], strpos($result_file['Url'], $this->url) + strlen($this->url));
        $result_offers = $this->request($url_no_domain, 'GET');
        if(is_array($result_offers))
        {
           echo 'offer wtf';
            var_dump($result_offers);exit;
        }
        $rows = explode(PHP_EOL, $result_offers);
        $head = str_getcsv(array_shift($rows));
        
        $return = array();
        foreach($rows as $key => $row) {
            if(!$row) {
                break;
            }
            $parsed_row = str_getcsv($row);
            
            foreach($parsed_row as $csvkey => $csvval) {
                        //ean
                $return[$parsed_row[6]][$head[$csvkey]] = $csvval;
            }
        }
        return $return;
    }
    
    /**
     * Submit a new offer to the Bol.com Plaza Api
     * @param  string  $offerID            
     * @param  array
     * @return boolean  error information if any
     */
    public function upsertOffer(array $array = [])
    {
        $fields = [
            'EAN',
            'Condition',
            'Price',
            'DeliveryCode',
            'QuantityInStock',
            'Publish',
            'ReferenceCode',
            'Description',
            'Title'             // for new products without known EAN, this title will be shown instead of the known one @ bol
        ];
        
        $conditions = [
            'NEW',
            'AS_NEW',
            'GOOD',
            'REASONABLE',
            'MODERATE'
        ];
        
        foreach ($fields as $field) {
            if (array_key_exists($field, $array)) {
                $array[$field] = utf8_encode($array[$field]);    
            } else {
                throw new Exception('Field `' . $field . '` not set');
            }
        }
        
        $array['Price'] = (float) str_replace(',', '.', $array['Price']);
        $array['Publish'] = (bool) $array['Publish'] == true ? 'true' : 'false';
        $array['QuantityInStock'] = (int) $array['QuantityInStock'];
        $array['Description'] = htmlspecialchars($array['Description']);
        $array['ReferenceCode'] = htmlspecialchars($array['ReferenceCode']);
        
        if (!in_array($array['DeliveryCode'], $this->deliveryCodes)) {
            throw new Exception('Unknown DeliveryCode');        
        }
        
        if (!in_array($array['Condition'], $conditions)) {
            throw new Exception('Unknown Condition');        
        }
        
        if (strlen($array['EAN'] < 10)) {
            throw new Exception('Invalid EAN');   
        }
        
        if ($array['QuantityInStock'] > 500) {
            $array['QuantityInStock'] = 500;   
        }
        
        if (mb_strlen($array['ReferenceCode'], '8bit') > 20) {
            throw new Exception('ReferenceCode exceeded 20 bytes');    
        }
        
        if (mb_strlen($array['Description'], '8bit') > 2000) {
            throw new Exception('Description exceeded 2000 bytes');    
        }
        
        if ($array['Price'] > 9999.99) {
            throw new Exception('Too expensive');    
        }
        
        $xml = new DOMDocument('1.0', 'UTF-8');

        $body = $xml->appendChild(
            $xml->createElementNS('https://plazaapi.bol.com/offers/xsd/api-2.0.xsd', 'UpsertRequest')
        );
        $offer = $body->appendChild($xml->createElement('RetailerOffer'));
        
        foreach ($array as $key => $value) {
            $offer->appendChild(
                $xml->createElement($key, $value)
            );
        }
        
        $result = $this->request(
            $this->endPoints['offers-upsert'], 'PUT', $xml->saveXML()
        );
        
        return isset($result['http_code']) && $result['http_code'] == 202 ? false : $result;
    }
    
    /**
     * Delete an offer via the Bol.com Plaza Api
     * @param  string  $ean            
     * @param  string  $condition 
     * @return boolean  success
     */
    public function deleteOffer($ean, $condition = 'NEW')
    {
        $xml = new DOMDocument('1.0', 'UTF-8');

        $body = $xml->appendChild(
            $xml->createElementNS('https://plazaapi.bol.com/offers/xsd/api-2.0.xsd', 'DeleteBulkRequest')
        );
        $offer = $body->appendChild($xml->createElement('RetailerOfferIdentifier'));
        
        foreach (array('EAN' => $ean, 'Condition' => $condition) as $key => $value) {
            $offer->appendChild(
                $xml->createElement($key, $value)
            );
        }
        
        $result = $this->request(
            $this->endPoints['offers-delete'], 'DELETE', $xml->saveXML()
        );
        
        return isset($result['http_code']) && $result['http_code'] == 202;
    }
    
    /**
     * Get all orders
     * @return array of BolPlazaOrder objects / false if no orders are found
     */
    public function getOrders()
    {
        $result = $this->request($this->endPoints['orders'], 'GET');
        
        if (!isset($result['Order'])) {
            return false; 
        }
        
        if ($this->is_assoc($result['Order'])) {
            $result['Order'] = $result;
        }
        
        if (isset($result['Order'])) {
            $orders = [];
            foreach ($result['Order'] as $order) {
             
                $tmp = new BolPlazaOrder(
                    $order['OrderId'],
                    $order['DateTimeCustomer'],
                    $order['CustomerDetails']['ShipmentDetails'],
                    $order['CustomerDetails']['BillingDetails'],
                    $this
                );

                if (!$this->is_assoc($order['OrderItems']['OrderItem'])) {
                    $items = $order['OrderItems']['OrderItem'];
                } else {
                    $items = $order['OrderItems'];    
                }

                foreach ($items as $line) {
                    $tmp->addOrderItem($line);
                }

                $orders[$order['OrderId']] = $tmp;
            }
            return $orders;
        } else {
            return false;    
        }
    }
    
    public function getReturns()
    {

        $result = $this->request($this->endPoints['returns'], 'GET');
        
        if (!isset($result['Item'])) {
            return false; 
        }
        
        if ($this->is_assoc($result['Item'])) {
            $result['Item'] = [$result['Item']];
        }
            
        $returns = [];
        foreach ($result['Item'] as $return) {
            $tmp = new BolPlazaReturn($return);
            $returns[] = $tmp;
        }
        return $returns;
    }
    
    /**
     * Get an order by it's id
     * @param  string  $id 
     * @return object BolPlazaOrder / false if not found
     */
    public function getOrder($id)
    {
        $orders = $this->getOrders();
        if (count($orders)) {
            foreach ($orders as $order) {
                if ($order->id == $id) {
                    return $order;    
                }
            }
        }
        return false;
    }
    
    /**
     * Get shipments
     * @param  integer $page = 1
     * @param string $fulfilmentmethod = 'ALL'
     * @return array of shipments
     */
    public function getShipments($page = 1, $fulfilmentmethod='ALL')
    {
        $fulfilmentmethod = in_array($fulfilmentmethod, $this->fulfilmentMethods) ? $fulfilmentmethod : 'ALL';
        return $this->request($this->endPoints['shipments'] . '?page=' . $page .  '&fulfilmentmethod=' . $fulfilmentmethod, 'GET');
    }
    
    /**
     * Retrieves commission information on a single offer
     * @param  integer $ean
     */
    public function getReductions()
    {
        $result = $this->request($this->endPoints['reductions'], 'GET');
        
        $rows = explode(PHP_EOL, $result);
        $head = str_getcsv(array_shift($rows));
        
        $return = array();
        foreach($rows as $row) {
            if(!$row) {
                break;
            }
            $parsed_row = str_getcsv($row);
            
            foreach($parsed_row as $csvkey => $csvval) {
                $return[$parsed_row[0]][$head[$csvkey]] = $csvval;
            }
        }
        return $return;
    }
    
    /**
     * Retrieves commission information on a single offer
     * @param  integer $ean
     */
    public function getCommission($ean)
    {
        return $this->request(
            str_replace(':ean', urlencode($ean), $this->endPoints['commission']), 
        'GET');
    }
    
    /**
     * Calculate the next deliverydate (Netherlands only)
     * @param  string   [$time = '18:00'] The last time in a day when orders are shipped from your warehouse
     * @param  array    [$noDeliveryDays = ['Sun', 'Mon']] On what days the carrier does not deliver packages
     * @param  array    [$noPickupDays = ['Sat', 'Sun']] On what days the carrier does not pickup / collect packages
     * @param  string   [$deliveryTime = '12:00'] What time should te estimated deliveryday have
     * @return DateTime the date and time the package can be expected
     */
    public function nextDeliveryDate(
        $time = '18:00', 
        $noDeliveryDays = ['Sun', 'Mon'], 
        $noPickupDays = ['Sat', 'Sun'], 
        $deliveryTime = '12:00')
    {
    
        if (!preg_match('/(2[0-3]|[01][0-9]):([0-5][0-9])/', $deliveryTime)) {
            throw new Exception('Invalid time. Use format `H:i`');
        }
        
        $latestShippingTime = new DateTime($time);
        $nextDay = new DateTime();
        $holiDayCalculator = new Netherlands();
        $addOneDayInterval = new DateInterval('P1D');
        $pickup = new DateTime();
        
        if (new DateTime() >= $latestShippingTime) {
            $add_days = '2';
            $pickup->add(new DateInterval('P1D'));
        } else {
            $add_days = '1';
        }
    
        $nextDay->add(new DateInterval('P' . $add_days . 'D'));
                
        $pickupDay = $pickup->format('D');
        
        // If today is not a pickup day, change it to tomorrow and try again
        while (in_array($pickupDay, $noPickupDays)) {
            $nextDay->add($addOneDayInterval);
            $pickupDay = new DateTime($pickupDay);
            $pickupDay->add($addOneDayInterval);
            $pickupDay = $pickupDay->format('D');
        }
        
        // If today is a holiday, change it and try again
        while (count($holiDayCalculator->isHoliday($nextDay)) > 0) {
            $nextDay->add($addOneDayInterval);
        }
        
        $deliveryDay = $nextDay->format('D');
        
        // If day is not a delivery day, change it and try again
        while (in_array($deliveryDay, $noDeliveryDays)) {
            $nextDay->add($addOneDayInterval);
            $deliveryDay = new DateTime($deliveryDay);
            $deliveryDay->add($addOneDayInterval);
            $deliveryDay = $deliveryDay->format('D');
        }
        
        // Finaly, if the delivery day is a holiday, change it and try again
        while (count($holiDayCalculator->isHoliday($nextDay)) > 0) {
            $nextDay->add($addOneDayInterval);
        }
        
        $minutesAndSeconds = explode(':', $deliveryTime);
        
        return $nextDay->setTime($minutesAndSeconds[0], $minutesAndSeconds[1]);
    }
}
