<?php

namespace Drupal\nfafmis\Services;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Render\Renderer;
use Drupal\Core\Session\AccountProxy;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class FarmerServices.
 *
 * @todo Fees and payments functionality has been deprecated. Much of the code
 * in this class is no longer used and should be removed.
 */
class FarmerServices {

  /**
   * Drupal\Core\Session\AccountProxy definition.
   *
   * @var \Drupal\Core\Session\AccountProxy
   */
  protected $currentUser;

  /**
   * Drupal\Core\Entity\EntityTypeManager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * Drupal\Core\Render\Renderer.
   *
   * @var \Drupal\Core\Render\Renderer
   */
  protected $renderer;

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * NFA Fee payments data.
   *
   * @var array
   */
  protected $nfaFeePayments;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs a new FarmerServices object.
   *
   * @param \Drupal\Core\Session\AccountProxy $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManager $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Render\Renderer $renderer
   *   The renderer.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(
    AccountProxy $current_user,
    EntityTypeManager $entity_type_manager,
    Renderer $renderer,
    FileUrlGeneratorInterface $file_url_generator,
    RequestStack $request_stack,
  ) {
    $this->currentUser = $current_user;
    $this->renderer = $renderer;
    $this->entityTypeManager = $entity_type_manager;
    $this->fileUrlGenerator = $file_url_generator;
    $this->nfaFeePayments = [];
    $this->requestStack = $request_stack;

  }

  /**
   * Update view field result with get_land_rent_and_other_data.
   *
   * @param string $farmer_id
   *   The farmer ID.
   * @param string $offer_licence_id
   *   The area ID.
   *
   * @return array|\Drupal\Component\Render\MarkupInterface|string
   *   The rendered array.
   *
   * @throws \Exception
   */
  public function getAreaLandRentAndFeesData(string $farmer_id, string $offer_licence_id) {
    $starting_amount = $this->getStartingAmountData($offer_licence_id);
    // Get fees & land rent data.
    $this->nfaFeePayments = $this->getFeePaymentsNFAData($offer_licence_id);
    $fees_data = $this->getFeesData($offer_licence_id);
    $land_rent_data = $this->getLandRentData($offer_licence_id);
    $historical_data = $this->getHistoricalData($offer_licence_id);
    $data = [
      'starting_amount' => $starting_amount,
      'farmer_id' => $farmer_id,
      'offer_licence_id' => $offer_licence_id,
      'fee_payments' => $this->nfaFeePayments,
      'fees' => $fees_data,
      'land_rent' => $land_rent_data,
      'historical' => $historical_data,
    ];
    $renderable = [
      '#theme' => 'tab__accounts__area__land_rent_fees_data',
      '#data' => $data,
    ];
    return $this->renderer->render($renderable);
  }

