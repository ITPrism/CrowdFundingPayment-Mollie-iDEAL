<?php
/**
 * @package      CrowdFunding
 * @subpackage   Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2013 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

// no direct access
defined('_JEXEC') or die;

jimport('joomla.plugin.plugin');

/**
 * CrowdFunding Mollie iDEAL Payment Plugin
 *
 * @package      CrowdFunding
 * @subpackage   Plugins
 */
class plgCrowdFundingPaymentMollieIdeal extends JPlugin {
    
    /**
     * This method prepares a payment gateway - buttons, forms,...
     * That gateway will be displayed on the summary page as a payment option.
     *
     * @param string 	$context	This string gives information about that where it has been executed the trigger.
     * @param object 	$item	    A project data.
     * @param JRegistry $params	    The parameters of the component
     */
    public function onProjectPayment($context, $item, $params) {
        
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
       
        if(strcmp("com_crowdfunding.payment", $context) != 0){
            return;
        }
        
        // Load language
        $this->loadLanguage();
        
        // This is a URI path to the plugin folder
        $pluginURI = "plugins/crowdfundingpayment/mollieideal";
        
        // Load the script that initialize the select element with banks.
        if(version_compare(JVERSION, "3", ">=")) {
            JHtml::_("jquery.framework");
        }
        $doc->addScript($pluginURI."/js/plg_crowdfundingpayment_mollieideal.js");
        
        // Check for valid partner ID
        $partnerId = $this->params->get("partner_id");
        
        $html  =  array();
        $html[] = '<h4><img src="'.$pluginURI.'/images/ideal_icon.png" />'.JText::_("PLG_CROWDFUNDINGPAYMENT_MOLLIEIDEAL_TITLE").'</h4>';
        $html[] = '<p>'.JText::_("PLG_CROWDFUNDINGPAYMENT_MOLLIEIDEAL_INFO").'</p>';
        
        if(!$partnerId) {
            $html[] = '<div class="alert">'.JText::_("PLG_CROWDFUNDINGPAYMENT_MOLLIEIDEAL_ERROR_PLUGIN_NOT_CONFIGURED").'</div>';
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
        JDEBUG ? CrowdFundingLog::add(JText::_("PLG_CROWDFUNDINGPAYMENT_MOLLIEIDEAL_DEBUG_BANKS"), "MOLLIEIDEAL_PAYMENT_PLUGIN_DEBUG", $banks) : null;
        
        $selectBankOption = array(
            array(
                    "text"  => JText::_("PLG_CROWDFUNDINGPAYMENT_SELECT_BANK"),
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
        $html[] = '<a href="#" class="btn btn-primary" id="js-continue-mollie" style="display: none;">'.JText::_("PLG_CROWDFUNDINGPAYMENT_MOLLIEIDEAL_CONTINUE_TO_MOLLIE").'</a>';
        
        if($this->params->get('testmode', 1)) {
            $html[] = '<p class="sticky">'.JText::_("PLG_CROWDFUNDINGPAYMENT_MOLLIEIDEAL_WORKS_TESTMODE").'</p>';
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
       
        if(strcmp("com_crowdfunding.notify", $context) != 0){
            return;
        }
        
        // Load language
        $this->loadLanguage();
        
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
        JDEBUG ? CrowdFundingLog::add(JText::_("PLG_CROWDFUNDINGPAYMENT_MOLLIEIDEAL_DEBUG_INTENTION"), "MOLLIEIDEAL_PAYMENT_PLUGIN_DEBUG", $intention->getProperties()) : null;
        
        // Verify gateway. 
        if(!$this->isMollieIdealGateway($intention)) {
            return null;
        }
        
        // Validate request method
        $requestMethod = $app->input->getMethod();
        if(strcmp("GET", $requestMethod) != 0) {
            
            CrowdFundingLog::add(
                JText::_("PLG_CROWDFUNDINGPAYMENT_MOLLIEIDEAL_ERROR_INVALID_REQUEST_METHOD"),
                "MOLLIEIDEAL_PAYMENT_PLUGIN_ERROR",
                JText::sprintf("PLG_CROWDFUNDINGPAYMENT_MOLLIEIDEAL_ERROR_INVALID_TRANSACTION_REQUEST_METHOD", $requestMethod)
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
        JDEBUG ? CrowdFundingLog::add(JText::_("PLG_CROWDFUNDINGPAYMENT_MOLLIEIDEAL_DEBUG_PAYMENT_GATEWAY_OBJECT"), "MOLLIEIDEAL_PAYMENT_PLUGIN_DEBUG", $paymentGateway) : null;
        
        if($paymentGateway->getPaidStatus()) {
            
            // Get currency
            jimport("crowdfunding.currency");
            $currencyId      = $params->get("project_currency");
            $currency        = CrowdFundingCurrency::getInstance($currencyId);
            
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
            JDEBUG ? CrowdFundingLog::add(JText::_("PLG_CROWDFUNDINGPAYMENT_MOLLIEIDEAL_DEBUG_TRANSACTION_DATA"), "MOLLIEIDEAL_PAYMENT_PLUGIN_DEBUG", $validData) : null;
            
            // Get project
            jimport("crowdfunding.project");
            $projectId = JArrayHelper::getValue($validData, "project_id");
            $project   = CrowdFundingProject::getInstance($projectId);
            
            // DEBUG DATA
            JDEBUG ? CrowdFundingLog::add(JText::_("PLG_CROWDFUNDINGPAYMENT_MOLLIEIDEAL_DEBUG_PROJECT_OBJECT"), "MOLLIEIDEAL_PAYMENT_PLUGIN_DEBUG", $project->getProperties()) : null;
            
            // Check for valid project
            if(!$project->getId()) {
                
                // Log data in the database
                CrowdFundingLog::add(
                    JText::_("PLG_CROWDFUNDINGPAYMENT_MOLLIEIDEAL_ERROR_INVALID_PROJECT"),
                    "MOLLIEIDEAL_PAYMENT_PLUGIN_ERROR",
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
            JDEBUG ? CrowdFundingLog::add(JText::_("PLG_CROWDFUNDINGPAYMENT_MOLLIEIDEAL_DEBUG_RESULT_DATA"), "MOLLIEIDEAL_PAYMENT_PLUGIN_DEBUG", $result) : null;
            
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
       
        if(strcmp("com_crowdfunding.notify.mollieideal", $context) != 0){
            return;
        }
        
        // Send email to the administrator
        if($this->params->get("send_admin_mail", 0)) {
        
            $subject = JText::_("PLG_CROWDFUNDINGPAYMENT_MOLLIEIDEAL_NEW_INVESTMENT_ADMIN_SUBJECT");
            $body    = JText::sprintf("PLG_CROWDFUNDINGPAYMENT_MOLLIEIDEAL_NEW_INVESTMENT_ADMIN_BODY", $project->title);
            $return  = JFactory::getMailer()->sendMail($app->getCfg("mailfrom"), $app->getCfg("fromname"), $app->getCfg("mailfrom"), $subject, $body);
            
            // Check for an error.
            if ($return !== true) {
                // Log error
                CrowdFundingLog::add(
                    JText::_("PLG_CROWDFUNDINGPAYMENT_MOLLIEIDEAL_ERROR_MAIL_SENDING_ADMIN"),
                    "MOLLIEIDEAL_PAYMENT_PLUGIN_ERROR"
                );
            }
        }
        
        // Send email to the user
        if($this->params->get("send_user_mail", 0)) {
        
            jimport("itprism.string");
            $amount  = ITPrismString::getAmount($transaction->txn_amount, $transaction->txn_currency);
            
            $user     = JUser::getInstance($project->user_id);
            
             // Send email to the administrator
            $subject = JText::sprintf("PLG_CROWDFUNDINGPAYMENT_MOLLIEIDEAL_NEW_INVESTMENT_USER_SUBJECT", $project->title);
            $body    = JText::sprintf("PLG_CROWDFUNDINGPAYMENT_MOLLIEIDEAL_NEW_INVESTMENT_USER_BODY", $amount, $project->title);
            $return  = JFactory::getMailer()->sendMail($app->getCfg("mailfrom"), $app->getCfg("fromname"), $user->email, $subject, $body);
    		
    		// Check for an error.
    		if ($return !== true) {
    		    // Log error
    		    CrowdFundingLog::add(
		            JText::_("PLG_CROWDFUNDINGPAYMENT_MOLLIEIDEAL_ERROR_MAIL_SENDING_USER"),
		            "MOLLIEIDEAL_PAYMENT_PLUGIN_ERROR"
    		    );
    		}
    		
        }
        
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
            CrowdFundingLog::add(
                JText::_("PLG_CROWDFUNDINGPAYMENT_MOLLIEIDEAL_ERROR_INVALID_TRANSACTION_DATA"),
                "MOLLIEIDEAL_PAYMENT_PLUGIN_ERROR",
                $transaction
            );
            return null;
        }
        
        return $transaction;
    }
    
    /**
     * Validate the reward and update the number of the distributed units.
     * 
     * @param  array $data
     * @return null|CrowdFundingReward
     */
    protected function updateReward(&$data) {
        
        // Get rewards
        jimport("crowdfunding.reward");
        $keys   = array(
        	"id"         => JArrayHelper::getValue($data, "reward_id"), 
        	"project_id" => JArrayHelper::getValue($data, "project_id")
        );
        $reward = new CrowdFundingReward($keys);
        
        // DEBUG DATA
        JDEBUG ? CrowdFundingLog::add(JText::_("PLG_CROWDFUNDINGPAYMENT_MOLLIEIDEAL_DEBUG_RESULT_DATA"), "MOLLIEIDEAL_PAYMENT_PLUGIN_DEBUG", $reward->getProperties()) : null;
        
        // Check for valid reward
        if(!$reward->getId()) {
            
            // Log data in the database
            CrowdFundingLog::add(
                JText::_("PLG_CROWDFUNDINGPAYMENT_MOLLIEIDEAL_ERROR_INVALID_REWARD"),
                "MOLLIEIDEAL_PAYMENT_PLUGIN_ERROR",
                array("data" => $data, "reward object" => $reward->getProperties())
            );
			
			$data["reward_id"] = 0;
			return null;
        }
        
        // Check for valida amount between reward value and payed by user
        $txnAmount = JArrayHelper::getValue($data, "txn_amount");
        if($txnAmount < $reward->getAmount()) {
            
            // Log data in the database
            CrowdFundingLog::add(
                JText::_("PLG_CROWDFUNDINGPAYMENT_MOLLIEIDEAL_ERROR_INVALID_REWARD_AMOUNT"),
                "MOLLIEIDEAL_PAYMENT_PLUGIN_ERROR",
                array("data" => $data, "reward object" => $reward->getProperties())
            );
            
			
			$data["reward_id"] = 0;
			return null;
        }
        
        // Verify the availability of rewards
        if($reward->isLimited() AND !$reward->getAvailable()) {
            
            // Log data in the database
            CrowdFundingLog::add(
                JText::_("PLG_CROWDFUNDINGPAYMENT_MOLLIEIDEAL_ERROR_REWARD_NOT_AVAILABLE"),
                "MOLLIEIDEAL_PAYMENT_PLUGIN_ERROR",
                array("data" => $data, "reward object" => $reward->getProperties())
            );
			
			$data["reward_id"] = 0;
			return null;
        }
        
        // Increase the number of distributed rewards 
        // if there is a limit.
        if($reward->isLimited()) {
            $reward->increaseDistributed();
            $reward->store();
        }
        
        return $reward;
    }
    
    /**
     * Save transaction
     * 
     * @param array               $data
     * @param CrowdFundingProject $project
     * 
     * @return boolean TRUE on success. FALSE on failure.
     */
    public function storeTransaction($data, $project) {
        
        // Get transaction by txn ID
        jimport("crowdfunding.transaction");
        $keys = array(
            "txn_id" => JArrayHelper::getValue($data, "txn_id")
        );
        $transaction = new CrowdFundingTransaction($keys);
        
        // DEBUG DATA
        JDEBUG ? CrowdFundingLog::add(JText::_("PLG_CROWDFUNDINGPAYMENT_MOLLIEIDEAL_DEBUG_RESULT_DATA"), "MOLLIEIDEAL_PAYMENT_PLUGIN_DEBUG", $transaction->getProperties()) : null;
        
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
    private function isMollieIdealGateway($intention) {
        
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
    private function prepareBanks($data) {
        
        $banks = array();
        foreach($data as $key => $name) {
            $banks[] = array(
                "text"  => $name,
                "value" => $key
            );
        }
        
        return $banks;
    }
    
}