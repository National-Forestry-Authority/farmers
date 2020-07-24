<?php

namespace Drupal\nfafmis\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\nfafmis\Services\FarmerServices;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class NfafmisSettingsForm.
 */
class NfafmisSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'nfafmis_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'nfafmis.settings',
    ];
  }

  /**
   * Drupal\Core\Config\ConfigFactoryInterface definition.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  /**
   * Drupal\Core\Entity\EntityTypeManager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * Drupal\nfafmis\Services\FarmerServices.
   *
   * @var \Drupal\nfafmis\Services\FarmerServices
   */
  protected $farmerService;

  /**
   * Class constructor.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManager $entity_type_manager,
    FarmerServices $farmer_service) {
    $this->config = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->farmerService = $farmer_service;

    // Year for which annual charges has to be calculated.
    $this->prev_year = date("Y") - 1;
    $this->year = date("Y");
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('nfafmis_service.farmer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $config = $this->config('nfafmis.settings');

    $form['item-check'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Annual charges date & late fees settings'),
      '#description' => $this->t('Add a date on which annual charges calculation will be happen for each areas against each farmer applying the late fees.'),
    ];
    $form['item-check']['anual_charge_date'] = [
      '#type' => 'date',
      '#required' => TRUE,
      '#title' => $this->t('Date'),
      '#default_value' => $config->get('anual_charge_date'),
    ];
    $form['item-check']['late_fees'] = [
      '#type' => 'textfield',
      '#required' => TRUE,
      '#size' => '12',
      '#field_suffix' => '%',
      '#title' => $this->t('Late fees'),
      '#default_value' => $config->get('late_fees'),
    ];
    $form['item-charges'] = [
      '#type' => 'fieldset',
      '#description' => $this->t('By clicking the button you can generate annual charges for the year %year.  note annual charges will be calculated for the last year only.', ['%year' => $this->year]),
    ];
    $form['item-charges']['calculate_annual_charges'] = [
      '#type' => 'submit',
      '#button_type' => 'primary',
      '#value' => $this->t('Calculate annual charges for year %year', ['%year' => $this->prev_year]),
      '#submit' => ['::calculateAnnualChargesHandler'],
    ];
    $form['item-charges']['calculate_annual_charges_1'] = [
      '#type' => 'submit',
      '#button_type' => 'primary',
      '#value' => $this->t('Calculate annual charges for year %year', ['%year' => $this->year]),
      '#submit' => ['::calculateAnnualChargesHandler'],
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $anual_charge_date = $form_state->getValue('anual_charge_date');
    $late_fees = $form_state->getValue('late_fees');

    // Retrieve the configuration.
    $this->config->getEditable('nfafmis.settings')
      // Set the submitted configuration setting.
      ->set('anual_charge_date', $anual_charge_date)
      ->set('late_fees', $late_fees)
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Custom submission handler for calculateAnnualChargesHandler.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function calculateAnnualChargesHandler(array &$form, FormStateInterface $form_state) {
    // Create node of annual charges programmatically for last year.
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $offer_license_ids = $query->condition('type', 'offer_license')
      ->condition('status', 1)
      ->execute();
    $areas_object = $this->entityTypeManager->getStorage('node')->loadMultiple($offer_license_ids);
    foreach ($areas_object as $area) {
      $area_allocated = $area->get('field_overall_area_allocated')->value;
      $cfr = $area->get('field_central_forest_reserve')->target_id;
      if ($cfr && $area_allocated) {
        $annual_charges = $this->farmerService->chekForExistingAnnualCharges($area, $this->year);
        // Prevent creating duplicate annual charges for the same year.
        if (empty($annual_charges)) {
          $this->createAnnualCharges($area, $cfr, $area_allocated);
        }
        else {
          $this->messenger()->addMessage($this->t('Annual charges already added against area :area for the year :year', [
            ':area' => $area->getTitle(),
            ':year' => $this->year,
          ]), 'warning');
        }
      }
    }
  }

  /**
   * Create annual charges for all area (offer license) lend by a farmer,
   * it consists two part.
   *
   * - Land rent late fee.
   * - Annual land rent.
   */
  public function createAnnualCharges($area, $cfr, $area_allocated) {
    $previous_year_land_rent = $this->farmerService->getPreviousYearLandRentDue($area, $this->year);
    if (!empty($previous_year_land_rent) && $previous_year_land_rent['charges_due']) {
      $config = $this->config('nfafmis.settings');
      $late_fees = $config->get('late_fees');
      // Create land rent late fee, this will only happen if there is annual
      // land rent unpaid for previous year.
      $node = $this->entityTypeManager->getStorage('node')->create([
        'type' => 'annual_charges',
        'field_annual_charges' => ($previous_year_land_rent['amount'] * $late_fees) / 100,
        'field_rate_year' => $this->year,
        'field_licence_id_ref' => $area,
        'field_arrears' => $previous_year_land_rent['amount'],
        'field_annual_charges_type' => '2',
      ]);
      $node->save();
      $this->messenger()->addMessage($this->t('Annual charges (late fee) added against area :area for the year :year', [
        ':area' => $area->getTitle(),
        ':year' => $this->year,
      ]));
    }
    // Create annual land rent, this will only happen if land_rent_rates is
    // already added against Central Forest Reserve ($cfr) added for the year
    // ($this->year).
    $annual_charges = $this->farmerService->calculateAnnualCharges($cfr, $area_allocated, $this->year);
    if ($annual_charges) {
      $node = $this->entityTypeManager->getStorage('node')->create([
        'type' => 'annual_charges',
        'field_annual_charges' => $annual_charges,
        'field_rate_year' => $this->year,
        'field_licence_id_ref' => $area,
        'field_annual_charges_type' => '1',
      ]);
      $node->save();
      $this->messenger()->addMessage($this->t('Annual charges (land rent) added against area :area for the year :year', [
        ':area' => $area->getTitle(),
        ':year' => $this->year,
      ]));
    }
  }

}