  /**
   * Get fees data for particular area.
   *
   * @param string $offer_licence_id
   *   The area ID.
   *
   * @return array
   *   The fee data.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getFeesData(string $offer_licence_id) {
    $fees_data = [
      'balance' => 0,
      'charges' => 0,
      'payments' => 0,
    ];
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $charge_nids = $query->condition('type', 'charge')
      ->condition('field_areas_id.target_id', $offer_licence_id)
      ->accessCheck()
      ->execute();
    if (!empty($charge_nids)) {
      $nids = array_values($charge_nids);
      $charges = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);

      foreach ($charges as $charge) {
        // Get invoice from charges.
        $invoice = $charge->get('field_payment_advice')->referencedEntities()[0];
        $invoice_has_payment = $this->invoiceHasPayment($invoice->id());
        if (!empty($invoice_has_payment)) {
          $fees_data['payments'] += $charge->get('field_amount')->value;
        }
        else {
          $fees_data['balance'] += $charge->get('field_amount')->value;
        }
        $fees_data['charges'] += $charge->get('field_amount')->value;
      }
    }
    // Format the data.
    $fees_data['balance'] = number_format($fees_data['balance'], 0, '.', ',');
    $fees_data['payments'] = number_format($fees_data['payments'], 0, '.', ',');
    $fees_data['charges'] = number_format($fees_data['charges'], 0, '.', ',');
    return $fees_data;
  }

  /**
   * Get NFA fee payments data for particular area.
   *
   * @param string $offer_licence_id
   *   The area ID.
   *
   * @return array
   *   The fee data.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getFeePaymentsNFAData(string $offer_licence_id) {
    $data_array = [];
    $total_paid = [];
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $payment_nids = $query->condition('type', 'fee_payment_nfa')
      ->condition('field_offer_id_ref.target_id', $offer_licence_id)
      ->accessCheck()
      ->execute();
    if (!empty($payment_nids)) {
      $area = $this->entityTypeManager->getStorage('node')->load($offer_licence_id);
      $nids = array_values($payment_nids);
      $payments = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
      foreach ($payments as $key => $payment) {
        foreach ($payment->get('copy_of_receipt') as $receipt) {
          $file = $receipt->entity;
          if ($file) {
            $file_uri = $file->getFileUri();
            $file_download_uri = $this->fileUrlGenerator->generateAbsoluteString($file_uri);
            $data_array[$key]['payment_receipt'] = $file_download_uri;
          }
        }
        $data_array[$key]['area_title'] = $area->getTitle();
        $data_array[$key]['payment_type'] = $payment->get('field_payment_type')->entity->getName();
        $data_array[$key]['nfa_receipt_date'] = $payment->get('field_date_of_nfa_receipt')->value;
        $data_array[$key]['nfa_receipt_number'] = $payment->get('field_nfa_receipt_number')->value;
        $data_array[$key]['ura_prn_date'] = $payment->get('field_ura_prn_date')->value;
        $data_array[$key]['ura_prn'] = $payment->get('field_ura_prn')->value;
        $data_array[$key]['payment_amount'] = $payment->get('field_payment_amount_new')->value;
        $data_array[$key]['nid'] = $payment->id();
        $total_paid[substr($payment->get('field_date_of_nfa_receipt')->value, 0, 4)] = $data_array[$key]['payment_amount'];
      }
    }

    return ['total_paid' => $total_paid, 'data' => $data_array];
  }

  /**
   * Get payment id against each invoice (Payment advice).
   *
   * @param string $invoice_id
   *   The invoice ID.
   *
   * @return array
   *   The array of payment id.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function invoiceHasPayment(string $invoice_id) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $payment_nid = $query->condition('type', 'payment')
      ->condition('field_invoice.target_id', $invoice_id)
      ->accessCheck()
      ->execute();
    return !empty($payment_nid) ? $payment_nid : [];
  }

  /**
   * Get land rent data for particular area.
   *
   * @param string $offer_licence_id
   *   The area ID.
   *
   * @return array
   *   The land rent data.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getLandRentData(string $offer_licence_id) {
    $land_rent_data = [
      'balance' => 0,
      'charges' => 0,
      'payments' => 0,
      'data' => [],
    ];
    $this->getLandRentStartingAmountData($offer_licence_id, $land_rent_data);
    $this->getLandRentAnnualChargesData($offer_licence_id, $land_rent_data);
    return $land_rent_data;
  }

  /**
   * Get historical payment information data for particular area.
   *
   * @param string $offer_licence_id
   *   The area ID.
   *
   * @return array
   *   The historical payment information data.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getHistoricalData(string $offer_licence_id) {
    $historical_data = [
      'data' => [],
    ];
    $this->getLandRentAnnualChargesHistoricalData($offer_licence_id, $historical_data);
    return $historical_data;
  }

  /**
   * Get land rent starting amount for particular area.
   *
   * @param string $offer_licence_id
   *   The area ID.
   * @param array $land_rent_data
   *   The $land_rent_data.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getLandRentStartingAmountData($offer_licence_id, &$land_rent_data) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $starting_amount_nids = $query->condition('type', 'starting_amount')
      ->condition('field_areas_id.target_id', $offer_licence_id)
      ->accessCheck()
      ->execute();
    $data_array = [];
    if (!empty($starting_amount_nids)) {
      $starting_amounts = $this->entityTypeManager->getStorage('node')->loadMultiple($starting_amount_nids);
      foreach ($starting_amounts as $starting_amount) {
        $amount = $starting_amount->get('field_amount')->value;
        $data_array += ['date' => 'Starting amount'];
        $data_array += ['nid' => $starting_amount->get('nid')->value];
        $land_rent_data['charges'] += $amount;
        $data_array += ['land_rent_due' => $amount];
        $data_array += ['previous_arrears' => 0];
        $data_array += ['late_fee_due' => 0];
        $data_array += ['total_due' => $amount];
        $invoice = $starting_amount->get('field_payment_advice')->referencedEntities()[0];
        // Get invoice for starting amount.
        if ($invoice) {
          $data_array += ['payment_advc_no' => $invoice->get('field_invoice_number')->value];
          $data_array += ['payment_advc_nid' => $invoice->id()];
          $invoice_payment_id = $this->invoiceHasPayment($invoice->id());
          // Get payment data for invoice.
          if (!empty($invoice_payment_id)) {
            $land_rent_data['payments'] += $amount;
            $payment_id = reset($invoice_payment_id);
            $payment = $this->entityTypeManager->getStorage('node')->load($payment_id);
            $data_array += ['payment_nid' => $payment->id()];
            $data_array += ['payment_date' => $payment->get('field_date_paid')->value];
            $data_array += ['receipt_number' => $payment->get('field_receipt_number')->value];

            // Get receipt file uri.
            $file = $payment->field_receipt_scan->entity;
            if ($file) {
              $file_uri = $payment->field_receipt_scan->entity->getFileUri();
              $file_download_uri = $this->fileUrlGenerator->generateAbsoluteString($file_uri);
              $data_array += ['receipt_uri' => $file_download_uri];
            }
          }
          else {
            $land_rent_data['balance'] += $amount;
          }
        }
      }
      $land_rent_data['data']['sa'] = $data_array;
    }
  }

  /**
   * Check for existing annual charges for the particular year and area.
   *
   * @param object $area
   *   The area object.
   * @param string $for_year
   *   The year.
   *
   * @return array|int
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function checkForExistingAnnualCharges($area, string $for_year) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $annual_charges_nid = $query->condition('type', 'annual_charges')
      ->condition('field_licence_id_ref.target_id', $area->id())
      ->condition('field_rate_year.value', $for_year)
      ->condition('field_annual_charges_type', '1')
      ->accessCheck()
      ->execute();
    return $annual_charges_nid ?? [];
  }

  /**
   * Get land rent annual charges for particular area of last two years.
   *
   * @param string $offer_licence_id
   *   The area ID.
   * @param array $land_rent_data
   *   The $land_rent_data.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getLandRentAnnualChargesData(string $offer_licence_id, array &$land_rent_data) {
    $year = date("Y");
    //$archiveYear = $year - 2;
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $annual_charges_nids = $query->condition('type', 'annual_charges')
      ->condition('field_licence_id_ref.target_id', $offer_licence_id)
      //->condition('field_rate_year.value', $archiveYear, '>')
      ->accessCheck()
      ->execute();
    $data_array = [];
    if (!empty($annual_charges_nids)) {
      $annual_charges = $this->entityTypeManager->getStorage('node')->loadMultiple($annual_charges_nids);
      foreach ($annual_charges as $key => $annual_charge) {
        $amount = $annual_charge->get('field_annual_charges')->value;
        $year = $annual_charge->get('field_rate_year')->value;
        $arrears = $annual_charge->get('field_arrears')->value;
        $charge_type = $annual_charge->get('field_annual_charges_type')->value;
        $land_rent_data['charges'] += $amount;
        $data_array[$key]['date'] = $year;
        $data_array[$key]['charge_type'] = $charge_type;
        $data_array[$key]['total_area'] = $annual_charge->get('field_overall_area')->value;
        if ($charge_type == '1') {
          $data_array[$key]['land_rent_due'] = $amount;
          $data_array[$key]['late_fee_due'] = 0;
          if (isset($this->nfaFeePayments['total_paid'][$year])) {
            $data_array[$key]['total_paid'] = $this->nfaFeePayments['total_paid'][$year];
          }
        }
        else {
          $data_array[$key]['land_rent_due'] = 0;
          $data_array[$key]['late_fee_due'] = $amount;
        }
        $data_array[$key]['previous_arrears'] = $arrears;
        // Cumulative amount outstanding year by year.
        $data_array[$key]['total_due'] = $land_rent_data['balance'] + $amount;
        if (isset($data_array[$key]['total_paid'])) {
          $data_array[$key]['total_due'] -= $data_array[$key]['total_paid'];
        }

        $invoice = $annual_charge->get('field_payment_advice')->referencedEntities()[0];
        // Get invoice for annual charges.
        if ($invoice) {
          $data_array[$key]['payment_advc_no'] = $invoice->get('field_invoice_number')->value;
          $data_array[$key]['payment_advc_nid'] = $invoice->id();
          $invoice_payment_id = $this->invoiceHasPayment($invoice->id());
          // Get payment data for annual charges.
          if (!empty($invoice_payment_id)) {
            $land_rent_data['payments'] += $amount;
            $payment_id = reset($invoice_payment_id);
            $payment = $this->entityTypeManager->getStorage('node')->load($payment_id);
            $data_array[$key]['payment_nid'] = $payment->id();
            $data_array[$key]['payment_date'] = $payment->get('field_date_paid')->value;
            $data_array[$key]['receipt_number'] = $payment->get('field_receipt_number')->value;

            // Get receipt file url.
            $file = $payment->field_receipt_scan->entity;
            if ($file) {
              $file_uri = $payment->field_receipt_scan->entity->getFileUri();
              $file_download_uri = $this->fileUrlGenerator->generateAbsoluteString($file_uri);
              $data_array[$key]['receipt_uri'] = $file_download_uri;
            }
          }
          else {
            $land_rent_data['balance'] += $amount;
          }
        }
      }
      // Sort data according to the date.
      if (!empty($data_array)) {
        krsort($data_array);
      }
      $land_rent_data['data'] = array_merge($data_array, $land_rent_data['data']);
    }
  }

  /**
   * Get historical payment information for particular area.
   *
   * @param string $offer_licence_id
   *   The area ID.
   * @param array $historical_data
   *   The historical payment information.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getLandRentAnnualChargesHistoricalData(string $offer_licence_id, array &$historical_data) {
    $year = date("Y");
    $archiveYear = $year - 2;
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $annual_charges_nids = $query->condition('type', 'annual_charges')
      ->condition('field_licence_id_ref.target_id', $offer_licence_id)
      ->condition('field_rate_year.value', $archiveYear, '<=')
      ->accessCheck()
      ->execute();
    $data_array = [];
    if (!empty($annual_charges_nids)) {
      $annual_charges = $this->entityTypeManager->getStorage('node')->loadMultiple($annual_charges_nids);
      foreach ($annual_charges as $key => $annual_charge) {
        $amount = $annual_charge->get('field_annual_charges')->value;
        $year = $annual_charge->get('field_rate_year')->value;
        $arrears = $annual_charge->get('field_arrears')->value;
        $data_array[$key]['nid'] = $annual_charge->id();
        $data_array[$key]['date'] = '01-01-' . $year;
        $data_array[$key]['amount_chargeable'] = $amount;
        $data_array[$key]['previous_arrears'] = $arrears;
        $data_array[$key]['description'] = t('Land rent for whole area');
        $invoice = $annual_charge->get('field_payment_advice')->referencedEntities()[0];
        // Get invoice for annual charges.
        if ($invoice) {
          $data_array[$key]['payment_advc_no'] = $invoice->get('field_invoice_number')->value;
          $data_array[$key]['payment_advc_nid'] = $invoice->id();
          $invoice_payment_id = $this->invoiceHasPayment($invoice->id());
          // Get payment data for annual charges.
          if (!empty($invoice_payment_id)) {
            $data_array[$key]['amount_paid'] = $amount;
            $payment_id = reset($invoice_payment_id);
          }
          else {
            $data_array[$key]['amount_paid'] = 0;
            $data_array[$key]['total_due'] = $amount;
          }
        }
      }
    }

    // Combine Historical payments information with annual historical charges.
    // Fetch data from historical_payments content type and merge it.
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $historical_payments_nid = $query->condition('type', 'historical_payments')
      ->condition('field_areas_id.target_id', $offer_licence_id)
      ->sort('field_charge_date.value', 'DESC')
      ->accessCheck()
      ->execute();
    if (!empty($historical_payments_nid)) {
      $historical_payments = $this->entityTypeManager->getStorage('node')->loadMultiple($historical_payments_nid);
      foreach ($historical_payments as $key => $historical_payment) {
        $amount = $historical_payment->get('field_charge_amount')->value;
        $date = $historical_payment->get('field_charge_date')->value;
        $body = $historical_payment->get('field_description')->value;
        $arrears = $historical_payment->get('field_arrears')->value;
        $amount_paid = $historical_payment->get('field_amount_paid_as')->value;
        $field_balance = $historical_payment->get('field_balance')->value;
        $key = $date . '_' . $key;

        $data_array[$key]['nid'] = $historical_payment->id();
        $data_array[$key]['date'] = $date;
        $data_array[$key]['amount_chargeable'] = $amount;
        $data_array[$key]['previous_arrears'] = $arrears;
        $data_array[$key]['description'] = $body;
        $data_array[$key]['amount_paid'] = $amount_paid;
        $data_array[$key]['total_due'] = $field_balance;
      }
    }
    // Sort data according to the date.
    if (!empty($data_array)) {
      krsort($data_array);
      $historical_data['data'] = $data_array;
    }
  }

  /**
   * Get Starting amount data for particular area.
   *
   * @param $offer_licence_id
   *
   * @return array
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getStartingAmountData($offer_licence_id) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $starting_amount_nids = $query->condition('type', 'starting_amount')
      ->condition('field_areas_id.target_id', $offer_licence_id)
      ->accessCheck()
      ->execute();
    $starting_amount_data = [];
    if (!empty($starting_amount_nids)) {
      $starting_amount_nid = reset($starting_amount_nids);
      $starting_amount = $this->entityTypeManager->getStorage('node')->load($starting_amount_nid);
      $starting_amount_data['nid'] = $starting_amount->get('nid')->value;
      $starting_amount_data['amount'] = $starting_amount->get('field_amount')->value;
    }
    return $starting_amount_data;
  }

  /**
   * Calculate annual charges for area.
   *
   * @param string $cfr
   *   The central forest reserve ID.
   * @param int $overall_area_allocated
   *   The overall unit allocated for the area.
   * @param string $for_year
   *   Annual charges for the year.
   *
   * @return float|int The annual land rent.
   *   The annual land rent.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function calculateAnnualCharges(string $cfr, int $overall_area_allocated, string $for_year) {
    // field_central_forest_reserve taxonomy term id.
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $query->condition('type', 'land_rent_rates');
    $query->condition('field_central_forest_reserve.target_id', $cfr);
    $query->condition('field_rate_year.value', $for_year);
    $query->accessCheck();

    $land_rent_rates_nid = $query->execute();

    if (!empty($land_rent_rates_nid)) {
      $nid = reset($land_rent_rates_nid);
      $land_rent_rate_node = $this->entityTypeManager->getStorage('node')->load($nid);
      // Make sure overall area is not zero.
      // land_rent_rate x overall_area_allocated.
      $rent_rate = $land_rent_rate_node->get('field_rate')->value;
      return $rent_rate * $overall_area_allocated;
    }
    return 0;
  }

  /**
   * Get previous year unpaid land rent for particular area.
   *
   * @param object $area
   *   The area object.
   * @param $year
   *
   * @return array
   *   The land rent total.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getPreviousYearLandRentDue($area, $year) {
    $year = $year - 1;
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $annual_charges_nids = $query->condition('type', 'annual_charges')
      ->condition('field_licence_id_ref.target_id', $area->id())
      ->condition('field_rate_year.value', $year)
      ->condition('field_annual_charges_type', '1')
      ->accessCheck()
      ->execute();

    $land_rent_annual_charge = [];
    if (!empty($annual_charges_nids)) {
      $annual_charges_nid = reset($annual_charges_nids);
      $annual_charges = $this->entityTypeManager->getStorage('node')->load($annual_charges_nid);
      $land_rent_annual_charge['amount'] = $annual_charges->get('field_annual_charges')->value;
      $land_rent_annual_charge['charges_due'] = TRUE;
      $invoice = $annual_charges->get('field_payment_advice')->referencedEntities()[0];
      // Get invoice for annual charges.
      if ($invoice) {
        $invoice_payment_id = $this->invoiceHasPayment($invoice->id());
        // Get payment data for annual charges.
        if (!empty($invoice_payment_id)) {
          $land_rent_annual_charge['charges_due'] = FALSE;
        }
      }
    }
    return $land_rent_annual_charge;
  }

  /**
   * Get offer license IDs based on farmer id.
   *
   * @param string $offer_licence_ids
   *   The area ID.
   *
   * @return mixed
   *   The list of sub area ids.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getSubAreasIds(string $offer_licence_ids) {
    $offer_licence_ids = explode(',', $offer_licence_ids);
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $sub_area_nids = $query->condition('type', 'sub_area')
      ->condition('field_areas_id.target_id', $offer_licence_ids, 'IN')
      ->accessCheck()
      ->execute();
    if (!empty($sub_area_nids)) {
      $sub_area_nids = array_values($sub_area_nids);
      return implode(',', $sub_area_nids);
    }
    return NULL;
  }

  /**
   * Get total charges of area year wise.
   *
   * @param string $cfr
   *   The central forest reserve ID.
   * @param int $overall_area_allocated
   *   The overall area allocated for the area.
   *
   * @return array
   *   The charges total year wise.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getTotalYearWise(string $cfr, int $overall_area_allocated) {
    // field_central_forest_reserve taxonomy term id.
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $land_rent_rates_nid = $query->condition('type', 'land_rent_rates')
      ->condition('field_central_forest_reserve.target_id', $cfr)
      ->accessCheck()
      ->execute();
    $land_rent_rates_table = [];
    if (!empty($land_rent_rates_nid)) {
      $nids = array_values($land_rent_rates_nid);
      $land_rent_rates = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
      foreach ($land_rent_rates as $land_rent_rate) {
        // Make sure overall area is not zero.
        // land_rent_rate x overall_area_allocated.
        $rent_rate = $land_rent_rate->get('field_rate')->value;
        $for_year = $land_rent_rate->get('field_rate_year')->value;
        $land_rent = $rent_rate * $overall_area_allocated;
        if ($overall_area_allocated) {
          if (array_key_exists($for_year, $land_rent_rates_table)) {
            $land_rent_rates_table[$for_year] += $land_rent;
          }
          else {
            $land_rent_rates_table[$for_year] = $land_rent;
          }
        }
        else {
          if (array_key_exists($for_year, $land_rent_rates_table)) {
            $land_rent_rates_table[$for_year] += $land_rent;
          }
          else {
            $land_rent_rates_table[$for_year] = $land_rent;
          }
        }
      }
    }
    return $land_rent_rates_table;
  }

  /**
   * Get sub-total from charges.
   *
   * @param string $offer_licence_id
   *   The area ID.
   *
   * @return array
   *   The other charges total year wise.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getOtherTotalYearWise(string $offer_licence_id) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $charge_nids = $query->condition('type', 'charge')
      ->condition('field_areas_id.target_id', $offer_licence_id)
      ->accessCheck()
      ->execute();
    $field_amount = [];
    if (!empty($charge_nids)) {
      $nids = array_values($charge_nids);
      $charges = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);

      foreach ($charges as $key => $charge) {
        $field_charge_date = $charge->get('field_charge_date')->value;
        if ($field_charge_date) {
          $field_charge_date = explode('-', $field_charge_date);
          $key = $field_charge_date[0];
          if (array_key_exists($key, $field_amount)) {
            $field_amount[$key] += $charge->get('field_amount')->value;
          }
          else {
            $field_amount[$key] = $charge->get('field_amount')->value;
          }
        }
      }
    }
    return $field_amount;
  }

  /**
   * Get invoice details.
   *
   * @param string $field_invoice_id
   *   The invoice id.
   *
   * @return array
   *   The result array.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getInvoiceDetails(string $field_invoice_id) {
    $invoice = $this->entityTypeManager->getStorage('node')->load($field_invoice_id);
    $field_areas_id = $invoice->get('field_areas_id')->target_id;
    $field_invoice_for = $invoice->get('field_invoice_details')->value;
    $other_charges['field_invoice_details'] = $field_invoice_for;
    $other_charges = $this->getOtherCharges($field_areas_id, $field_invoice_for);
    return $other_charges;
  }

  /**
   * Get other charges or land rent amount for specific area.
   *
   * @param string $offer_licence_id
   *   The area id for which amount has to be fetched.
   * @param string $charge_type
   *   Indicate charges type other or land rent.
   * @param bool $unformatted
   *   Weather to return formated amount.
   *
   * @return array
   *   The detail array result.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getOtherCharges(string $offer_licence_id, string $charge_type, $unformatted = FALSE) {

    $area = $this->entityTypeManager->getStorage('node')->load($offer_licence_id);
    $charges_data['title'] = $area->getTitle();
    $charges_total = 0;
    $charges_data['data'] = [];

    // Charges type '1' represents other fees.
    if ($charge_type === '1') {
      $charges_data['other_fees'] = TRUE;
      $query = $this->entityTypeManager->getStorage('node')->getQuery();
      $charge_nids = $query->condition('type', 'charge')
        ->condition('field_areas_id.target_id', $offer_licence_id)
        ->accessCheck()
        ->execute();
      if (!empty($charge_nids)) {
        $nids = array_values($charge_nids);
        $charges = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);

        foreach ($charges as $charge) {
          $field_amount = $charge->get('field_amount')->value;
          $charges_total += $field_amount;
          $charges_data['data'][] = [
            'desc' => $charge->get('field_charge_description')->value,
            'date' => $charge->get('field_charge_date')->value,
            'amount' => number_format($field_amount, 0, '.', ','),
          ];
        }
      }
    }
    // Charges type '2' represents land rent.
    if ($charge_type === '2') {
      $charges_data['land_rent'] = TRUE;
      $charges_date = $this->getRentSubTotal($offer_licence_id);
      $charges_total = $charges_date['sub_total'];
    }

    if ($unformatted) {
      $charges_data['total'] = $charges_total;
    }
    else {
      $charges_data['total'] = number_format($charges_total, 0, '.', ',');
    }
    return $charges_data;
  }

  /**
   * Get offer license IDs based on farmer id.
   *
   * @param string $farmer_id
   *   The farmer ID.
   *
   * @return mixed
   *   The area ids.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getOfferLicenseIds(string $farmer_id) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $nids = $query->condition('type', 'offer_license')
      ->condition('field_farmer_name_ref.target_id', $farmer_id)
      ->accessCheck()
      ->execute();
    if (!empty($nids)) {
      $nids = array_values($nids);
      return implode(',', $nids);
    }
    return NULL;
  }

  /**
   * Get get_area_planted_un_planted_value value  based on area ID.
   *
   * @param string $offer_license_id
   *   The area ID.
   *
   * @return int
   *   The area planted un-planted count.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getAreaPlantedUnPlantedValue(string $offer_license_id) {
    // Get sub-area entity Ids based on area ID.
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $nids = $query->condition('type', 'sub_area')
      ->condition('field_areas_id.target_id', $offer_license_id)
      ->accessCheck()
      ->execute();
    if (!empty($nids)) {
      $nids = array_values($nids);
      $field_sub_area_planted = 0;
      $sub_areas = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
      foreach ($sub_areas as $area) {
        $field_sub_area_planted += $area->get('field_subarea_planted')->value;
      }
      return $field_sub_area_planted;
    }
    return 0;
  }

  /**
   * Update view field result with get_summary_charges_data.
   *
   * @param string $farmer_id
   *   The farmer ID.
   *
   * @return array|\Drupal\Component\Render\MarkupInterface|string
   *   The rendered summary charges.
   *
   * @throws \Exception
   */
  public function getSummaryChargesData(string $farmer_id) {
    $summary_charges['balance'] = [
      'overall' => 0,
      'land_rent' => 0,
      'fees' => 0,
    ];
    $summary_charges['fees'] = [
      'charges' => 0,
    ];
    $summary_charges['land_rent'] = [
      'charges' => 0,
      'late_fee' => 0,
      'due' => 0,
    ];
    $area_ids = $this->getFarmerAreaIds($farmer_id);
    if (!empty($area_ids)) {
      // Get overall outstanding fees & data.
      $this->getOverallOutstandingFees($area_ids, $summary_charges);
      // Get overall outstanding Land rent along with starting amount.
      $this->getOverallOutstandingLandRent($area_ids, $summary_charges);
    }

    $data = [
      'farmer_id' => $farmer_id,
      'summary_charges' => $summary_charges,
    ];
    $renderable = [
      '#theme' => 'tab__accounts__summary_charges_data',
      '#data' => $data,
    ];
    return $this->renderer->render($renderable);;
  }

