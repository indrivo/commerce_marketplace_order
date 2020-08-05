<?php

namespace Drupal\commerce_marketplace_order\EventSubscriber;

use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Drupal\commerce_marketplace_order\CommerceMarketplaceOrderService;

/**
 * Commerce Marketplace Order event subscriber.
 */
class CommerceMarketplaceOrderSubscriber implements EventSubscriberInterface {

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The Commerce Marketplace Order Service.
   *
   * @var \Drupal\commerce_marketplace_order\CommerceMarketplaceOrderService
   */
  public $commerceMarketplaceOrderService;

  /**
   * Constructs event subscriber.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Drupal\commerce_marketplace_order\CommerceMarketplaceOrderService $commerce_marketplace_order_service
   *   The Commerce Marketplace Order Service.
   */
  public function __construct(MessengerInterface $messenger, CommerceMarketplaceOrderService $commerce_marketplace_order_service) {
    $this->messenger = $messenger;
    $this->commerceMarketplaceOrderService = $commerce_marketplace_order_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      'commerce_order.place.post_transition' => ['onPlaceTransition'],
    ];
  }

  /**
   * Сreates sub-orders at the moment when the main order is completed.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The transition event.
   */
  public function onPlaceTransition(WorkflowTransitionEvent $event) {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();
    if ($order->type->target_id == MARKETPLACE_ORDER_TYPE) {
      $cmp_suborders = $this->commerceMarketplaceOrderService->createCommerceMarketplaceSubOders($order);
    }
  }

}
