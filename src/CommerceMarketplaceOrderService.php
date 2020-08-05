<?php

namespace Drupal\commerce_marketplace_order;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\profile\Entity\ProfileInterface;
use Drupal\commerce\Context;
use Drupal\commerce\PurchasableEntityInterface;
use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_order\Resolver\OrderTypeResolverInterface;
use Drupal\commerce_price\Resolver\ChainPriceResolverInterface;
use Drupal\commerce_store\CurrentStoreInterface;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Commerce Marketplace Order Service.
 */
class CommerceMarketplaceOrderService {

  /**
   * The cart manager.
   *
   * @var \Drupal\commerce_cart\CartManagerInterface
   */
  public $cartManager;

  /**
   * The cart provider.
   *
   * @var \Drupal\commerce_cart\CartProviderInterface
   */
  public $cartProvider;

  /**
   * The order type resolver.
   *
   * @var \Drupal\commerce_order\Resolver\OrderTypeResolverInterface
   */
  public $orderTypeResolver;

  /**
   * The current store.
   *
   * @var \Drupal\commerce_store\CurrentStoreInterface
   */
  public $currentStore;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  public $currentUser;

  /**
   * The entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  public $entityManager;

  /**
   * The order storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  public $orderStorage;

  /**
   * The chain price resolver.
   *
   * @var \Drupal\commerce_price\Resolver\ChainPriceResolverInterface
   */
  protected $chainPriceResolver;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new CmpOrderService object.
   *
   * @param \Drupal\commerce_cart\CartManagerInterface $cart_manager
   *   The cart manager.
   * @param \Drupal\commerce_cart\CartProviderInterface $cart_provider
   *   The cart provider.
   * @param \Drupal\commerce_order\Resolver\OrderTypeResolverInterface $order_type_resolver
   *   The order type resolver.
   * @param \Drupal\commerce_store\CurrentStoreInterface $current_store
   *   The current store.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager.
   * @param \Drupal\commerce_price\Resolver\ChainPriceResolverInterface $chain_price_resolver
   *   The chain price resolver.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(
    CartManagerInterface $cart_manager,
    CartProviderInterface $cart_provider,
    OrderTypeResolverInterface $order_type_resolver,
    CurrentStoreInterface $current_store,
    AccountInterface $current_user,
    EntityManagerInterface $entity_manager,
    ChainPriceResolverInterface $chain_price_resolver,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    $this->cartManager = $cart_manager;
    $this->cartProvider = $cart_provider;
    $this->orderTypeResolver = $order_type_resolver;
    $this->currentStore = $current_store;
    $this->currentUser = $current_user;
    $this->entityManager = $entity_manager;
    $this->orderStorage = $this->entityManager->getStorage('commerce_order');
    $this->chainPriceResolver = $chain_price_resolver;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Create sub-orders for commerce_marketplace_order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Order Interface object.
   *
   * @throws \Exception
   *   When something is wrong.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface[]
   *   Returns the commerce marketplace sub-orders.
   */
  public function createCommerceMarketplaceSubOders(OrderInterface $order) {
    try {
      $cmp_suborders = [];
      $mp_order_id = $order->id();
      // Initial creation of sub-orders occurs ONCE, when a high-level order
      // becomes “COMPLETED” and no sub-orders are created.
      $if_suborders_exist = $this->orderStorage->loadByProperties(['field_mp_order_reference' => $mp_order_id]);
      if ($if_suborders_exist) {
        return $cmp_suborders;
      }

      $order_state = $order->state->value;
      $billing_profile = $order->getBillingProfile();
      $items_by_store = $this->itemsByStore($order);
      foreach ($items_by_store as $store_name => $store_items) {
        $order_type_id = $store_items['order_type_id'];
        $order_items = $store_items['order_items'];
        $store = $store_items['store'];

        // Create the new cart order.
        $store = $store ?: $this->orderStorage->getStore();
        $uid = $this->currentUser->id();
        $store_id = $store->id();
        /** @var \Drupal\commerce_order\Entity\OrderInterface $cart */
        $cart = $this->orderStorage->create([
          'type' => $order_type_id,
          'store_id' => $store_id,
          'uid' => $uid,
          'cart' => TRUE,
        ]);
        // An exception.
        if (!$cart->hasField('field_mp_order_reference')) {
          throw new \Exception("The suborder object does not have a 'field_mp_order_reference' field.");
        }
        $cart->save();

        $time = $cart->getCalculationDate()->format('U');
        $context = new Context($cart->getCustomer(), $cart->getStore(), $time);

        // Add duplicate order items to sub-order.
        $duplicate_order_items = [];
        foreach ($order_items as $order_item) {
          $purchased_entity = $order_item->getPurchasedEntity();
          $quantity = $order_item->getQuantity();
          // Duplicate the order item from "cmp order".
          $order_item_duplicate = $this->cartManager->createOrderItem($purchased_entity, $quantity);
          if (!$order_item_duplicate->isUnitPriceOverridden()) {
            $unit_price = $this->chainPriceResolver->resolve($purchased_entity, $quantity, $context);
            $order_item_duplicate->setUnitPrice($unit_price);
          }
          $order_item_duplicate->set('order_id', $cart->id());
          $order_item_duplicate->save();
          $duplicate_order_items[] = $order_item_duplicate;
        }
        $cart->setItems($duplicate_order_items);

        // Set up a billing profile for an sub-order.
        if ($billing_profile instanceof ProfileInterface) {
          $cart->setBillingProfile($billing_profile);
        }
        // Change sub-order state.
        $cart->set('state', $order_state);
        // Save on suborder reference to high-level order.
        $cart->set('field_mp_order_reference', $mp_order_id);
        // Copy shippments
        $shipments = [];
        foreach ($order->get('shipments')->referencedEntities() as $shipment) {
          if ($shipment->getData('store_id') == $store_id) {
            $cloned_shipment = $shipment->createDuplicate();
            $cloned_shipment->set('order_id', $cart->id());
            $cloned_shipment->save();
            $shipments[] = $cloned_shipment;
          }
        }
        if ($shipments) {
          $cart->shipments = $shipments;
          // Trigger an order refresh so that the shipping adjustment gets adjusted.
          $cart->setRefreshState(OrderInterface::REFRESH_ON_SAVE);
        }
        // Set placed time.
        $cart->setPlacedTime($order->getPlacedTime());
        // Generate an order number.
        $this->setSuborderNumber($cart);
        // Finalize cart and save changes.
        $this->cartProvider->finalizeCart($cart);
        // Collect the created sub-orders.
        $cmp_suborders[$store_name] = $cart;
      }

      return $cmp_suborders;
    }
    catch (\Exception $e) {
      throw new \Exception($e->getMessage());
    }
  }

  /**
   * Arrange order items by store name.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Order Interface object.
   *
   * @return array
   *   Returns an array with order items ordered by store name.
   */
  public function itemsByStore(OrderInterface $order) {
    $items_reorder = [];
    $items = $order->getItems();
    foreach ($items as $item) {
      $purchased_entity = $item->getPurchasedEntity();
      $store = $this->selectStore($purchased_entity);
      $order_type_id = $this->orderTypeResolver->resolve($item);
      $items_reorder[$store->getName()]['store'] = $store;
      $items_reorder[$store->getName()]['order_type_id'] = $order_type_id;
      $items_reorder[$store->getName()]['order_items'][] = $item;
    }
    return $items_reorder;
  }

  /**
   * Selects the store for the given purchasable entity.
   *
   * If the entity is sold from one store, then that store is selected.
   * If the entity is sold from multiple stores, and the current store is
   * one of them, then that store is selected.
   *
   * @param \Drupal\commerce\PurchasableEntityInterface $entity
   *   The entity being added to cart.
   *
   * @throws \Exception
   *   When the entity can't be purchased from the current store.
   *
   * @return \Drupal\commerce_store\Entity\StoreInterface
   *   The selected store.
   */
  public function selectStore(PurchasableEntityInterface $entity) {
    $stores = $entity->getStores();
    if (count($stores) === 1) {
      $store = reset($stores);
    }
    elseif (count($stores) === 0) {
      // Malformed entity.
      throw new \Exception('The given entity is not assigned to any store.');
    }
    else {
      $store = $this->currentStore->getStore();
      if (!in_array($store, $stores)) {
        // Indicates that the site listings are not filtered properly.
        throw new \Exception("The given entity can't be purchased from the current store.");
      }
    }

    return $store;
  }

  /**
   * Set order number on suborders.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The Order Interface object.
   */
  public function setSuborderNumber(OrderInterface $order) {
    if (!$order->getOrderNumber()) {
      $order_type_storage = $this->entityTypeManager->getStorage('commerce_order_type');
      /** @var \Drupal\commerce_order\Entity\OrderTypeInterface $order_type */
      $order_type = $order_type_storage->load($order->bundle());
      /** @var \Drupal\commerce_number_pattern\Entity\NumberPatternInterface $number_pattern */
      $number_pattern = $order_type->getNumberPattern();
      if ($number_pattern) {
        $order_number = $number_pattern->getPlugin()->generate($order);
      }
      else {
        $order_number = $order->id();
      }

      $order->setOrderNumber($order_number);
    }
  }
}