  /**
   * Get overall outstanding fee for all area of particular farmer.
   *
   * @param array $area_ids
   *   The area ID.
   * @param array $summary_charges
   *   The summary_charges array.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getOverallOutstandingFees(array $area_ids, array &$summary_charges) {
    // Calculate outstanding fees.
    $invoice_nids = $this->getInvoiceIds($area_ids);
    foreach ($invoice_nids as $invoice_id) {
      $payment = $this->invoiceHasPayment($invoice_id);
      if (empty($payment)) {
        $invoice = $this->entityTypeManager->getStorage('node')->load($invoice_id);
        $amount = $invoice->get('field_amount')->value;
        $summary_charges['fees']['charges'] += $amount;
        $summary_charges['balance']['overall'] += $amount;
        $summary_charges['balance']['fees'] += $amount;

        // Build data array.
        $data_array = [];
        $area = $invoice->get('field_areas_id')->referencedEntities()[0];
        if (!empty($area)) {
          $data_array['area'] = $area->getTitle();
        }
        $charge = $this->getChargeFromInvoice($invoice_id);
        if (!empty($charge)) {
          $data_array['date'] = $charge->get('field_charge_date')->value;
          $data_array['desc'] = $charge->get('field_charge_description')->value;
          $data_array['total_due'] = $charge->get('field_amount')->value;
          $data_array['payment_advc_no'] = $invoice->get('field_invoice_number')->value;
          $data_array['payment_advc_nid'] = $invoice->id();
        }
        $summary_charges['fees']['data'][] = $data_array;
      }
    }
    if (!empty($summary_charges['fees']['data'])) {
      krsort($summary_charges['fees']['data']);
    }
  }

  /**
   * Get charge for particular invoice.
   *
   * @param string $invoice_id
   *   The invoice ID.
   *
   * @return \Drupal\Core\Entity\EntityInterface|array
   *   The charge object.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getChargeFromInvoice(string $invoice_id) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $charge_nids = $query->condition('type', 'charge')
      ->condition('field_payment_advice.target_id', $invoice_id)
      ->accessCheck()
      ->execute();
    $charge_nid = reset($charge_nids);
    if (!empty($charge_nid)) {
      return $this->entityTypeManager->getStorage('node')->load($charge_nid);
    }
    return [];
  }

  /**
   * Get annual charges for particular invoice.
   *
   * @param string $invoice_id
   *   The invoice ID.
   *
   * @return \Drupal\Core\Entity\EntityInterface|array
   *   The charge object.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getAnnualChargeFromInvoice(string $invoice_id) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $annual_charges_nids = $query->condition('type', 'annual_charges')
      ->condition('field_payment_advice.target_id', $invoice_id)
      ->accessCheck()
      ->execute();
    $annual_charges_nid = reset($annual_charges_nids);
    if (!empty($annual_charges_nid)) {
      return $this->entityTypeManager->getStorage('node')->load($annual_charges_nid);
    }
    return [];
  }

  /**
   * Get overall outstanding land rent for all area of particular farmer.
   *
   * @param array $area_ids
   *   The area ID.
   * @param array $summary_charges
   *   The summary_charges array.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getOverallOutstandingLandRent(array $area_ids, array &$summary_charges) {
    // Calculate outstanding land rent.
    $invoice_nids = $this->getInvoiceIds($area_ids, '2');
    foreach ($invoice_nids as $invoice_id) {
      $payment = $this->invoiceHasPayment($invoice_id);
      if (empty($payment)) {
        $invoice = $this->entityTypeManager->getStorage('node')->load($invoice_id);
        $amount = $invoice->get('field_amount')->value;
        $summary_charges['land_rent']['charges'] += $amount;
        $summary_charges['balance']['overall'] += $amount;
        $summary_charges['balance']['land_rent'] += $amount;

        // Calculate land rent late_fee/due.
        $annual_charges = $this->getAnnualChargeFromInvoice($invoice_id);
        $data_array = [];
        // Build data array.
        if ($annual_charges) {
          $charge_type = $annual_charges->get('field_annual_charges_type')->value;
          if ($charge_type == '1') {
            $summary_charges['land_rent']['due'] += $amount;
            $data_array['due'] = $amount;
          }
          else {
            $summary_charges['land_rent']['late_fee'] += $amount;
            $data_array['late_fee_due'] = $amount;
            $data_array['previous_arrears'] = $annual_charges->get('field_arrears')->value;
          }
          $year = $annual_charges->get('field_rate_year')->value;
          $data_array['date'] = '01-01-' . $year;
          $data_array['total_due'] = $amount;
          $data_array['payment_advc_no'] = $invoice->get('field_invoice_number')->value;
          $data_array['payment_advc_nid'] = $invoice->id();
        }
        $area = $invoice->get('field_areas_id')->referencedEntities()[0];
        if (!empty($area)) {
          $data_array['area'] = $area->getTitle();
        }
        $summary_charges['land_rent']['data'][] = $data_array;
      }
    }
    if (isset($summary_charges['land_rent']['data'])) {
      krsort($summary_charges['land_rent']['data']);
    }

    // Calculate outstanding starting amount as part of land rent.
    $invoice_nids = $this->getInvoiceIds($area_ids, '3');
    foreach ($invoice_nids as $invoice_id) {
      $payment = $this->invoiceHasPayment($invoice_id);
      if (empty($payment)) {
        $invoice = $this->entityTypeManager->getStorage('node')->load($invoice_id);
        $amount = $invoice->get('field_amount')->value;
        $summary_charges['land_rent']['charges'] += $amount;
        $summary_charges['balance']['overall'] += $amount;
        $summary_charges['balance']['land_rent'] += $amount;
      }
    }
  }

  /**
   * Get all Invoice of particular type.
   *
   * @param array $area_ids
   *   The area ids.
   * @param string $type
   *   - 1|Fees
   *   - 2|Land rent
   *   - 3|Starting amount
   *   The type of invoice field_invoice_details.
   *
   * @return array
   *   The array of area ids.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getInvoiceIds(array $area_ids, $type = '1') {
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $invoice_nids = $query->condition('type', 'invoice')
      ->condition('field_areas_id.target_id', $area_ids, 'IN')
      ->condition('field_invoice_details', $type)
      ->condition('status', '1')
      ->accessCheck()
      ->execute();
    $invoice_nids = array_values($invoice_nids);
    return $invoice_nids ?? [];
  }

  /**
   * Get all area of particular farmer.
   *
   * @param string $farmer_id
   *   The farmer ID.
   *
   * @return array
   *   The array of area ids.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getFarmerAreaIds(string $farmer_id) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $area_nids = $query->condition('type', 'offer_license')
      ->condition('field_farmer_name_ref.target_id', $farmer_id)
      ->accessCheck()
      ->execute();
    $area_nids = array_values($area_nids);
    return $area_nids ?? [];
  }

  /**
   * Update view field result with get_payments_data.
   *
   * @param string $farmer_id
   *   The farmer ID.
   *
   * @return array|\Drupal\Component\Render\MarkupInterface|string
   *   The rendered payment data.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Exception
   */
  public function getPaymentsData(string $farmer_id) {
    $payments = [
      'fees' => [
        'balance' => 0,
        'payments' => 0,
        'charges' => 0,
        'data' => [],
      ],
      'land_rent' => [
        'balance' => 0,
        'payments' => 0,
        'charges' => 0,
        'data' => [],
      ],
      'due_starting_amount' => 0,
    ];
    $area_ids = $this->getFarmerAreaIds($farmer_id);
    if (!empty($area_ids)) {
      $this->getAllFeesData($area_ids, $payments);
      // Get all Land rent along with starting amount.
      $this->getAllLandRentData($area_ids, $payments);
    }
    // dump($payments);die;
    $renderable = [
      '#theme' => 'tab__accounts__payments_data',
      '#data' => ['payments' => $payments],
    ];
    return $this->renderer->render($renderable);;
  }

