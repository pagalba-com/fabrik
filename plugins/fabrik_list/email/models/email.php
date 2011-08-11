<?php

/**
 * @package Joomla
 * @subpackage Fabrik
 * @copyright Copyright (C) 2005 Rob Clayburn. All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
 */

// Check to ensure this file is included in Joomla!
defined('_JEXEC') or die();

require_once(COM_FABRIK_FRONTEND.DS.'models'.DS.'plugin-list.php');

class plgFabrik_ListEmail extends plgFabrik_List {

	var $useMocha = true;

	var $_buttonPrefix = 'emailtable';

 function onPopupwin(){
	echo ' hre lklfsd k popupwin';
}

	function getAclParam()
	{
		return 'emailtable_access';
	}

	function button()
	{
		return "email records";
	}

	function button_result($c)
	{
		if ($this->canUse()) {
			$params =& $this->getParams();
			$loc = $params->get('emailtable_button_location', 'bottom');
			if ($loc == 'bottom' || $loc == 'both') {
				return $this->getButton();
			} else {
				return '';
			}
		}
	}

	protected function getButton()
	{
		$params = $this->getParams();
		$access = $params->get('emailtable_access');
		$canuse = FabrikWorker::getACL($access, $name);
		$name = $this->_getButtonName();
		if ($canuse) {
			return "<a href=\"#\" class=\"listplugin $name\"/>".$params->get('email_button_label',  JText::_('PLG_LIST_EMAIL_EMAIL'))."</a>";
		}
		return '';
	}

	/**
	 * get the position for the button
	 */

	protected function getRenderLocation()
	{
		return $this->getParams()->get('emailtable_button_location', 'bottom');
	}

	/**
	 * return the javascript to create an instance of the class defined in formJavascriptClass
	 * @param object parameters
	 * @param object table model
	 * @param array [0] => string table's form id to contain plugin
	 * @return bool
	 */

	function onLoadJavascriptInstance($params, $model, $args)
	{
		parent::onLoadJavascriptInstance($params, $model, $args);
		$opts = new stdClass();
		$opts->name = $this->_getButtonName();
		$opts->listid = $model->getId();
		$opts->renderOrder = $this->renderOrder;
		$opts = json_encode($opts);
		$this->jsInstance = "new FbListEmail($opts)";
		return true;
	}

	function getToField()
	{
		//$this->_type = 'table';
		$this->_id = JRequest::getInt('id');
		$params =& $this->getParams();
		$renderOrder = JRequest::getInt('renderOrder');
		$toType = $params->get('emailtable_to_type');
		$toType = is_array($toType) ? $toType[$renderOrder] : $toType;
		if ($toType == 'field') {
			$to = $params->get('emailtable_to');
			$to = is_array($to) ? $to[$renderOrder] : $to;
			return "<input name=\"order_by\" id=\"order_by\" value=\"".$to."\" readonly=\"true\" />";
		} else {
			return $this->formModel->getElementList('order_by');
		}
	}

	public function getAllowAttachment()
	{
		$renderOrder = JRequest::getInt('renderOrder');
		$params =& $this->getParams();
		$allow = $params->get('emailtable_allow_attachment');
		return $allow[$renderOrder];
	}

	public function getSubject()
	{
		$renderOrder = JRequest::getInt('renderOrder');
		$params =& $this->getParams();
		$var = $params->get('email_subject');
		return is_array($var) ? $var[$renderOrder] : $var;
	}

	public function getMessage()
	{
		$renderOrder = JRequest::getInt('renderOrder');
		$params =& $this->getParams();
		$var = $params->get('email_message');
		return is_array($var) ? $var[$renderOrder] : $var;
	}

	/**
	 * get the selected records
	 * @param string $key
	 * @param bool $allData
	 * @return array rows:
	 */
	
