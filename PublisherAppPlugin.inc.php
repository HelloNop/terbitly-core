<?php

/**
 * @file plugins/generic/publisherApp/PublisherAppPlugin.inc.php
 *
 * @class PublisherAppPlugin
 * @ingroup plugins_generic_publisherApp
 *
 * @brief Connects one OJS journal to the Publisher App (prototype, OJS 3.3).
 *
 * - Sends every new submission to the app the moment the author submits it.
 * - Carries a promoter's referral code: from a ?ref= link (kept in a cookie through sign-up and
 *   sign-in) or typed in the submission form. The code goes only to the app; nothing in OJS shows
 *   it, so editors and reviewers never see who brought a manuscript.
 * - Adds a "Payments" tab to the submission page of authors and editors, read live from the app:
 *   invoices with their pay link, and the LOA, receipt and certificate once issued.
 *
 * Every request is signed with the journal's token: "<key id>.<secret>", made in the app.
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class PublisherAppPlugin extends GenericPlugin {

	const REFERRAL_COOKIE = 'publisherAppRef';
	const REFERRAL_PATTERN = '/^[A-Za-z0-9-]{2,32}$/';

	/**
	 * @copydoc Plugin::register()
	 */
	function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);
		if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) return true;
		if ($success && $this->getEnabled($mainContextId)) {
			HookRegistry::register('LoadHandler', array($this, 'rememberReferral'));
			HookRegistry::register('Templates::Submission::SubmissionMetadataForm::AdditionalMetadata', array($this, 'addReferralField'));
			HookRegistry::register('submissionsubmitstep3form::readuservars', array($this, 'readReferralField'));
			HookRegistry::register('submissionsubmitstep3form::execute', array($this, 'keepReferral'));
			HookRegistry::register('submissionsubmitstep4form::execute', array($this, 'sendSubmission'));
			HookRegistry::register('Template::Workflow', array($this, 'addPaymentsTab'));
		}
		return $success;
	}

	function getDisplayName() {
		return __('plugins.generic.publisherApp.displayName');
	}

	function getDescription() {
		return __('plugins.generic.publisherApp.description');
	}

	/**
	 * @copydoc Plugin::getActions()
	 */
	function getActions($request, $verb) {
		$router = $request->getRouter();
		import('lib.pkp.classes.linkAction.request.AjaxModal');
		return array_merge(
			$this->getEnabled() ? array(
				new LinkAction(
					'settings',
					new AjaxModal(
						$router->url($request, null, null, 'manage', null, array('verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic')),
						$this->getDisplayName()
					),
					__('manager.plugins.settings'),
					null
				),
			) : array(),
			parent::getActions($request, $verb)
		);
	}

	/**
	 * @copydoc Plugin::manage()
	 */
	function manage($args, $request) {
		if ($request->getUserVar('verb') !== 'settings') {
			return parent::manage($args, $request);
		}
		$context = $request->getContext();
		AppLocale::requireComponents(LOCALE_COMPONENT_APP_COMMON, LOCALE_COMPONENT_PKP_MANAGER);
		$this->import('PublisherAppSettingsForm');
		$form = new PublisherAppSettingsForm($this, $context->getId());

		if ($request->getUserVar('save')) {
			$form->readInputData();
			if ($form->validate()) {
				$form->execute();
				return new JSONMessage(true);
			}
		} else {
			$form->initData();
		}
		return new JSONMessage(true, $form->fetch($request));
	}

	// ---------- Referral code ----------

	/**
	 * Any page opened with ?ref=CODE keeps the code in a cookie for 30 days, so it survives
	 * signing up and signing in before the author reaches the submission form.
	 */
	function rememberReferral($hookName, $args) {
		$code = (string) Application::get()->getRequest()->getUserVar('ref');
		if (preg_match(self::REFERRAL_PATTERN, $code)) {
			setcookie(self::REFERRAL_COOKIE, strtoupper($code), array(
				'expires' => time() + 30 * 86400,
				'path' => '/',
				'httponly' => true,
				'samesite' => 'Lax',
			));
			$_COOKIE[self::REFERRAL_COOKIE] = strtoupper($code);
		}
		return false;
	}

	/** Step 3 of the submission wizard: an optional field, filled from the ?ref= link when there was one. */
	function addReferralField($hookName, $args) {
		$smarty =& $args[1];
		$output =& $args[2];
		$request = Application::get()->getRequest();
		$submissionId = (int) $request->getUserVar('submissionId');

		$smarty->assign('publisherAppReferral', $this->referralFor($request, $submissionId));
		$output .= $smarty->fetch($this->getTemplateResource('referralField.tpl'));
		return false;
	}

	function readReferralField($hookName, $args) {
		$vars =& $args[1];
		$vars[] = 'publisherAppReferral';
		return false;
	}

	/** Kept in the author's session until the submission is sent; never saved on the submission itself. */
	function keepReferral($hookName, $args) {
		$form = $args[0];
		$code = strtoupper(trim((string) $form->getData('publisherAppReferral')));
		$session = Application::get()->getRequest()->getSession();
		$session->setSessionVar('publisherAppReferral' . $form->submissionId, preg_match(self::REFERRAL_PATTERN, $code) ? $code : '');
		return false;
	}

	/** The code typed in the form wins over the ?ref= cookie, even when the author cleared it. */
	function referralFor($request, $submissionId) {
		$typed = $request->getSession()->getSessionVar('publisherAppReferral' . $submissionId);
		if ($typed !== null) return $typed;
		$cookie = isset($_COOKIE[self::REFERRAL_COOKIE]) ? (string) $_COOKIE[self::REFERRAL_COOKIE] : '';
		return preg_match(self::REFERRAL_PATTERN, $cookie) ? $cookie : '';
	}

	// ---------- New submission ----------

	/** Step 4 confirmed: the submission goes to the app right away. */
	function sendSubmission($hookName, $args) {
		$form = $args[0];
		$submission = $form->submission;
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		if (!$submission || !$context) return false;

		$this->sendEvent($context->getId(), 'submission.submitted', $submission->getId(), $this->submissionPayload($request, $context, $submission));
		$request->getSession()->unsetSessionVar('publisherAppReferral' . $submission->getId());
		return false;
	}

	/** The new manuscript: title, section, authors in order, the referral code and the link back to OJS. */
	function submissionPayload($request, $context, $submission) {
		$publication = $submission->getCurrentPublication();
		$section = DAORegistry::getDAO('SectionDAO')->getById($publication->getData('sectionId'), $context->getId());
		$authors = (array) $publication->getData('authors');
		usort($authors, function ($a, $b) { return (int) $a->getData('seq') - (int) $b->getData('seq'); });

		return array(
			'title' => $publication->getLocalizedTitle(),
			'section' => $section ? $section->getLocalizedTitle() : null,
			'authors' => array_map(function ($author) use ($publication) {
				return array(
					'name' => $author->getFullName(),
					'email' => $author->getEmail(),
					'affiliation' => $author->getLocalizedAffiliation(),
					'country' => $author->getCountry(),
					'corresponding' => (int) $author->getId() === (int) $publication->getData('primaryContactId'),
				);
			}, $authors),
			'referral_code' => $this->referralFor($request, $submission->getId()) ?: null,
			'ojs_url' => $request->getDispatcher()->url($request, ROUTE_PAGE, $context->getPath(), 'workflow', 'access', $submission->getId()),
		);
	}

	/**
	 * One event in the app's shape (POST /api/ojs/events): a fresh id, so the app applies it once
	 * even when it is sent again, the type, the OJS submission and when it happened.
	 */
	function sendEvent($contextId, $type, $submissionId, $data) {
		return $this->post($contextId, '/api/ojs/events', array(
			'event_id' => $this->uuid(),
			'type' => $type,
			'ojs_submission_id' => (int) $submissionId,
			'occurred_at' => date('c'),
			'data' => $data,
		));
	}

	function uuid() {
		$bytes = random_bytes(16);
		$bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
		$bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}

	// ---------- Payments tab ----------

	/** Authors see their invoices and documents; editors see whether the fees are paid. */
	function addPaymentsTab($hookName, $args) {
		$smarty =& $args[1];
		$output =& $args[2];
		$submission = $smarty->getTemplateVars('submission');
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		if (!$submission || !$context) return false;

		$response = $this->get($context->getId(), '/api/ojs/submissions/' . (int) $submission->getId());
		$smarty->assign(array(
			'publisherAppStatus' => $response,
			'publisherAppForAuthor' => $request->getRequestedPage() === 'authorDashboard',
		));
		$output .= $smarty->fetch($this->getTemplateResource('paymentsTab.tpl'));
		return false;
	}

	// ---------- Talking to the app ----------

	/**
	 * A signed request to the app. Headers: the key id, a timestamp, and an HMAC-SHA256 of
	 * "timestamp\nMETHOD\npath\nbody" with the secret. The secret itself never travels.
	 *
	 * ponytail: a failed send is only logged; a retry outbox comes when the real plugin is built.
	 */
	function request($contextId, $method, $path, $body = null) {
		$appUrl = rtrim((string) $this->getSetting($contextId, 'appUrl'), '/');
		$token = (string) $this->getSetting($contextId, 'token');
		if ($appUrl === '' || strpos($token, '.') === false) return null;
		list($keyId, $secret) = explode('.', $token, 2);

		$json = $body === null ? '' : json_encode($body);
		$timestamp = (string) time();
		$signature = hash_hmac('sha256', $timestamp . "\n" . $method . "\n" . $path . "\n" . $json, $secret);

		$curl = curl_init($appUrl . $path);
		curl_setopt_array($curl, array(
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 2,
			CURLOPT_TIMEOUT => 4,
			CURLOPT_HTTPHEADER => array(
				'Accept: application/json',
				'Content-Type: application/json',
				'X-Publisher-Key: ' . $keyId,
				'X-Publisher-Timestamp: ' . $timestamp,
				'X-Publisher-Signature: ' . $signature,
				'X-Publisher-Plugin: ' . $this->getCurrentVersion()->getVersionString(false),
			),
		));
		if ($json !== '') curl_setopt($curl, CURLOPT_POSTFIELDS, $json);
		$raw = curl_exec($curl);
		$status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);

		if ($raw === false || $status >= 400 || $status === 0) {
			error_log('[publisherApp] ' . $method . ' ' . $path . ' failed with status ' . $status);
			return null;
		}
		return json_decode($raw, true);
	}

	function post($contextId, $path, $body) {
		return $this->request($contextId, 'POST', $path, $body);
	}

	function get($contextId, $path) {
		return $this->request($contextId, 'GET', $path);
	}
}
