<?php
/**
 * @package      Crowdfunding
 * @subpackage   Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2017 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      GNU General Public License version 3 or later; see LICENSE.txt
 */

use Joomla\Utilities\ArrayHelper;
use Joomla\String\StringHelper;
use Crowdfunding\Payment;
use Crowdfunding\Transaction\Transaction;
use Crowdfunding\Transaction\TransactionManager;
use Prism\Payment\Result as PaymentResult;

// no direct access
defined('_JEXEC') or die;

jimport('Prism.init');
jimport('Crowdfunding.init');
jimport('Emailtemplates.init');

JObserverMapper::addObserverClassToClass(Crowdfunding\Observer\Transaction\TransactionObserver::class, Crowdfunding\Transaction\TransactionManager::class, array('typeAlias' => 'com_crowdfunding.payment'));

/**
 * Crowdfunding Mollie iDEAL Payment Plugin
 *
 * @package      Crowdfunding
 * @subpackage   Plugins
 */
class plgCrowdfundingPaymentMollieIdeal extends Payment\Plugin
{
    protected $version = '2.2';

    public function __construct(&$subject, $config = array())
    {
        $this->serviceProvider = 'Mollie iDEAL';
        $this->serviceAlias    = 'mollieideal';
        
        parent::__construct($subject, $config);
    }

    /**
     * This method prepares a payment gateway - buttons, forms,...
     * That gateway will be displayed on the summary page as a payment option.
     *
     * @param string    $context This string gives information about that where it has been executed the trigger.
     * @param stdClass  $item    A project data.
     * @param Joomla\Registry\Registry $params  The parameters of the component
     *
     * @return null|string
     */
    public function onProjectPayment($context, &$item, &$params)
    {
        if (strcmp('com_crowdfunding.payment', $context) !== 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('html', $docType) !== 0) {
            return null;
        }

        // This is a URI path to the plugin folder
        $pluginURI = 'plugins/crowdfundingpayment/mollieideal';

        // Load the script that initialize the select element with banks.
        JHtml::_('jquery.framework');
        $doc->addScript($pluginURI . '/js/plg_crowdfundingpayment_mollieideal.js?v=' . urlencode($this->version));

        // Get API key.
        $apiKey = $this->getApiKey();

        $html   = array();
        $html[] = '<div class="well">'; // Open "well".

        $html[] = '<h4><img src="' . $pluginURI . '/images/ideal_icon.png" />' . JText::_($this->textPrefix . '_TITLE') . '</h4>';
        $html[] = '<p>' . JText::_($this->textPrefix . '_INFO') . '</p>';

        if (!$apiKey) {
            $html[] = '<div class="alert alert-warning p-10-5"><span class="fa fa-warning"></span> ' . JText::_($this->textPrefix . '_ERROR_PLUGIN_NOT_CONFIGURED') . '</div>';
            $html[] = '</div>'; // Close "well".
            return implode("\n", $html);
        }

        // Register Mollie classes.
        jimport('Prism.libs.Mollie.init');

        $mollie = new Mollie_API_Client;
        $mollie->setApiKey($apiKey);

        // Get banks
        $banks = $mollie->issuers->all();
        $banks = $this->prepareBanks($banks);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_BANKS'), $this->debugType, $banks) : null;

        $selectBankOption = array(
            array(
                'text'  => JText::_($this->textPrefix . '_SELECT_BANK'),
                'value' => ''
            )
        );
        $banks  = array_merge($selectBankOption, $banks);

        $html[] = '<select name="bank_id" id="js-mollieideal-bank-id" data-project-id="' . (int)$item->id . '" data-reward-id="' . (int)$item->rewardId . '" data-amount="' . $item->amount . '" >';
        $html[] = JHtml::_('select.options', $banks);
        $html[] = '</select>';

        $html[] = '<div class="bg-warning m-10" id="js-mollie-ideal-alert" style="display: none;"></div>';

        $html[] = '<img src="media/com_crowdfunding/images/ajax-loader.gif" width="16" height="16" id="js-mollie-ajax-loading" style="display: none;" />';
        $html[] = '<a href="#" class="btn btn-primary mtb-10" id="js-continue-mollie" style="display: none;"><span class="fa fa-chevron-right "></span> ' . JText::_($this->textPrefix . '_CONTINUE_TO_MOLLIE') . '</a>';

        if ($this->params->get('testmode', 1)) {
            $html[] = '<p class="alert alert-info p-10-5 mt-5"><span class="fa fa-info-circle"></span> ' . JText::_($this->textPrefix . '_WORKS_TESTMODE') . '</p>';
        }

        $html[] = '</div>'; // Close "well".

        return implode("\n", $html);
    }

