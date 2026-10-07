<?php

/**
 * @file plugins/generic/publisherApp/PublisherAppPlugin.inc.php
 *
 * @class PublisherAppPlugin
 * @ingroup plugins_generic_publisherApp
 *
 * @brief Connects one OJS journal to the Publisher App (prototype, OJS 3.3).
 *
 * - Sends every new submission to the app the moment the author submits it, then the editors'
 *   decisions (review, accept, decline, production) and publishing, so the app follows OJS.
 * - Carries a promoter's referral code: from a ?ref= link (kept in a cookie through sign-up and
 *   sign-in) or typed in the submission form. The code goes only to the app; nothing in OJS shows
 *   it, so editors and reviewers never see who brought a manuscript.
 * - Adds a "Payments" tab to the submission page of authors and editors, read live from the app:
 *   invoices with their pay link, and the LOA, receipt and certificate once issued.
 *
 * - Sends title and author changes made after submission, so the app's copy follows OJS.
 * - Keeps what could not be sent (app down, server error, wrong token) and sends it again later,
 *   with the same event id so the app applies it once.
 *
 * Every request is signed with the journal's token: "<key id>.<secret>", made in the app.
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class PublisherAppPlugin extends GenericPlugin {

	const REFERRAL_COOKIE = 'publisherAppRef';
	const OUTBOX_LIMIT = 200;
	const OUTBOX_RETRY_SECONDS = 300;

	/** @var array Submissions whose title or authors changed in this request, sent once at the end. */
	var $metadataChanged = array();
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
			HookRegistry::register('EditorAction::recordDecision', array($this, 'sendDecision'));
			HookRegistry::register('Publication::publish', array($this, 'sendPublished'));
			HookRegistry::register('Publication::unpublish', array($this, 'sendUnpublished'));
			HookRegistry::register('Publication::edit', array($this, 'noteMetadataChange'));
			HookRegistry::register('Author::add', array($this, 'noteAuthorChange'));
			HookRegistry::register('Author::edit', array($this, 'noteAuthorChange'));
			HookRegistry::register('Author::delete', array($this, 'noteAuthorChange'));
			HookRegistry::register('LoadHandler', array($this, 'flushOutbox'));
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

	// ---------- Decisions and publishing ----------

	/**
	 * An editor's decision, before OJS saves it. Recommendations are not decisions and are skipped.
	 * Accept moves the submission to Copyediting in the app by itself.
	 */
	function sendDecision($hookName, $args) {
		import('classes.workflow.EditorDecisionActionsManager');
		$submission = $args[0];
		$decision = (int) $args[1]['decision'];
		if ($args[3]) return false;

		$contextId = $submission->getContextId();
		$id = $submission->getId();
		$editor = array('editor' => $this->editorName());

		switch ($decision) {
			case SUBMISSION_EDITOR_DECISION_ACCEPT:
				$this->sendEvent($contextId, 'decision.accepted', $id, $editor);
				break;
			case SUBMISSION_EDITOR_DECISION_DECLINE:
			case SUBMISSION_EDITOR_DECISION_INITIAL_DECLINE:
				$this->sendEvent($contextId, 'decision.declined', $id, $editor);
				break;
			case SUBMISSION_EDITOR_DECISION_REVERT_DECLINE:
				$this->sendEvent($contextId, 'decision.reverted', $id, $editor);
				break;
			case SUBMISSION_EDITOR_DECISION_EXTERNAL_REVIEW:
				$this->sendEvent($contextId, 'submission.stage_changed', $id, array('stage' => 'review'));
				break;
			case SUBMISSION_EDITOR_DECISION_SEND_TO_PRODUCTION:
				$this->sendEvent($contextId, 'submission.stage_changed', $id, array('stage' => 'production'));
				break;
			case SUBMISSION_EDITOR_DECISION_PENDING_REVISIONS:
				$this->sendEvent($contextId, 'review.round_updated', $id, array('round' => $this->reviewRound($id), 'status' => 'revisions_requested'));
				break;
			case SUBMISSION_EDITOR_DECISION_RESUBMIT:
				$this->sendEvent($contextId, 'review.round_updated', $id, array('round' => $this->reviewRound($id), 'status' => 'resubmit_requested'));
				break;
			case SUBMISSION_EDITOR_DECISION_NEW_ROUND:
				$this->sendEvent($contextId, 'review.round_updated', $id, array('round' => (int) $this->reviewRound($id) + 1, 'status' => 'new_round'));
				break;
		}
		return false;
	}

	/** Published in OJS: the app publishes it once the APC allows, with the article's link. */
	function sendPublished($hookName, $args) {
		$submission = $args[2];
		try {
			$request = Application::get()->getRequest();
			$context = Services::get('context')->get($submission->getContextId());
			// The application's dispatcher: a scheduled publish runs without a web request's one.
			$url = Application::get()->getDispatcher()->url($request, ROUTE_PAGE, $context->getPath(), 'article', 'view', $submission->getBestId());
		} catch (Throwable $e) {
			error_log('[publisherApp] no article link for submission ' . $submission->getId() . ': ' . $e->getMessage());
			return false;
		}
		$this->sendEvent($submission->getContextId(), 'submission.published', $submission->getId(), array('article_url' => $url, 'editor' => $this->editorName()));
		return false;
	}

	/** Unpublished in OJS: the app only flags it for the admin. */
	function sendUnpublished($hookName, $args) {
		$submission = $args[2];
		$this->sendEvent($submission->getContextId(), 'submission.unpublished', $submission->getId(), array('editor' => $this->editorName()));
		return false;
	}

	// ---------- Metadata ----------

	/** The publication was edited (title among others). */
	function noteMetadataChange($hookName, $args) {
		$this->noteSubmission((int) $args[1]->getData('submissionId'));
		return false;
	}

	/** An author was added, changed or removed. */
	function noteAuthorChange($hookName, $args) {
		$author = $args[0];
		$publication = Services::get('publication')->get((int) $author->getData('publicationId'));
		if ($publication) $this->noteSubmission((int) $publication->getData('submissionId'));
		return false;
	}

	/**
	 * Remembers a submitted manuscript whose metadata changed. The hooks run before OJS saves, and
	 * an editor often changes several authors at once, so one message goes at the end of the request
	 * with what is saved by then. Submissions still in the wizard are left alone.
	 */
	function noteSubmission($submissionId) {
		if (!$submissionId || isset($this->metadataChanged[$submissionId])) return;
		$this->metadataChanged[$submissionId] = true;
		if (count($this->metadataChanged) === 1) register_shutdown_function(array($this, 'sendMetadata'));
	}

	function sendMetadata() {
		foreach (array_keys($this->metadataChanged) as $submissionId) {
			try {
				$submission = Services::get('submission')->get($submissionId);
				if (!$submission || (int) $submission->getData('submissionProgress') !== 0) continue;
				$this->sendEvent($submission->getContextId(), 'submission.metadata_updated', $submissionId, $this->metadataPayload($submission));
			} catch (Throwable $e) {
				error_log('[publisherApp] metadata of submission ' . $submissionId . ': ' . $e->getMessage());
			}
		}
		$this->metadataChanged = array();
	}

	/** The editor acting now, named in the app because they may have no account there. */
	function editorName() {
		$user = Application::get()->getRequest()->getUser();
		return $user ? $user->getFullName() : null;
	}

	/** The latest external review round, null before the first one. */
	function reviewRound($submissionId) {
		$round = DAORegistry::getDAO('ReviewRoundDAO')->getLastReviewRoundBySubmissionId($submissionId, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW);
		return $round ? (int) $round->getRound() : null;
	}

	// ---------- Payload ----------

	/** The new manuscript: title, section, authors in order, the referral code and the link back to OJS. */
	function submissionPayload($request, $context, $submission) {
		$publication = $submission->getCurrentPublication();
		$section = DAORegistry::getDAO('SectionDAO')->getById($publication->getData('sectionId'), $context->getId());

		return $this->metadataPayload($submission) + array(
			'section' => $section ? $section->getLocalizedTitle() : null,
			'referral_code' => $this->referralFor($request, $submission->getId()) ?: null,
			'ojs_url' => Application::get()->getDispatcher()->url($request, ROUTE_PAGE, $context->getPath(), 'workflow', 'access', $submission->getId()),
		);
	}

	/** Title and authors in byline order, with the corresponding author marked. */
	function metadataPayload($submission) {
		$publication = $submission->getCurrentPublication();
		$authors = (array) $publication->getData('authors');
		usort($authors, function ($a, $b) { return (int) $a->getData('seq') - (int) $b->getData('seq'); });

		return array(
			'title' => $publication->getLocalizedTitle(),
			'authors' => array_map(function ($author) use ($publication) {
				return array(
					'name' => $author->getFullName(),
					'email' => $author->getEmail(),
					'affiliation' => $author->getLocalizedAffiliation(),
					'country' => $author->getCountry(),
					'corresponding' => (int) $author->getId() === (int) $publication->getData('primaryContactId'),
				);
			}, $authors),
		);
	}

	/**
	 * One event in the app's shape (POST /api/ojs/events): a fresh id, so the app applies it once
	 * even when it is sent again, the type, the OJS submission and when it happened.
	 */
	function sendEvent($contextId, $type, $submissionId, $data) {
		$event = array(
			'event_id' => $this->uuid(),
			'type' => $type,
			'ojs_submission_id' => (int) $submissionId,
			'occurred_at' => date('c'),
			'data' => $data,
		);
		// Nothing here may stop the editor's action in OJS: a failure is kept or logged.
		try {
			if (!$this->deliver($contextId, $event)) $this->enqueue($contextId, $event);
		} catch (Throwable $e) {
			error_log('[publisherApp] ' . $type . ' for submission ' . $submissionId . ' failed: ' . $e->getMessage());
		}
	}

	/**
	 * Sends one event. True when the app has it, or refused it for good (a 4xx other than 401: the
	 * event itself is wrong, resending will not help); false when it should be sent again.
	 */
	function deliver($contextId, $event) {
		$response = $this->request($contextId, 'POST', '/api/ojs/events', $event);
		if ($response === false) return false;
		$status = $response['status'];
		if ($status >= 400 && $status < 500 && $status !== 401) {
			error_log('[publisherApp] ' . $event['type'] . ' refused with status ' . $status . ': ' . json_encode($response['body']));
		}
		return $status < 500 && $status !== 401;
	}

	// ---------- Outbox ----------

	function enqueue($contextId, $event) {
		$outbox = (array) json_decode((string) $this->getSetting($contextId, 'outbox'), true);
		$outbox[] = $event;
		$this->updateSetting($contextId, 'outbox', json_encode(array_slice($outbox, -self::OUTBOX_LIMIT)), 'string');
	}

	/** On a page load at most every five minutes: sends what is waiting, oldest first, and stops at the first failure. */
	function flushOutbox($hookName, $args) {
		$context = Application::get()->getRequest()->getContext();
		if ($context) $this->flushOutboxFor($context->getId());
		return false;
	}

	function flushOutboxFor($contextId) {
		$outbox = (array) json_decode((string) $this->getSetting($contextId, 'outbox'), true);
		if (!$outbox || time() - (int) $this->getSetting($contextId, 'outboxTriedAt') < self::OUTBOX_RETRY_SECONDS) return;

		$this->updateSetting($contextId, 'outboxTriedAt', time(), 'int');
		try {
			while ($outbox && $this->deliver($contextId, $outbox[0])) array_shift($outbox);
		} catch (Throwable $e) {
			error_log('[publisherApp] outbox: ' . $e->getMessage());
		}
		$this->updateSetting($contextId, 'outbox', json_encode(array_values($outbox)), 'string');
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

		$forAuthor = $request->getRequestedPage() === 'authorDashboard';
		$response = $this->get($context->getId(), '/api/ojs/submissions/' . (int) $submission->getId() . '?view=' . ($forAuthor ? 'author' : 'editor'));
		$smarty->assign(array(
			'publisherAppStatus' => $response,
			'publisherAppForAuthor' => $forAuthor,
		));
		$output .= $smarty->fetch($this->getTemplateResource('paymentsTab.tpl'));
		return false;
	}

	// ---------- Talking to the app ----------

	/**
	 * A signed request to the app. Headers: the key id, a timestamp, and an HMAC-SHA256 of
	 * "timestamp\nMETHOD\npath\nbody" with the secret. The secret itself never travels.
	 *
	 * Returns the status and body, or false when the app could not be reached or is not set up.
	 */
	function request($contextId, $method, $path, $body = null) {
		$appUrl = rtrim((string) $this->getSetting($contextId, 'appUrl'), '/');
		$token = (string) $this->getSetting($contextId, 'token');
		if ($appUrl === '' || strpos($token, '.') === false) return false;
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

		if ($raw === false || $status === 0) {
			error_log('[publisherApp] ' . $method . ' ' . $path . ' could not reach the app');
			return false;
		}
		return array('status' => $status, 'body' => json_decode($raw, true));
	}

	/** A read for the Payments tab: the body, or null when it failed. */
	function get($contextId, $path) {
		$response = $this->request($contextId, 'GET', $path);
		return $response !== false && $response['status'] === 200 ? $response['body'] : null;
	}
}
