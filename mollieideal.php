<?php
/**
 * @package      CrowdFunding
 * @subpackage   Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2015 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

// no direct access
defined('_JEXEC') or die;

jimport('crowdfunding.payment.plugin');

/**
 * CrowdFunding Mollie iDEAL Payment Plugin
 *
 * @package      CrowdFunding
 * @subpackage   Plugins
 */
class plgCrowdFundingPaymentMollieIdeal extends CrowdFundingPaymentPlugin
{
    protected $version = "1.10";
    protected $paymentService = "mollieideal";

    protected $textPrefix   = "PLG_CROWDFUNDINGPAYMENT_MOLLIEIDEAL";
    protected $debugType    = "MOLLIEIDEAL_PAYMENT_PLUGIN_DEBUG";

    /**
     * @var JApplicationSite
     */
    protected $app;

    /**
     * This method prepares a payment gateway - buttons, forms,...
     * That gateway will be displayed on the summary page as a payment option.
     *
     * @param string    $context This string gives information about that where it has been executed the trigger.
     * @param object    $item    A project data.
     * @param Joomla\Registry\Registry $params  The parameters of the component
     *
     * @return null|string
     */
    public function onProjectPayment($context, &$item, &$params)
    {
        if (strcmp("com_crowdfunding.payment", $context) != 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp("html", $docType) != 0) {
            return null;
        }

        // This is a URI path to the plugin folder
        $pluginURI = "plugins/crowdfundingpayment/mollieideal";

        // Load the script that initialize the select element with banks.
        JHtml::_("jquery.framework");
        $doc->addScript($pluginURI . "/js/plg_crowdfundingpayment_mollieideal.js?v=" . urlencode($this->version));

        // Get API key.
        $apiKey = $this->getApiKey();

        $html   = array();
        $html[] = '<div class="well">'; // Open "well".

        $html[] = '<h4><img src="' . $pluginURI . '/images/ideal_icon.png" />' . JText::_($this->textPrefix . "_TITLE") . '</h4>';
        $html[] = '<p>' . JText::_($this->textPrefix . "_INFO") . '</p>';

        if (!$apiKey) {
            $html[] = '<div class="alert">' . JText::_($this->textPrefix . "_ERROR_PLUGIN_NOT_CONFIGURED") . '</div>';
            $html[] = '</div>'; // Close "well".
            return implode("\n", $html);
        }

        // Register Mollie classes.
        jimport("itprism.payment.mollie.init");

        $mollie = new Mollie_API_Client;
        $mollie->setApiKey($apiKey);

        // Get banks
        $banks = $mollie->issuers->all();
        $banks = $this->prepareBanks($banks);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_BANKS"), $this->debugType, $banks) : null;

        $selectBankOption = array(
            array(
                "text"  => JText::_($this->textPrefix . "_SELECT_BANK"),
                "value" => ""
            )
        );
        $banks  = array_merge($selectBankOption, $banks);

        $html[] = '<select name="bank_id" id="js-mollieideal-bank-id" data-project-id="' . (int)$item->id . '" data-reward-id="' . (int)$item->rewardId . '" data-amount="' . $item->amount . '" >';
        $html[] = JHtml::_("select.options", $banks);
        $html[] = '</select>';

        $html[] = '<div class="alert hide" id="js-mollie-ideal-alert"></div>';

        $html[] = '<div class="clearfix"></div>';
        $html[] = '<img src="media/com_crowdfunding/images/ajax-loader.gif" width="16" height="16" id="js-mollie-ajax-loading" style="display: none;" />';
        $html[] = '<a href="#" class="btn btn-primary" id="js-continue-mollie" style="display: none;">' . JText::_($this->textPrefix . "_CONTINUE_TO_MOLLIE") . '</a>';

        if ($this->params->get('testmode', 1)) {
            $html[] = '<p class="alert alert-info"><i class="icon-info-sign"></i>' . JText::_($this->textPrefix . "_WORKS_TESTMODE") . '</p>';
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
     * @return array|null
     */
    public function onPaymentNotify($context, $params)
    {
        $this->log->add(
            "Context",
            $this->debugType,
            $context
        );

        if (strcmp("com_crowdfunding.notify.mollieideal", $context) != 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp("raw", $docType) != 0) {
            return null;
        }

        // Validate request method
        $requestMethod = $this->app->input->getMethod();
        if (strcmp("POST", $requestMethod) != 0) {

            $this->log->add(
                JText::_($this->textPrefix . "_ERROR_INVALID_REQUEST_METHOD"),
                $this->debugType,
                JText::sprintf($this->textPrefix . "_ERROR_INVALID_TRANSACTION_REQUEST_METHOD", $requestMethod)
            );

            return null;
        }

        // Get transaction ID
        $transactionId = $this->app->input->post->get("id");
        if (!$transactionId) {
            return null;
        }

        // Get intention data
        $keys = array(
            "unique_key" => $transactionId
        );
        jimport("crowdfunding.intention");
        $intention = new CrowdFundingIntention(JFactory::getDbo());
        $intention->load($keys);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_INTENTION"), $this->debugType, $intention->getProperties()) : null;

        // Verify the gateway.
        $gateway = $intention->getGateway();
        if (!$this->isValidPaymentGateway($gateway)) {
            return null;
        }

        // Get API key.
        $apiKey = $this->getApiKey();

        // Prepare the array that will be returned by this method
        $result = array(
            "project"         => null,
            "reward"          => null,
            "transaction"     => null,
            "payment_session" => null,
            "payment_service" => $this->paymentService
        );

        // Register Mollie classes.
        jimport("itprism.payment.mollie.init");

        $mollie = new Mollie_API_Client;
        $mollie->setApiKey($apiKey);

        $payment    = $mollie->payments->get($transactionId);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_PAYMENT_OBJECT"), $this->debugType, $payment) : null;

        if ($payment->id) {

            // Get currency
            jimport("crowdfunding.currency");
            $currencyId = $params->get("project_currency");
            $currency   = CrowdFundingCurrency::getInstance(JFactory::getDbo(), $currencyId);

            // Prepare the transaction data that will be validated.
            $transactionData = array(
                "txn_currency" => $currency->getAbbr(),
                "txn_amount"   => $payment->amount,
                "project_id"   => $intention->getProjectId(),
                "user_id"      => $intention->getUserId(),
                "reward_id"    => ($intention->isAnonymous()) ? 0 : $intention->getRewardId(), // Set reward ID to 0 because anonymous users cannot sellect reward.
                "txn_id"       => $payment->id
            );

            // Set completed because the paid status is TRUE.
            if (strcmp("paid", $payment->status) == 0) {
                $transactionData["txn_status"] = "completed";
            } elseif (strcmp("cancelled", $payment->status) == 0) {
                $transactionData["txn_status"] = "cancelled";
            } else {
                $transactionData["txn_status"] = "pending";
            }

            // Set real status ( the bank payment status ) and consumer name as additional data.
            $transactionData["extra_data"] = array(
                "mode"   => $payment->mode,
                "method" => $payment->method,
                "createdDatetime" => $payment->createdDatetime,
                "status" => $payment->status,
                "paidDatetime" => $payment->paidDatetime,
                "cancelledDatetime" => $payment->cancelledDatetime,
                "expiredDatetime" => $payment->expiredDatetime,
                "links" => $payment->links
            );

            // Validate transaction data
            $validData = $this->validateData($transactionData);
            if (is_null($validData)) {
                return $result;
            }

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_TRANSACTION_DATA"), $this->debugType, $validData) : null;

            // Get project
            jimport("crowdfunding.project");
            $projectId = JArrayHelper::getValue($validData, "project_id");
            $project   = CrowdFundingProject::getInstance(JFactory::getDbo(), $projectId);

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_PROJECT_OBJECT"), $this->debugType, $project->getProperties()) : null;

            // Check for valid project
            if (!$project->getId()) {

                // Log data in the database
                $this->log->add(
                    JText::_($this->textPrefix . "_ERROR_INVALID_PROJECT"),
                    $this->debugType,
                    $validData
                );

                return $result;
            }

            // Set the receiver of funds
            $validData["receiver_id"] = $project->getUserId();

            // Save transaction data.
            // If it is not completed, return empty results.
            // If it is complete, continue with process transaction data
            $transactionData = $this->storeTransaction($validData, $project);
            if (is_null($transactionData)) {
                return $result;
            }

            // Update the number of distributed reward.
            $rewardId = JArrayHelper::getValue($transactionData, "reward_id");
            $reward   = null;
            if (!empty($rewardId)) {
                $reward = $this->updateReward($transactionData);

                // Validate the reward.
                if (!$reward) {
                    $transactionData["reward_id"] = 0;
                }
            }

            //  Prepare the data that will be returned

            $result["transaction"] = JArrayHelper::toObject($transactionData);

            // Generate object of data based on the project properties
            $properties        = $project->getProperties();
            $result["project"] = JArrayHelper::toObject($properties);

            // Generate object of data based on the reward properties
            if (!empty($reward)) {
                $properties       = $reward->getProperties();
                $result["reward"] = JArrayHelper::toObject($properties);
            }

            // Generate data object, based on the intention properties.
            $properties       = $intention->getProperties();
            $result["payment_session"] = JArrayHelper::toObject($properties);

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_RESULT_DATA"), $this->debugType, $result) : null;

            // Remove intention
            $intention->delete();
            unset($intention);

        }

        return $result;

    }

    /**
     * This method is executed after complete payment.
     * It is used to be sent mails to user and administrator
     *
     * @param string $context  Transaction data
     * @param object $transaction  Transaction data
     * @param Joomla\Registry\Registry $params Component parameters
     * @param object $project  Project data
     * @param object $reward  Reward data
     * @param object $paymentSession Payment session data.
     */
    public function onAfterPayment($context, &$transaction, &$params, &$project, &$reward, &$paymentSession)
    {
        if (strcmp("com_crowdfunding.notify.mollieideal", $context) != 0) {
            return;
        }

        if ($this->app->isAdmin()) {
            return;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp("raw", $docType) != 0) {
            return;
        }

        // Send mails
        $this->sendMails($project, $transaction, $params);
    }

    /**
     * This method generates URL to the service, based on selected bank, and send it to the browser.
     *
     * @param string $context
     * @param Joomla\Registry\Registry $params
     *
     * @return array|null
     */
    public function onPaymentsPreparePayment($context, &$params)
    {
        if (strcmp("com_crowdfunding.preparepayment.mollieideal", $context) != 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp("raw", $docType) != 0) {
            return null;
        }

        $apiKey    = $this->getApiKey();

        $projectId = $this->app->input->getInt("pid");
        $rewardId  = $this->app->input->get("reward_id");
        $bankId    = $this->app->input->get("bank_id");
        $amount    = $this->app->input->getFloat("amount");

        $aUserId   = $this->app->getUserState("auser_id");

        $userId    = JFactory::getUser()->get("id");

        // Get project
        jimport("crowdfunding.project");
        $project = new CrowdFundingProject(JFactory::getDbo());
        $project->load($projectId);

        if (!$project->getId()) {
            $response = array(
                "success" => false,
                "title"   => JText::_($this->textPrefix . "_FAIL"),
                "text"    => JText::_($this->textPrefix . "_ERROR_INVALID_PROJECT")
            );

            return $response;
        }

        //  INTENTIONS

        $intention = $this->getIntention(array(
            "user_id"    => $userId,
            "auser_id"   => $aUserId,
            "project_id" => $projectId
        ));

        // Set main data if it is a new intention.
        if (!$intention->getId()) {

            $recordDate = new JDate();

            $intentionData["user_id"]     = $userId;
            $intentionData["auser_id"]    = $aUserId; // This is hash user ID used for anonymous users.
            $intentionData["project_id"]  = $projectId;
            $intentionData["reward_id"]   = $rewardId;
            $intentionData["record_date"] = $recordDate->toSql();

            $intention->bind($intentionData);
        }

        // Set the gateway.
        $intention->setGateway($this->paymentService);
        $intention->store();

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_INTENTION"), $this->debugType, $intention->getProperties()) : null;

        // Prepare URLs
        $returnUrl   = $this->getReturnUrl($project->getSlug(), $project->getCatSlug());
        $callbackUrl = $this->getCallbackUrl();

        $paymentOptions["return_url"] = $returnUrl;
        $paymentOptions["report_url"] = $callbackUrl;

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_RETURN_URL"), $this->debugType, $returnUrl) : null;
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_REPORT_URL"), $this->debugType, $callbackUrl) : null;

        $paymentOptions = array(
            "amount"      => (float)$amount,
            "description" => JString::substr($project->getTitle(), 0, 32),
            "redirectUrl" => $returnUrl,
            "webhookUrl"  => $callbackUrl,
            "issuer"      => $bankId,
            "method"      => "ideal",
            "metadata"    => array(
                "intention_id" => $intention->getId()
            )
        );

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_PAYMENT_OPTIONS"), $this->debugType, $paymentOptions) : null;

        jimport("itprism.payment.mollie.init");
        $paymentGateway = new Mollie_API_Client;
        $paymentGateway->setApiKey($apiKey);

        try {

            $payment = $paymentGateway->payments->create($paymentOptions);

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_PAYMENT_OBJECT"), $this->debugType, $payment) : null;

            $url   = $payment->getPaymentUrl();

            // Store the ID.
            $intention->setUniqueKey($payment->id);
            $intention->storeUniqueKey();

        } catch (Mollie_API_Exception $e) {

            JLog::add(JText::_($this->textPrefix . "_ERROR_CALL_FAILED_S", htmlspecialchars($e->getMessage()), htmlspecialchars($e->getField())));

            $response = array(
                "success" => false,
                "title"   => JText::_($this->textPrefix . "_FAIL"),
                "text"    => JText::_($this->textPrefix . "_ERROR_CALL_FAILED")
            );

            return $response;
        }

        // Return response
        $response = array(
            "success" => true,
            "title"   => JText::_('PLG_CROWDFUNDINGPAYMENT_MOLLIEIDEAL_SUCCESS'),
            "data"    => array(
                "url" => $url
            )
        );

        return $response;
    }

    /**
     * Validate the transaction.
     *
     * @param array $data This is a transaction data, that comes from the payment gateway.
     *
     * @return null|array
     */
    protected function validateData($data)
    {
        $date = new JDate();

        // Prepare transaction data
        $transaction = array(
            "investor_id"      => JArrayHelper::getValue($data, "user_id", 0, "int"),
            "project_id"       => JArrayHelper::getValue($data, "project_id", 0, "int"),
            "reward_id"        => JArrayHelper::getValue($data, "reward_id", 0, "int"),
            "txn_id"           => JArrayHelper::getValue($data, "txn_id"),
            "txn_amount"       => JArrayHelper::getValue($data, "txn_amount"),
            "txn_currency"     => JArrayHelper::getValue($data, "txn_currency"),
            "txn_status"       => JArrayHelper::getValue($data, "txn_status"),
            "txn_date"         => $date->toSql(),
            "extra_data"       => JArrayHelper::getValue($data, "extra_data"),
            "service_provider" => "Mollie iDEAL",
        );

        // Check User Id, Project ID and Transaction ID
        if (!$transaction["project_id"] or !$transaction["txn_id"]) {
            // Log data in the database
            $this->log->add(
                JText::_($this->textPrefix . "_ERROR_INVALID_TRANSACTION_DATA"),
                $this->debugType,
                $transaction
            );

            return null;
        }

        return $transaction;
    }

    /**
     * Save transaction
     *
     * @param array               $transactionData
     * @param CrowdFundingProject $project
     *
     * @return null|array
     */
    protected function storeTransaction($transactionData, $project)
    {
        // Get transaction by txn ID
        jimport("crowdfunding.transaction");
        $keys        = array(
            "txn_id" => JArrayHelper::getValue($transactionData, "txn_id")
        );
        $transaction = new CrowdFundingTransaction(JFactory::getDbo());
        $transaction->load($keys);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_TRANSACTION_OBJECT"), $this->debugType, $transaction->getProperties()) : null;

        // Check for valid transaction
        if ($transaction->getId()) {

            // If the current status is completed,
            // stop the process.
            if ($transaction->isCompleted()) {
                return null;
            }

        }

        // Add extra data.
        if (isset($transactionData["extra_data"])) {
            if (!empty($transactionData["extra_data"])) {
                $transaction->addExtraData($transactionData["extra_data"]);
            }

            unset($transactionData["extra_data"]);
        }

        // Store the new transaction data.
        $transaction->bind($transactionData);
        $transaction->store();

        // If it is not completed (it might be pending or other status),
        // stop the process. Only completed transaction will continue
        // and will process the project, rewards,...
        if (!$transaction->isCompleted()) {
            return null;
        }

        // Set transaction ID.
        $transactionData["id"] = $transaction->getId();

        // If the new transaction is completed,
        // update project funded amount.
        $amount = JArrayHelper::getValue($transactionData, "txn_amount");
        $project->addFunds($amount);
        $project->updateFunds();

        return $transactionData;
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

            if ($issuer->method == Mollie_API_Object_Method::IDEAL) {
                $banks[] = array(
                    "value" => htmlspecialchars($issuer->id),
                    "text"  => htmlspecialchars($issuer->name)
                );
            }

        }

        return $banks;
    }

    protected function getApiKey()
    {
        if (!$this->params->get("testmode", 1)) {
            return $this->params->get("api_key");
        } else {
            return $this->params->get("test_api_key");
        }
    }
}