    /**
     * This method processes transaction data that comes from payment gateway.
     *
     * @param string    $context This string gives information about that where it has been executed the trigger.
     * @param Joomla\Registry\Registry $params  The parameters of the component
     *
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException
     * @throws \OutOfBoundsException
     * @throws \RuntimeException
     * @throws \Mollie_API_Exception
     *
     * @return null|PaymentResult
     */
    public function onPaymentNotify($context, $params)
    {
        if (strcmp('com_crowdfunding.notify.mollieideal', $context) !== 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('raw', $docType) !== 0) {
            return null;
        }

        // Validate request method
        $requestMethod = $this->app->input->getMethod();
        if (strcmp('POST', $requestMethod) !== 0) {
            $this->log->add(
                JText::_($this->textPrefix . '_ERROR_INVALID_REQUEST_METHOD'),
                $this->debugType,
                JText::sprintf($this->textPrefix . '_ERROR_INVALID_TRANSACTION_REQUEST_METHOD', $requestMethod)
            );

            return null;
        }

        // Get transaction ID
        $transactionId = $this->app->input->post->get('id');
        if (!$transactionId) {
            return null;
        }

        // Get payment session data
        $keys = array(
            'unique_key' => $transactionId
        );
        $paymentSessionRemote = $this->getPaymentSession($keys);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_PAYMENT_SESSION'), $this->debugType, $paymentSessionRemote->getProperties()) : null;

        // Verify the gateway.
        $gateway = $paymentSessionRemote->getGateway();
        if (!$this->isValidPaymentGateway($gateway)) {
            return null;
        }

        // Get API key.
        $apiKey = $this->getApiKey();

        // Prepare the array that have to be returned by this method.
        $paymentResult = new PaymentResult;

        // Register Mollie classes.
        jimport('Prism.libs.Mollie.init');

        $mollie = new Mollie_API_Client;
        $mollie->setApiKey($apiKey);

        $payment = $mollie->payments->get($transactionId);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_PAYMENT_OBJECT'), $this->debugType, $payment) : null;

        if ($payment->id) {
            $containerHelper  = new Crowdfunding\Container\Helper();
            $currency         = $containerHelper->fetchCurrency($this->container, $params);

            // Prepare the transaction data that will be validated.
            $transactionData = array(
                'txn_currency' => $currency->getCode(),
                'txn_amount'   => $payment->amount,
                'project_id'   => $paymentSessionRemote->getProjectId(),
                'user_id'      => $paymentSessionRemote->getUserId(),
                'reward_id'    => $paymentSessionRemote->isAnonymous() ? 0 : $paymentSessionRemote->getRewardId(), // Set reward ID to 0 because anonymous users cannot sellect reward.
                'txn_id'       => $payment->id
            );

            // Set completed because the paid status is TRUE.
            if (strcmp('paid', $payment->status) === 0) {
                $transactionData['txn_status'] = 'completed';
            } elseif (strcmp('cancelled', $payment->status) === 0) {
                $transactionData['txn_status'] = 'cancelled';
            } else {
                $transactionData['txn_status'] = 'pending';
            }

            // Set real status ( the bank payment status ) and consumer name as additional data.
            $transactionData['extra_data'] = array(
                'mode'   => $payment->mode,
                'method' => $payment->method,
                'createdDatetime' => $payment->createdDatetime,
                'status' => $payment->status,
                'paidDatetime' => $payment->paidDatetime,
                'cancelledDatetime' => $payment->cancelledDatetime,
                'expiredDatetime' => $payment->expiredDatetime,
                'links' => $payment->links
            );

            // Validate transaction data
            $validData = $this->validateData($transactionData);
            if ($validData === null) {
                return null;
            }

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_TRANSACTION_DATA'), $this->debugType, $validData) : null;

            // Set the receiver ID.
            $project = $containerHelper->fetchProject($this->container, $validData['project_id']);
            $validData['receiver_id'] = $project->getUserId();

            // Get reward object.
            $reward = null;
            if ($validData['reward_id']) {
                $reward = $containerHelper->fetchReward($this->container, $validData['reward_id'], $project->getId());
            }

            // Save transaction data.
            // If it is not completed, return empty results.
            // If it is complete, continue with process transaction data
            $transaction = $this->storeTransaction($validData);
            if ($transaction === null) {
                return null;
            }

            // Generate object of data, based on the transaction properties.
            $paymentResult->transaction = $transaction;

            // Generate object of data based on the project properties.
            $paymentResult->project = $project;

            // Generate object of data based on the reward properties.
            if ($reward !== null and ($reward instanceof Crowdfunding\Reward)) {
                $paymentResult->reward = $reward;
            }

            // Generate data object, based on the payment session properties.
            $paymentResult->paymentSession = $paymentSessionRemote;

            // Removing intention.
            $this->removeIntention($paymentSessionRemote, $transaction);
        }

        return $paymentResult;
    }

