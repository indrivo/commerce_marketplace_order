<?php

namespace Drupal\commerce_marketplace_order;

use Drupal\commerce\CommerceContentEntityStorage;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\Event\FilterShippingMethodsEvent;
use Drupal\commerce_shipping\Event\ShippingEvents;
use Drupal\commerce_shipping\ShippingMethodStorage;

/**
 * Defines the shipping method storage.
 */
class StoreShippingMethodStorage extends ShippingMethodStorage {

  /**
   * {@inheritdoc}
   */
  public function loadMultipleForShipment(ShipmentInterface $shipment) {
    $query = $this->getQuery();
    $query
      ->condition('stores', $shipment->getData('store_id'))
      ->condition('status', TRUE);
    $result = $query->execute();
    if (empty($result)) {
      return [];
    }

    $shipping_methods = $this->loadMultiple($result);
    // Allow the list of shipping methods to be filtered via code.
    $event = new FilterShippingMethodsEvent($shipping_methods, $shipment);
    $this->eventDispatcher->dispatch(ShippingEvents::FILTER_SHIPPING_METHODS, $event);
    $shipping_methods = $event->getShippingMethods();
    // Evaluate conditions for the remaining ones.
    foreach ($shipping_methods as $shipping_method_id => $shipping_method) {
      if (!$shipping_method->applies($shipment)) {
        unset($shipping_methods[$shipping_method_id]);
      }
    }
    uasort($shipping_methods, [$this->entityType->getClass(), 'sort']);

    return $shipping_methods;
  }

}
