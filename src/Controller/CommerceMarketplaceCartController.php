<?php

namespace Drupal\commerce_marketplace_order\Controller;

use Drupal\commerce_cart\CartProviderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\commerce_cart\Controller\CartController;

/**
 * Overrides the cart page controller.
 */
class CommerceMarketplaceCartController extends CartController {

  /**
   * The cart provider.
   *
   * @var \Drupal\commerce_cart\CartProviderInterface
   */
  protected $cartProvider;

  /**
   * Constructs a new CartController object.
   *
   * @param \Drupal\commerce_cart\CartProviderInterface $cart_provider
   *   The cart provider.
   */
  public function __construct(CartProviderInterface $cart_provider) {
    $this->cartProvider = $cart_provider;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('commerce_cart.cart_provider')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function cartPage() {
    $build = parent::cartPage();
    return $build;
  }

}