    /**
     * This method generates URL to the service, based on selected bank, and send it to the browser.
     *
     * @param string $context
     * @param Joomla\Registry\Registry $params
     *
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException
     * @throws \OutOfBoundsException
     * @throws \RuntimeException
     * @throws \Mollie_API_Exception
     *
     * @return stdClass|null
     */
    public function onPaymentsPreparePayment($context, $params)
    {
        if (strcmp('com_crowdfunding.preparepayment.mollieideal', $context) !== 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('raw', $docType) !== 0) {
            return null;
        }

        $apiKey    = $this->getApiKey();

        $projectId = $this->app->input->getInt('pid');
        $rewardId  = $this->app->input->get('reward_id');
        $bankId    = $this->app->input->get('bank_id');
        $amount    = $this->app->input->getFloat('amount');

        $aUserId   = $this->app->getUserState('auser_id');

        $userId    = JFactory::getUser()->get('id');

        // Get project
        $containerHelper  = new Crowdfunding\Container\Helper();
        $project          = $containerHelper->fetchProject($this->container, $projectId);

        if (!$project->getId()) {
            $response = array(
                'success' => false,
                'title'   => JText::_($this->textPrefix . '_FAIL'),
                'text'    => JText::_($this->textPrefix . '_ERROR_INVALID_PROJECT')
            );

            return ArrayHelper::toObject($response);
        }

        //  PAYMENT SESSION

        $paymentSessionContext    = Crowdfunding\Constants::PAYMENT_SESSION_CONTEXT . $project->getId();
        $paymentSessionLocal      = $this->app->getUserState($paymentSessionContext);

        $paymentSessionRemote = $this->getPaymentSession(array(
            'session_id'    => $paymentSessionLocal->session_id
        ));

        // Set main data if it is a new payment session.
        if (!$paymentSessionRemote->getId()) {
            $recordDate = new JDate();

            $paymentSessionData['user_id']     = $userId;
            $paymentSessionData['auser_id']    = $aUserId; // This is hash user ID used for anonymous users.
            $paymentSessionData['project_id']  = $projectId;
            $paymentSessionData['reward_id']   = $rewardId;
            $paymentSessionData['record_date'] = $recordDate->toSql();

            $paymentSessionRemote->bind($paymentSessionData);
        }

        // Set the gateway.
        $paymentSessionRemote->setGateway($this->serviceAlias);
        $paymentSessionRemote->store();

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_PAYMENT_SESSION'), $this->debugType, $paymentSessionRemote->getProperties()) : null;

        // Prepare URLs
        $returnUrl   = $this->getReturnUrl($project->getSlug(), $project->getCatSlug());
        $callbackUrl = $this->getCallbackUrl();

        $paymentOptions['return_url'] = $returnUrl;
        $paymentOptions['report_url'] = $callbackUrl;

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_RETURN_URL'), $this->debugType, $returnUrl) : null;
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_REPORT_URL'), $this->debugType, $callbackUrl) : null;

        $paymentOptions = array(
            'amount'      => (float)$amount,
            'description' => StringHelper::substr($project->getTitle(), 0, 32),
            'redirectUrl' => $returnUrl,
            'webhookUrl'  => $callbackUrl,
            'issuer'      => $bankId,
            'method'      => 'ideal',
            'metadata'    => array(
                'payment_session_id' => $paymentSessionRemote->getId()
            )
        );

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_PAYMENT_OPTIONS'), $this->debugType, $paymentOptions) : null;

        jimport('Prism.libs.Mollie.init');
        $paymentGateway = new Mollie_API_Client;
        $paymentGateway->setApiKey($apiKey);

        try {
            $payment = $paymentGateway->payments->create($paymentOptions);

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_PAYMENT_OBJECT'), $this->debugType, $payment) : null;

            $url   = $payment->getPaymentUrl();

            // Store the ID.
            $paymentSessionRemote->setUniqueKey($payment->id);
            $paymentSessionRemote->storeUniqueKey();

        } catch (Mollie_API_Exception $e) {
            JLog::add(JText::_($this->textPrefix . '_ERROR_CALL_FAILED_S', htmlspecialchars($e->getMessage()), htmlspecialchars($e->getField())));

            $response = array(
                'success' => false,
                'title'   => JText::_($this->textPrefix . '_FAIL'),
                'text'    => JText::_($this->textPrefix . '_ERROR_CALL_FAILED')
            );

            return ArrayHelper::toObject($response);
        }

        // Return response
        $response = array(
            'success' => true,
            'title'   => JText::_('PLG_CROWDFUNDINGPAYMENT_MOLLIEIDEAL_SUCCESS'),
            'data'    => array(
                'url' => $url
            )
        );

        return ArrayHelper::toObject($response);
    }

