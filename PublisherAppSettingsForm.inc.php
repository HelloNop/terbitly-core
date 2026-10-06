<?php

/**
 * @file plugins/generic/publisherApp/PublisherAppSettingsForm.inc.php
 *
 * @class PublisherAppSettingsForm
 * @ingroup plugins_generic_publisherApp
 *
 * @brief The app address and this journal's token, both copied from the journal's OJS tab in the app.
 */

import('lib.pkp.classes.form.Form');

class PublisherAppSettingsForm extends Form {

	/** @var int */
	var $_journalId;

	/** @var PublisherAppPlugin */
	var $_plugin;

	function __construct($plugin, $journalId) {
		$this->_journalId = $journalId;
		$this->_plugin = $plugin;

		parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

		// Not FormValidatorUrl: its browser check refuses local addresses such as 127.0.0.1, used while testing.
		$this->addCheck(new FormValidatorRegExp($this, 'appUrl', 'required', 'plugins.generic.publisherApp.settings.appUrlRequired', '#^https?://[^\s/]+#'));
		$this->addCheck(new FormValidatorRegExp($this, 'token', 'required', 'plugins.generic.publisherApp.settings.tokenRequired', '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{16,}$/'));
		$this->addCheck(new FormValidatorPost($this));
		$this->addCheck(new FormValidatorCSRF($this));
	}

	function initData() {
		$this->_data = array(
			'appUrl' => $this->_plugin->getSetting($this->_journalId, 'appUrl'),
			'token' => $this->_plugin->getSetting($this->_journalId, 'token'),
		);
	}

	function readInputData() {
		$this->readUserVars(array('appUrl', 'token'));
	}

	function fetch($request, $template = null, $display = false) {
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign('pluginName', $this->_plugin->getName());
		return parent::fetch($request, $template, $display);
	}

	function execute(...$functionArgs) {
		$this->_plugin->updateSetting($this->_journalId, 'appUrl', rtrim(trim($this->getData('appUrl')), '/'), 'string');
		$this->_plugin->updateSetting($this->_journalId, 'token', trim($this->getData('token')), 'string');
		parent::execute(...$functionArgs);
	}
}
