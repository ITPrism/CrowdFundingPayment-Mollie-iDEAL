<?php
/**
 * @package      CrowdFunding
 * @subpackage   Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2014 Todor Iliev <todor@itprism.com>. All rights reserved.
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
class plgCrowdFundingPaymentMollieIdeal extends CrowdFundingPaymentPlugin {

    protected   $version        = "1.7";
    protected   $paymentService = "mollieideal";
    
    protected   $textPrefix   = "PLG_CROWDFUNDINGPAYMENT_MOLLIEIDEAL";
    protected   $debugType    = "MOLLIEIDEAL_PAYMENT_PLUGIN_DEBUG";
    
    /**
     * This method prepares a payment gateway - buttons, forms,...
     * That gateway will be displayed on the summary page as a payment option.
     *
     * @param string 	$context	This string gives information about that where it has been executed the trigger.
     * @param object 	$item	    A project data.
     * @param JRegistry $params	    The parameters of the component
     */
    public function onProjectPayment($context, $item, $params) {
        
        if(strcmp("com_crowdfunding.payment", $context) != 0){
            return;
        }
        
        $app = JFactory::getApplication();
        /** @var $app JSite **/

        if($app->isAdmin()) {
            return;
        }

        $doc     = JFactory::getDocument();
        /**  @var $doc JDocumentHtml **/
        
        // Check document type
        $docType = $doc->getType();
        if(strcmp("html", $docType) != 0){
            return;
        }
       
        // This is a URI path to the plugin folder
        $pluginURI = "plugins/crowdfundingpayment/mollieideal";
        
        // Load the script that initialize the select element with banks.
        if(version_compare(JVERSION, "3", ">=")) {
            JHtml::_("jquery.framework");
        }
        $doc->addScript($pluginURI."/js/plg_crowdfundingpayment_mollieideal.js?v=".urlencode($this->version));
        
        // Check for valid partner ID
        $partnerId = $this->params->get("partner_id");
        
        $html  =  array();
        $html[] = '<h4><img src="'.$pluginURI.'/images/ideal_icon.png" />'.JText::_($this->textPrefix."_TITLE").'</h4>';
        $html[] = '<p>'.JText::_($this->textPrefix."_INFO").'</p>';
        
        if(!$partnerId) {
            $html[] = '<div class="alert">'.JText::_($this->textPrefix."_ERROR_PLUGIN_NOT_CONFIGURED").'</div>';
            return implode("\n", $html);
        }
        
        jimport("itprism.payment.mollie.ideal");
        $paymentGateway = new ITPrismPaymentMollieIdeal($partnerId);
        
        // Enable test mode
        if($this->params->get('testmode', 1)) {
            $paymentGateway->enableTestmode();
        }
        
        // Get banks
        $banks = $paymentGateway->getBanks();
        $banks = $this->prepareBanks($banks);
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix."_DEBUG_BANKS"), $this->debugType, $banks) : null;
        
        $selectBankOption = array(
            array(
                "text"  => JText::_($this->textPrefix."_SELECT_BANK"),
                "value" => ""
            )
        );
        $banks = array_merge($selectBankOption, $banks);
        
        $html[] = '<select name="bank_id" id="js-mollieideal-bank-id" data-project-id="'.(int)$item->id.'" data-reward-id="'.(int)$item->rewardId.'" data-amount="'.$item->amount.'" >';
        $html[] = JHtml::_("select.options", $banks);
        $html[] = '</select>';
        
        $html[] = '<div class="alert hide" id="js-mollie-ideal-alert"></div>';
        
        $html[] = '<div class="clearfix"></div>';
        $html[] = '<img src="media/com_crowdfunding/images/ajax-loader.gif" width="16" height="16" id="js-mollie-ajax-loading" style="display: none;" />';
        $html[] = '<a href="#" class="btn btn-primary" id="js-continue-mollie" style="display: none;">'.JText::_($this->textPrefix."_CONTINUE_TO_MOLLIE").'</a>';
        
        if($this->params->get('testmode', 1)) {
            $html[] = '<p class="sticky">'.JText::_($this->textPrefix."_WORKS_TESTMODE").'</p>';
        }
        
        return implode("\n", $html);
        
    }
    
    /**
     * This method processes transaction data that comes from payment gateway.
     *  
     * @param string 	$context	This string gives information about that where it has been executed the trigger.
     * @param JRegistry $params	    The parameters of the component
     * 
     * @return array|null
     */
    public function onPaymenNotify($context, $params) {
        
        if(strcmp("com_crowdfunding.notify.mollieideal", $context) != 0){
            return;
        }
        
        $app = JFactory::getApplication();
        /** @var $app JSite **/
        
        if($app->isAdmin()) {
            return;
        }

        $doc     = JFactory::getDocument();
        /**  @var $doc JDocumentHtml **/
        
        // Check document type
        $docType = $doc->getType();
        if(strcmp("raw", $docType) != 0){
            return;
        }
       
        // Get transaction ID
        $transactionId    = $app->input->get("transaction_id");
        if(!$transactionId) {
            return;
        }
        
        // Get intention data
        $keys = array(
            "txn_id" => $transactionId
        );
        jimport("crowdfunding.intention");
        $intention = new CrowdFundingIntention($keys);
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix."_DEBUG_INTENTION"), $this->debugType, $intention->getProperties()) : null;
        
        // Verify gateway. 
        if(!$this->isMollieIdealGateway($intention)) {
            return null;
        }
        
        // Validate request method
        $requestMethod = $app->input->getMethod();
        if(strcmp("GET", $requestMethod) != 0) {
            
            $this->log->add(
                JText::_($this->textPrefix."_ERROR_INVALID_REQUEST_METHOD"),
                $this->debugType,
                JText::sprintf($this->textPrefix."_ERROR_INVALID_TRANSACTION_REQUEST_METHOD", $requestMethod)
            );
            
            return;
        }
        
        // Get partner ID
        $partnerId        = $this->params->get("partner_id");
        
        // Prepare the array that will be returned by this method
        $result = array(
        	"project"          => null, 
        	"reward"           => null, 
        	"transaction"      => null,
            "payment_service"  => "Mollie iDEAL"
        );
        
        jimport("itprism.payment.mollie.ideal");
        $paymentGateway = new ITPrismPaymentMollieIdeal($partnerId);
        
        // Enable test mode
        if($this->params->get('testmode', 1)) {
            $paymentGateway->enableTestmode();
        }
        
        $paymentGateway->checkPayment($transactionId);
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix."_DEBUG_PAYMENT_GATEWAY_OBJECT"), $this->debugType, $paymentGateway) : null;
        
        if($paymentGateway->getPaidStatus()) {
            
            // Get currency
            jimport("crowdfunding.currency");
            $currencyId      = $params->get("project_currency");
            $currency        = CrowdFundingCurrency::getInstance(JFactory::getDbo(), $currencyId);
            
            // Prepare the transaction data that will be validated.
            $transactionData = array(
                "txn_currency" => $currency->getAbbr(),
                "txn_amount"   => $paymentGateway->getAmount() / 100, // The amount, which we received from Mollie, is multiplied by one hundred. So, we must divide it by 100. 
                "project_id"   => $intention->getProjectId(),
                "user_id"      => $intention->getUserId(),
                "reward_id"    => ($intention->isAnonymous()) ? 0 : $intention->getRewardId(), // Set reward ID to 0 because anonymous users cannot sellect reward.
                "txn_id"       => $paymentGateway->getTransactionId()
            );
            
            // Set completed because the paid status is TRUE.
            $transactionData["txn_status"]   = "completed";
            
            // Set real status ( the bank payment status ) and consumer name as additional data.
            $consumerData    = $paymentGateway->getConsumerInfo();
            $transactionData["extra_data"]   = array(
                "status"        => $paymentGateway->getBankStatus(),
                "consumer_name" => JArrayHelper::getValue($consumerData, "consumerName")
            );
            
            // Validate transaction data
            $validData = $this->validateData($transactionData);
            if(is_null($validData)) {
                return $result;
            }
            
            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix."_DEBUG_TRANSACTION_DATA"), $this->debugType, $validData) : null;
            
            // Get project
            jimport("crowdfunding.project");
            $projectId = JArrayHelper::getValue($validData, "project_id");
            $project   = CrowdFundingProject::getInstance($projectId);
            
            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix."_DEBUG_PROJECT_OBJECT"), $this->debugType, $project->getProperties()) : null;
            
            // Check for valid project
            if(!$project->getId()) {
                
                // Log data in the database
                $this->log->add(
                    JText::_($this->textPrefix."_ERROR_INVALID_PROJECT"),
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
            if(!$this->storeTransaction($validData, $project)) {
                return $result;
            }
            
            // Validate and Update distributed value of the reward
            $rewardId  = JArrayHelper::getValue($validData, "reward_id");
            $reward    = null;
            if(!empty($rewardId)) {
                $reward = $this->updateReward($validData);
            }
            
            //  Prepare the data that will be returned
            
            $result["transaction"]    = JArrayHelper::toObject($validData);
            
            // Generate object of data based on the project properties
            $properties               = $project->getProperties();
            $result["project"]        = JArrayHelper::toObject($properties);
            
            // Generate object of data based on the reward properties
            if(!empty($reward)) {
                $properties           = $reward->getProperties();
                $result["reward"]     = JArrayHelper::toObject($properties);
            }
            
            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix."_DEBUG_RESULT_DATA"), $this->debugType, $result) : null;
            
            // Remove intention
            $intention->delete();
            unset($intention);
            
        }
        
        return $result;
                
    }
    
    /**
     * This metod is executed after complete payment.
     * It is used to be sent mails to user and administrator
     * 
     * @param stdObject  Transaction data
     * @param JRegistry  Component parameters
     * @param stdObject  Project data
     * @param stdObject  Reward data
     */
    public function onAfterPayment($context, &$transaction, $params, $project, $reward) {
        
        if(strcmp("com_crowdfunding.notify.mollieideal", $context) != 0){
            return;
        }
        
        $app = JFactory::getApplication();
        /** @var $app JSite **/
        
        if($app->isAdmin()) {
            return;
        }

        $doc     = JFactory::getDocument();
        /**  @var $doc JDocumentHtml **/
        
        // Check document type
        $docType = $doc->getType();
        if(strcmp("raw", $docType) != 0){
            return;
        }
       
        // Send mails
        $this->sendMails($project, $transaction);
        
    }
    
    public function onPaymentsPreparePayment($context, $params) {
    
        if(strcmp("com_crowdfunding.payments.preparepayment.mollieideal", $context) != 0){
            return;
        }
        
        $app = JFactory::getApplication();
        /** @var $app JSite **/
    
        if($app->isAdmin()) {
            return;
        }
    
        $doc     = JFactory::getDocument();
        /**  @var $doc JDocumentHtml **/
    
        // Check document type
        $docType = $doc->getType();
        if(strcmp("raw", $docType) != 0){
            return;
        }

        $partnerId = $this->params->get("partner_id");
        
        $projectId = $app->input->getInt("pid");
        $bankId    = $app->input->getAlnum("bank_id");
        $amount    = $app->input->get("amount", 0, "float");
        
        $uri        = JUri::getInstance();
        $domain     = $uri->toString(array("host"));
        
        $paymentOptions = array(
            "bank_id"      => $bankId,
            "amount"       => $amount * 100,
            "description"  => "",
            "return_url"   => "",
            "report_url"   => "",
        );
    
        jimport("itprism.payment.mollie.ideal");
        $paymentGateway = new ITPrismPaymentMollieIdeal($partnerId);
        
        // Enable test mode
        if($this->params->get('testmode', 1)) {
            $paymentGateway->enableTestmode();
        }
        
        // Get project
        jimport("crowdfunding.project");
        $project    = new CrowdFundingProject($projectId);
        
        if(!$project->getId()) {
            $response = array(
                "success" => false,
                "title"   => JText::_($this->textPrefix."_FAIL"),
                "text"    => JText::_($this->textPrefix."_ERROR_INVALID_PROJECT")
            );
        
            return $response;
        }
        
        // Prepare description
        $paymentOptions["description"] = JString::substr($project->getTitle(), 0, 29);
        
        // Prepare return URL
        $returnUrl = JString::trim($this->params->get('returnurl'));
        if(!$returnUrl) {
            $returnUrl = $uri->toString(array("scheme", "host")).JRoute::_(CrowdFundingHelperRoute::getBackingRoute($project->getSlug(), $project->getCatslug(), "share"), false);
        }
        $paymentOptions["return_url"] = $returnUrl;
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix."_DEBUG_RETURN_URL"), $this->debugType, $returnUrl) : null;
        
        // Prepare report URL
        $reportUrl = $this->getReportUrl();
        $paymentOptions["report_url"] = $reportUrl;
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix."_DEBUG_PAYMENT_OPTIONS"), $this->debugType, $paymentOptions) : null;
        
        $paymentGateway->createPayment($paymentOptions);
        
        $url   = $paymentGateway->getBankURL();
        $txnId = $paymentGateway->getTransactionId();
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix."_DEBUG_PAYMENT_GATEWAY_OBJECT"), $this->debugType, $paymentGateway) : null;
        
        //  INTENTIONS
        
        // Prepare custom data
        
        $rewardId     = $app->input->get("reward_id");
        $userId       = JFactory::getUser()->id;
        $aUserId      = $app->getUserState("auser_id");
        
        $intention    = $this->getIntention(array(
            "user_id"       => $userId,
            "auser_id"      => $aUserId,
            "project_id"    => $projectId
        ));
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix."_DEBUG_INTENTION"), $this->debugType, $intention->getProperties()) : null;
        
        // Prepare intention data.
        $intentionData = array(
            "txn_id"        => $txnId,
            "gateway"       => "Mollie iDEAL",
        );
        
        // Set main data if it is a new intention.
        if(!$intention->getId()) {
        
            $recordDate = new JDate();
        
            $intentionData["user_id"]     = $userId;
            $intentionData["auser_id"]    = $aUserId; // This is hash user ID used for anonymous users.
            $intentionData["project_id"]  = $projectId;
            $intentionData["reward_id"]   = $rewardId;
            $intentionData["record_date"] = $recordDate->toSql();
        
        }
        
        $intention->bind($intentionData);
        $intention->store();
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix."_DEBUG_INTENTION_AS"), $this->debugType, $intention->getProperties()) : null;
        
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
     * @param array This is a transaction data, that comes from the payment gateway.
     * 
     */
    protected function validateData($data) {
        
        $date    = new JDate();
        
        // Prepare transaction data
        $transaction = array(
            "investor_id"		     => JArrayHelper::getValue($data, "user_id", 0, "int"),
            "project_id"		     => JArrayHelper::getValue($data, "project_id", 0, "int"),
            "reward_id"			     => JArrayHelper::getValue($data, "reward_id", 0, "int"),
        	"txn_id"                 => JArrayHelper::getValue($data, "txn_id"),
        	"txn_amount"		     => JArrayHelper::getValue($data, "txn_amount"),
            "txn_currency"           => JArrayHelper::getValue($data, "txn_currency"),
            "txn_status"             => JArrayHelper::getValue($data, "txn_status"),
            "txn_date"               => $date->toSql(),
            "extra_data"             => JArrayHelper::getValue($data, "extra_data"),
            "service_provider"       => "Mollie iDEAL",
        ); 
        
        // Check User Id, Project ID and Transaction ID
        if(!$transaction["project_id"] OR !$transaction["txn_id"]) {
            // Log data in the database
            $this->log->add(
                JText::_($this->textPrefix."_ERROR_INVALID_TRANSACTION_DATA"),
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
     * @param array               $data
     * @param CrowdFundingProject $project
     * 
     * @return boolean TRUE on success. FALSE on failure.
     */
    protected function storeTransaction($data, $project) {
        
        // Get transaction by txn ID
        jimport("crowdfunding.transaction");
        $keys = array(
            "txn_id" => JArrayHelper::getValue($data, "txn_id")
        );
        $transaction = new CrowdFundingTransaction($keys);
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix."_DEBUG_RESULT_DATA"), $this->debugType, $transaction->getProperties()) : null;
        
        // Check for valid transaction
        if($transaction->getId()) {
            
            // If the current status is completed,
            // stop the process.
            if($transaction->isCompleted()) {
                return false;
            } 
            
        }
        
        // Encode extra data
        if(!empty($data["extra_data"])) {
            $data["extra_data"] = json_encode($data["extra_data"]);
        } else {
            $data["extra_data"] = null;
        }

        // Store the new transaction data.
        $transaction->bind($data);
        $transaction->store();
        
        $txnStatus = JArrayHelper::getValue($data, "txn_status");
        
        // If it is not completed (it might be pending or other status),
        // stop the process. Only completed transaction will continue 
        // and will process the project, rewards,...
        $txnStatus = JArrayHelper::getValue($data, "txn_status");
        if(!$transaction->isCompleted()) {
            return false;
        }
        
        // If the new transaction is completed, 
        // update project funded amount.
        $amount = JArrayHelper::getValue($data, "txn_amount");
        $project->addFunds($amount);
        $project->store();
        
        return true;
    }
    
    /**
     * Validate payment gateway.
     * 
     * @param CrowdFundingIntention $intention
     * @return boolean
     */
    protected function isMollieIdealGateway($intention) {
        
        $gateway = $intention->getGateway();
        
        if(strcmp("Mollie iDEAL", $gateway) != 0 ) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Prepare the date of banks to array, which will be used as options.
     * 
     * @param array $data
     * @return multitype:multitype:unknown
     */
    protected function prepareBanks($data) {
        
        $banks = array();
        foreach($data as $key => $name) {
            $banks[] = array(
                "text"  => $name,
                "value" => $key
            );
        }
        
        return $banks;
    }
    
    protected function getReportUrl() {
    
        $page       = JString::trim($this->params->get('reporturl'));
    
        $uri        = JURI::getInstance();
        $domain     = $uri->toString(array("host"));
    
        if( false == strpos($page, $domain) ) {
            $page = JURI::root().$page;
        }
    
        if(false === strpos($page, "payment_service=mollieideal")) {
            $page .= "&payment_service=mollieideal";
        }
    
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix."_DEBUG_REPORT_URL"), $this->debugType, $page) : null;
    
        return $page;
    
    }
}