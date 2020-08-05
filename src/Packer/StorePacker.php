<?php

namespace Drupal\commerce_marketplace_order\Packer;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_shipping\Packer\DefaultPacker;
use Drupal\commerce_shipping\ProposedShipment;
use Drupal\commerce_shipping\ShipmentItem;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\physical\Weight;
use Drupal\physical\WeightUnit;
use Drupal\profile\Entity\ProfileInterface;

/**
 * Creates a single shipment per store.
 */
class StorePacker extends DefaultPacker {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function pack(OrderInterface $order, ProfileInterface $shipping_profile) {
    $items = [];
    $store_labels = [];

    foreach ($order->getItems() as $order_item) {
      $purchased_entity = $order_item->getPurchasedEntity();

      // Skip items not shippable purchasable entity types.
      if (!$purchased_entity || !$purchased_entity->hasField('weight')) {
        continue;
      }

      // Use default store for items without stores.
      if (!$item_stores = $purchased_entity->getStores()) {
        $item_store = $this->entityTypeManager->getStorage('commerce_store')->loadDefault();
      }
      else {
        $item_store = reset($item_stores);
      }
      $item_store_id = $item_store->id();
      $store_labels[$item_store_id] = $item_store->getName();

      $quantity = $order_item->getQuantity();
      /** @var \Drupal\physical\Weight $weight */
      $weight = $purchased_entity->get('weight')->isEmpty() ? new Weight(0, WeightUnit::GRAM) :
        $purchased_entity->get('weight')->first()->toMeasurement();

      $items[$item_store_id][] = new ShipmentItem([
        'order_item_id' => $order_item->id(),
        'title' => $order_item->getTitle(),
        'quantity' => $quantity,
        'weight' => $weight->multiply($quantity),
        'declared_value' => $order_item->getUnitPrice()->multiply($quantity),
      ]);
    }

    $proposed_shipments = [];
    if (!empty($items)) {
      foreach ($items as $store_id => $store_items) {
        $proposed_shipments[] = new ProposedShipment([
          'type' => $this->getShipmentType($order),
          'order_id' => $order->id(),
          'title' => $this->t('Shipment from @store', ['@store' => $store_labels[$store_id]]),
          'items' => $store_items,
          'shipping_profile' => $shipping_profile,
          'custom_fields' => ['store_id' => $store_id],
        ]);
      }
    }

    return $proposed_shipments;
  }
}