	public function getRecords($key = 'ids', $allData = false)
	{
		$ids = (array)JRequest::getVar($key, array());
		JArrayHelper::toInteger($ids);
		if (empty($ids)) {
			JError::raiseError(400, JText::_('PLG_LIST_EMAIL_ERR_NO_RECORDS_SELECTED'));
			jexit();
		}
		$renderOrder = JRequest::getInt('renderOrder');
		$params =& $this->getParams();
		$model = $this->listModel;
		$pk = $model->getTable()->db_primary_key;
		$pk2 = FabrikString::safeColNameToArrayKey($pk).'_raw';
		$whereClause = "($pk IN (" . implode(",", $ids). "))";
		$cond = $params->get('emailtable_condition');
		$cond = JArrayHelper::getValue($cond, $renderOrder);
		if (trim($cond) !== '') {
			$whereClause .= " AND ($cond)";
		}
		$model->setPluginQueryWhere($this->_buttonPrefix, $whereClause);
		$data = $model->getData();
		if ($allData) {
			return $data;
		}
		$return = array();
		foreach ($data as $gdata) {
			foreach($gdata as $row) {
				$return[] = $row->$pk2;
			}
		}
		return $return;
	}

	/**
	 * upload the attachments to the server
	 * @access private
	 *
	 * @return bol success/fail
	 */

	function _upload()
	{
		jimport('joomla.filesystem.file');
		jimport('joomla.client.helper');
		JClientHelper::setCredentialsFromRequest('ftp');
		$files = JRequest::getVar('attachement', array(), 'files');
		$folder = JPATH_ROOT.DS.'images'.DS.'stories';
		$this->filepath = array();
		$c = 0;
		if (array_key_exists('name', $files)) {
			foreach ($files['name'] as $name) {
				if ($name == '') {
					continue;
				}
				$path = $folder.DS.strtolower($name);
				if (!JFile::upload($files['tmp_name'][$c], $path)) {
					JError::raiseWarning(100, JText::_('PLG_LIST_EMAIL_ERR_CANT_UPLOAD_FILE'));
					return false;
				} else {
					$this->filepath[] = $path;
				}
				$c ++;
			}
		}
		return true;
	}

	public function doEmail()
	{
		$listModel = $this->listModel;
		$app = JFactory::getApplication();
		jimport('joomla.mail.helper');
		if (!$this->_upload()) {
			return false;
		}

		$listModel->setId(JRequest::getInt('id', 0));
		$w = new FabrikWorker();
		$config = JFactory::getConfig();
		
		$params =& $this->getParams();
		$to = JRequest::getVar('order_by');
		$renderOrder = JRequest::getInt('renderOrder');
		$toType = $params->get('emailtable_to_type', 'list');
		$fromUser = $params->get('emailtable_from_user');
		if ($toType == 'list') {
			$to = str_replace('.', '___', $to);
		}
		$subject = JRequest::getVar('subject');
		$message = JRequest::getVar('message', '', 'post', 'string', 4);
		$data = $this->getRecords('recordids', true);
		if ($fromUser) {
			$my =& JFactory::getUser();
			$from = $my->get('email');
			$fromname = $my->get('name');
		} else {
			$from = $config->getValue('mailfrom');
			$fromname = $config->getValue('fromname');
		}

		$email_from = $config->getValue('mailfrom');
		$cc = null;
		$bcc = null;
		$sent = 0;
		$notsent = 0;
		$updated = array();
		foreach ($data as $group) {
			foreach ($group as $row) {

				if ($toType == 'list') {
					$process = isset($row->$to);
					$mailto = $row->$to;
				} else {
					$process = true;
					$mailto = $to;
				}
				if ($process) {
					if (JMailHelper::isEmailAddress($mailto)) {
						$thissubject = $w->parseMessageForPlaceholder($subject, $row);
						$thismessage = $w->parseMessageForPlaceholder($message, $row);
						$res = JUtility::sendMail($email_from, $email_from, $mailto, $thissubject, $thismessage, 1, $cc, $bcc, $this->filepath);
						if ($res) {
							$updated[] = $row->__pk_val;
							$sent ++;
						} else {
							$notsent ++;
						}
					} else {
						$notsent ++;
					}
				} else {
					$notsent ++;
				}
			}
		}
		if (!empty($updated)) {
			$updateField = $params->get('emailtable_update_field');
			$updateVal = $params->get('emailtable_update_value');
			$listModel->updateRows($updated, $updateField, $updateVal);
		}
		$app->enqueueMessage(JText::sprintf('PLG_LIST_EMAIL_N_SENT', $sent));
		if ($notsent != 0) {
			JError::raiseWarning(E_NOTICE, JText::sprintf('PLG_LIST_EMAIL_N_NOT_SENT', $notsent));
		}
	}

}
?>