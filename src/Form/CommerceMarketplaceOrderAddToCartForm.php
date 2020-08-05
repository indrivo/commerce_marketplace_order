<?php

namespace Drupal\commerce_marketplace_order\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_marketplace\Form\MarketplaceAddToCartForm;

/**
 * Overrides commerce add to cart form.
 */
class CommerceMarketplaceOrderAddToCartForm extends MarketplaceAddToCartForm {

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Remove button and internal Form API values from submitted values.
    $form_state->cleanValues();
    $this->entity = $this->buildEntity($form, $form_state);
    // Update the changed timestamp of the entity.
    $this->updateChangedTime($this->entity);

    /** @var \Drupal\commerce_order\Entity\OrderItemInterface $order_item */
    $order_item = $this->entity;
    $entity_manager = \Drupal::entityManager();
    $store = $entity_manager->getStorage('commerce_store')->loadDefault();
    // Statically and directly set the order type.
    $order_type_id = MARKETPLACE_ORDER_TYPE;

    $cart = $this->cartProvider->getCart($order_type_id, $store);
    if (!$cart) {
      $cart = $this->cartProvider->createCart($order_type_id, $store);
    }
    $this->entity = $this->cartManager->addOrderItem($cart, $order_item, $form_state->get(['settings', 'combine']));
    // Other submit handlers might need the cart ID.
    $form_state->set('cart_id', $cart->id());
  }

}