  /**
   * Get fee for all area of particular farmer.
   *
   * @param array $area_ids
   *   The area ID.
   * @param array $payment
   *   The $payment array.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getAllFeesData(array $area_ids, array &$payment) {
    $invoice_nids = $this->getInvoiceIds($area_ids);
    foreach ($invoice_nids as $invoice_id) {
      $payment_nids = $this->invoiceHasPayment($invoice_id);
      $invoice = $this->entityTypeManager->getStorage('node')->load($invoice_id);
      $amount = $invoice->get('field_amount')->value;
      $payment['fees']['charges'] += $amount;
      $data_array = [];
      if (!empty($payment_nids)) {
        $payment_nid = reset($payment_nids);
        $payment_node = $this->entityTypeManager->getStorage('node')->load($payment_nid);
        $payment['fees']['payments'] += $amount;
        $data_array['payment_date'] = $payment_node->get('field_date_paid')->value;
        $data_array['payment_receipt_number'] = $payment_node->get('field_receipt_number')->value;
      }
      else {
        $payment['fees']['balance'] += $amount;
      }
      $area = $invoice->get('field_areas_id')->referencedEntities()[0];
      if (!empty($area)) {
        $data_array['area'] = $area->getTitle();
      }
      $charge = $this->getChargeFromInvoice($invoice_id);
      if (!empty($charge)) {
        $data_array['date'] = $charge->get('field_charge_date')->value;
        $data_array['desc'] = $charge->get('field_charge_description')->value;
        $data_array['amount'] = $charge->get('field_amount')->value;
        $data_array['payment_advc_no'] = $invoice->get('field_invoice_number')->value;
        $data_array['payment_advc_nid'] = $invoice->id();
      }
      $payment['fees']['data'][] = $data_array;
    }
    if (isset($payment['fees']['data'])) {
      krsort($payment['fees']['data']);
    }
  }

  /**
   * Get land rent for all area of particular farmer.
   *
   * @param array $area_ids
   *   The area ID.
   * @param $payment
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getAllLandRentData(array $area_ids, &$payment) {
    $invoice_nids = $this->getInvoiceIds($area_ids, '2');
    foreach ($invoice_nids as $invoice_id) {
      $payment_nids = $this->invoiceHasPayment($invoice_id);
      $invoice = $this->entityTypeManager->getStorage('node')->load($invoice_id);
      $amount = $invoice->get('field_amount')->value;
      $payment['land_rent']['charges'] += $amount;
      $data_array = [];
      if (!empty($payment_nids)) {
        $payment_nid = reset($payment_nids);
        $payment_node = $this->entityTypeManager->getStorage('node')->load($payment_nid);
        $payment['land_rent']['payments'] += $amount;
        $data_array['payment_date'] = $payment_node->get('field_date_paid')->value;
        $data_array['payment_receipt_number'] = $payment_node->get('field_receipt_number')->value;
        $data_array['payment_nid'] = $payment_nid;
      }
      else {
        $payment['land_rent']['balance'] += $amount;
      }
      // Calculate land rent late_fee/due.
      $annual_charges = $this->getAnnualChargeFromInvoice($invoice_id);
      if ($annual_charges) {
        $charge_type = $annual_charges->get('field_annual_charges_type')->value;
        if ($charge_type == '1') {
          $data_array['due'] = $amount;
        }
        else {
          $data_array['late_fee_due'] = $amount;
          $data_array['previous_arrears'] = $annual_charges->get('field_arrears')->value;
        }
        $year = $annual_charges->get('field_rate_year')->value;
        $data_array['date'] = '01-01-' . $year;
        $data_array['total_due'] = $amount;
        $data_array['payment_advc_no'] = $invoice->get('field_invoice_number')->value;
        $data_array['payment_advc_nid'] = $invoice->id();
      }
      $area = $invoice->get('field_areas_id')->referencedEntities()[0];
      if (!empty($area)) {
        $data_array['area'] = $area->getTitle();
      }
      $payment['land_rent']['data'][] = $data_array;
    }
    if (isset($payment['land_rent']['data'])) {
      krsort($payment['land_rent']['data']);
    }

    // Starting amount as part of land rent.
    $sm_invoice_nids = $this->getInvoiceIds($area_ids, '3');
    $sm_data_array = [];
    foreach ($sm_invoice_nids as $invoice_id) {
      $invoice = $this->entityTypeManager->getStorage('node')->load($invoice_id);
      $amount = $invoice->get('field_amount')->value;
      $sm_data_array['date'] = 'Starting amount';
      $sm_data_array['due'] = $amount;
      $sm_data_array['late_fee_due'] = 0;
      $sm_data_array['previous_arrears'] = 0;
      $sm_data_array['total_due'] = $amount;
      $sm_data_array['payment_advc_no'] = $invoice->get('field_invoice_number')->value;
      $sm_data_array['payment_advc_nid'] = $invoice->id();
      $area = $invoice->get('field_areas_id')->referencedEntities()[0];
      if (!empty($area)) {
        $sm_data_array['area'] = $area->getTitle();
      }
      $payment_nids = $this->invoiceHasPayment($invoice_id);
      if (!empty($payment_nids)) {
        $payment_nid = reset($payment_nids);
        $payment_node = $this->entityTypeManager->getStorage('node')->load($payment_nid);
        $sm_data_array['payment_date'] = $payment_node->get('field_date_paid')->value;
        $sm_data_array['payment_receipt_number'] = $payment_node->get('field_receipt_number')->value;
        $sm_data_array['payment_nid'] = $payment_nid;
      }
      else {
        $payment['due_starting_amount'] += $amount;
        $sm_data_array['payment_date'] = NULL;
        $sm_data_array['payment_receipt_number'] = NULL;
        $sm_data_array['payment_nid'] = NULL;
      }
      $payment['land_rent']['data'][] = $sm_data_array;
    }

  }

  /**
   * Get arrears which is also know as starting amount for land rent.
   *
   * Note payment history data should be added in historical order.
   *
   * @param int $offer_licence_id
   *   The area id for which arrears need to find.
   *
   * @return int|null
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getArrears($offer_licence_id) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $historical_payments_nid = $query->condition('type', 'historical_payments')
      ->condition('field_areas_id.target_id', $offer_licence_id)
      ->sort('field_charge_date.value', 'DESC')
      ->range(0, 1)
      ->accessCheck()
      ->execute();

    if (!empty($historical_payments_nid)) {
      $historical_payments_nid = reset($historical_payments_nid);
      $historical_payments = $this->entityTypeManager->getStorage('node')->load($historical_payments_nid);
      return $historical_payments->get('field_arrears')->value;
    }
    return 'starting_entry';
  }

  /**
   * Get the farmer ID from the title query parameter.
   *
   * @return int|null
   *   The farmer ID or NULL if not found.
   */
  public function getFarmerIdFromTitle() {
    $title = $this->requestStack->getCurrentRequest()->query->get('title');
    if ($title) {
      $query = $this->entityTypeManager->getStorage('node')->getQuery();
      $nids = $query->condition('type', 'farmer_details')
        ->condition('title', $title)
        ->accessCheck(TRUE)
        ->execute();
      if (!empty($nids)) {
        return reset($nids);
      }
    }
    return NULL;
  }

}
