<?php 
namespace MCS;
 
class BolPlazaOrderItem{
    
    public $OrderItemId;
    public $EAN;
    public $OfferReference;
    public $Title;
    public $Quantity;
    public $OfferPrice;
    public $TransactionFee;
    public $PromisedDeliveryDate;
    public $OfferCondition;
    public $CancelRequest;
    
    public function __construct(array $array)
    {
        foreach ($array as $property => $value) {
            if (property_exists($this, $property)) {
                if (is_array($value)) {
                    $value = '';
                }
                $this->{$property} = $value;
            }
        }
        
        $this->Quantity = (int) $this->Quantity;
        $this->OfferPrice = (float) $this->OfferPrice;
        $this->TransactionFee = (float) $this->TransactionFee;
        $this->CancelRequest = $this->CancelRequest === 'TRUE' ? true : false;
        
    }
    
    public function has($property)
    {
        if (property_exists($this, $property)) {
            return is_null($this->{$property}) ? false : true;    
        } else {
            return false;    
        }
    }
}