    /**
     * Validate the transaction.
     *
     * @param array $data This is a transaction data, that comes from the payment gateway.
     *
     * @throws \InvalidArgumentException
     *
     * @return null|array
     */
    protected function validateData($data)
    {
        $date = new JDate();

        $status = ArrayHelper::getValue($data, 'txn_status');

        if (strcmp($status, 'paid') === 0) {
            $status = Prism\Constants::PAYMENT_STATUS_COMPLETED;
        }

        if (strcmp($status, 'expired') === 0) {
            $status = Prism\Constants::PAYMENT_STATUS_FAILED;
        }

        if (strcmp($status, 'cancelled') === 0) {
            $status = Prism\Constants::PAYMENT_STATUS_CANCELED;
        }

        // Prepare transaction data
        $transaction = array(
            'investor_id'      => ArrayHelper::getValue($data, 'user_id', 0, 'int'),
            'project_id'       => ArrayHelper::getValue($data, 'project_id', 0, 'int'),
            'reward_id'        => ArrayHelper::getValue($data, 'reward_id', 0, 'int'),
            'txn_id'           => ArrayHelper::getValue($data, 'txn_id'),
            'txn_amount'       => ArrayHelper::getValue($data, 'txn_amount'),
            'txn_currency'     => ArrayHelper::getValue($data, 'txn_currency'),
            'txn_status'       => $status,
            'txn_date'         => $date->toSql(),
            'extra_data'       => ArrayHelper::getValue($data, 'extra_data'),
            'service_provider' => $this->serviceProvider,
            'service_alias'    => $this->serviceAlias
        );

        // Check User Id, Project ID and Transaction ID
        if (!$transaction['project_id'] or !$transaction['txn_id']) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_TRANSACTION_DATA'), $this->debugType, $transaction);
            return null;
        }

        return $transaction;
    }

    /**
     * Save transaction
     *
     * @param array  $transactionData
     *
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException
     *
     * @return null|Transaction
     */
    protected function storeTransaction($transactionData)
    {
        // Get transaction object by transaction ID
        $keys  = array(
            'txn_id' => ArrayHelper::getValue($transactionData, 'txn_id')
        );
        $transaction = new Transaction(JFactory::getDbo());
        $transaction->load($keys);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_TRANSACTION_OBJECT'), $this->debugType, $transaction->getProperties()) : null;

        // Check for existed transaction
        // If the current status if completed, stop the payment process.
        if ($transaction->getId() and $transaction->isCompleted()) {
            return null;
        }

        // Add extra data.
        if (array_key_exists('extra_data', $transactionData) and !empty($transactionData['extra_data'])) {
            $transaction->addExtraData($transactionData['extra_data']);
            unset($transactionData['extra_data']);
        }

        // IMPORTANT: It must be placed before ->bind();
        $options = array(
            'old_status' => $transaction->getStatus(),
            'new_status' => $transactionData['txn_status']
        );

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_TRANSACTION_STATUSES'), $this->debugType, $options) : null;

        // Create the new transaction record if there is not record.
        // If there is new record, store new data with new status.
        // Example: It has been 'pending' and now is 'completed'.
        // Example2: It has been 'pending' and now is 'failed'.
        $transaction->bind($transactionData);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_TRANSACTION_OBJECT_AFTER_BIND'), $this->debugType, $transaction->getProperties()) : null;

        // Start database transaction.
        $db = JFactory::getDbo();

        try {
            $db->transactionStart();

            $transactionManager = new TransactionManager($db);
            $transactionManager->setTransaction($transaction);
            $transactionManager->process('com_crowdfunding.payment', $options);

            $db->transactionCommit();
        } catch (Exception $e) {
            $db->transactionRollback();

            $this->log->add(JText::_($this->textPrefix . '_ERROR_TRANSACTION_PROCESS'), $this->errorType, $e->getMessage());
            return null;
        }

        return $transaction;
    }

    /**
     * Prepare the date of banks to array, which will be used as options.
     *
     * @param array $issuers
     *
     * @return array
     */
    protected function prepareBanks($issuers)
    {
        $banks = array();

        foreach ($issuers as $issuer) {
            if ($issuer->method === Mollie_API_Object_Method::IDEAL) {
                $banks[] = array(
                    'value' => htmlspecialchars($issuer->id),
                    'text'  => htmlspecialchars($issuer->name)
                );
            }
        }

        return $banks;
    }

    protected function getApiKey()
    {
        if ((bool)$this->params->get('testmode', 1)) {
            return $this->params->get('test_api_key');
        } else {
            return $this->params->get('api_key');
        }
    }
}
