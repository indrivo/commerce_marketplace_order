<?php

namespace Drupal\commerce_marketplace_order\Plugin\Commerce\CheckoutPane;

use Drupal\commerce\InlineFormManager;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneInterface;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\Core\Entity\EntityTypeBundleInfo;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * A pane that will process individual user data.
 *
 * @CommerceCheckoutPane(
 *  id = "account_information",
 *  label = @Translation("Account Information"),
 *  admin_label = @Translation("Account Information"),
 *  default_step = "order_information",
 *  wrapper_element = "fieldset",
 * )
 */
class AccountInformation extends CheckoutPaneBase implements CheckoutPaneInterface {

  /**
   * The entity type bundle info.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfo
   */
  protected $entityTypeBundleInfo;

  /**
   * The inline form manager.
   *
   * @var \Drupal\commerce\InlineFormManager
   */
  protected $inlineFormManager;

  /**
   * The packer manager.
   *
   * @var \Drupal\commerce_shipping\PackerManagerInterface
   */
  protected $packerManager;

  /**
   * The entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  public $currentUser;

  /**
   * The Profile storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  public $profileStorage;

  /**
   * Constructs a new ShippingInformation object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface $checkout_flow
   *   The parent checkout flow.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfo $entity_type_bundle_info
   *   The entity type bundle info.
   * @param \Drupal\commerce\InlineFormManager $inline_form_manager
   *   The inline form manager.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    CheckoutFlowInterface $checkout_flow,
    EntityTypeManagerInterface $entity_type_manager,
    EntityTypeBundleInfo $entity_type_bundle_info,
    InlineFormManager $inline_form_manager,
    AccountInterface $current_user
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $checkout_flow, $entity_type_manager);
    $this->currentUser = $current_user;

    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->inlineFormManager = $inline_form_manager;
    $this->profileStorage = $this->entityTypeManager->getStorage('profile');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, CheckoutFlowInterface $checkout_flow = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $checkout_flow,
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('plugin.manager.commerce_inline_form'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'require_individual_profile' => TRUE,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationSummary() {
    if (!empty($this->configuration['require_individual_profile'])) {
      $summary = $this->t('Display individual profile form: Yes');
    }
    else {
      $summary = $this->t('Display individual profile form: No');
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['require_individual_profile'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Display individual profile form'),
      '#default_value' => $this->configuration['require_individual_profile'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['require_individual_profile'] = !empty($values['require_individual_profile']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isVisible() {
    return $this->configuration['require_individual_profile'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneSummary() {
    $individual_profile = $this->profileStorage->loadByProperties([
      'type' => 'individual',
      'uid' => $this->currentUser->id(),
    ]);
    if ($individual_profile) {
      $individual_profile = reset($individual_profile);
      // Only the individual information was collected.
      $view_builder = $this->entityTypeManager->getViewBuilder('profile');
      $summary = [
        '#title' => $this->t('Account Information'),
        'profile' => $view_builder->view($individual_profile, 'default'),
      ];
      return $summary;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form) {
    $individual_profile = !$this->currentUser->id() ? NULL : $this->profileStorage->loadByProperties([
      'type' => 'individual',
      'uid' => $this->currentUser->id(),
    ]);

    if ($individual_profile) {
      $individual_profile = reset($individual_profile);
    }
    else {
      $individual_profile = $this->profileStorage->create([
        'type' => 'individual',
        'label' => $this->currentUser->getUsername(),
        'uid' => $this->currentUser->id(),
      ]);
    }

    /** @var \Drupal\commerce\Plugin\Commerce\InlineForm\EntityInlineFormInterface $inline_form */
    $inline_form = $this->inlineFormManager->createInstance('customer_profile', [
      'profile_scope' => 'account_information',
      'available_countries' => $this->order->getStore()->getBillingCountries(),
      'address_book_uid' => $this->order->getCustomerId(),
      // Don't copy the profile to address book until the order is placed.
      'copy_on_save' => FALSE,
    ], $individual_profile);
    $pane_form['individual_profile'] = [
      '#parents' => array_merge($pane_form['#parents'], ['individual_profile']),
      '#inline_form' => $inline_form,
    ];
    $build_inline_form = $inline_form->buildInlineForm($pane_form['individual_profile'], $form_state);
    $pane_form['#title'] = $this->t('Account Information');
    $pane_form['individual_profile'] = $build_inline_form;

    return $pane_form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form) {
    /** @var \Drupal\commerce\Plugin\Commerce\InlineForm\EntityInlineFormInterface $inline_form */
    $inline_form = $pane_form['individual_profile']['#inline_form'];
    /** @var \Drupal\profile\Entity\ProfileInterface $profile */
    $profile = $inline_form->getEntity();
    if (!$profile->isActive()) {
      $profile->setActive(TRUE);
    }
    if (!$profile->isPublished()) {
      $profile->setPublished(TRUE);
    }
    $profile->save();
  }

}
