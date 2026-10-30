<?php

/**
 * @file plugins/generic/terbitlyCore/TerbitlyCorePlugin.inc.php
 *
 * @class TerbitlyCorePlugin
 * @ingroup plugins_generic_terbitlyCore
 *
 * @brief Connects one OJS journal to Terbitly (prototype, OJS 3.3).
 *
 * - Sends every new submission to the app the moment the author submits it, then the editors'
 *   decisions (review, accept, decline, production) and publishing, so the app follows OJS.
 * - Carries a promoter's referral code: from a ?ref= link (kept in a cookie for 30 days, the last
 *   link wins, never for the journal's staff) or typed in the submission form, which shows only
 *   while the publisher's promoter program is on. The code goes only to the app; nothing in OJS
 *   shows it, so editors and reviewers never see who brought a manuscript.
 * - Adds a documents tab to the submission page once the first document the journal chose to show
 *   exists (invoices with a Pay button, receipts, the LOA, certificates), read live from the app,
 *   and, while the APC is not paid, refuses Publish where the journal requires payment first and
 *   warns the editor where it does not.
 * - Answers the app about a submission (TerbitlyCoreHandler) when someone clicks Sync or Get
 *   submission from OJS there.
 *
 * - Sends title and author changes made after submission, so the app's copy follows OJS.
 * - Sends the journal team's work: each review's state (submitted, declined, cancelled, rated,
 *   thanked), each decision with its editor, and who worked on copyediting and production once an
 *   article is published; answers the app with the journal's user groups and past work.
 * - Keeps what could not be sent (app down, server error, wrong token) and sends it again later,
 *   with the same event id so the app applies it once.
 *
 * Every request is signed with the journal's token: "<key id>.<secret>", made in the app.
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class TerbitlyCorePlugin extends GenericPlugin {

	const REFERRAL_COOKIE = 'terbitlyRef';
	const OUTBOX_LIMIT = 200;
	const OUTBOX_RETRY_SECONDS = 300;

	/** @var array Submissions whose title or authors changed in this request, sent once at the end. */
	var $metadataChanged = array();
	/** @var array Review assignments saved in this request; their whole state goes at the end. */
	var $reviewsChanged = array();
	/** @var array Submissions with a decision recorded in this request, by context. */
	var $decisionsRecorded = array();
	/** Past work read per page by the app (PRD-TEAM-02 §9). */
	const HISTORY_PAGE = 100;
	const REFERRAL_PATTERN = '/^[A-Za-z0-9-]{2,32}$/';
	/** How long the app's answer about the journal (the promoter program) is kept. */
	const JOURNAL_CONFIG_SECONDS = 60;

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
			// The journal team's work (PRD-TEAM-02 §12): every save of a review assignment, and each decision.
			HookRegistry::register('reviewassignmentdao::_updateobject', array($this, 'noteReviewChange'));
			HookRegistry::register('EditorAction::recordDecision', array($this, 'noteDecision'));
			HookRegistry::register('Publication::publish', array($this, 'sendPublished'));
			HookRegistry::register('Publication::unpublish', array($this, 'sendUnpublished'));
			HookRegistry::register('Publication::edit', array($this, 'noteMetadataChange'));
			HookRegistry::register('Author::add', array($this, 'noteAuthorChange'));
			HookRegistry::register('Author::edit', array($this, 'noteAuthorChange'));
			HookRegistry::register('Author::delete', array($this, 'noteAuthorChange'));
			HookRegistry::register('LoadHandler', array($this, 'flushOutbox'));
			HookRegistry::register('Template::Workflow', array($this, 'addDocumentsTab'));
			HookRegistry::register('Form::config::before', array($this, 'warnUnpaidApc'));
			HookRegistry::register('Publication::validatePublish', array($this, 'blockUnpaidApc'));
			HookRegistry::register('LoadHandler', array($this, 'setupHandler'));
		}
		return $success;
	}

	function getDisplayName() {
		return __('plugins.generic.terbitlyCore.displayName');
	}

	function getDescription() {
		return __('plugins.generic.terbitlyCore.description');
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
		$this->import('TerbitlyCoreSettingsForm');
		$form = new TerbitlyCoreSettingsForm($this, $context->getId());

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
		$request = Application::get()->getRequest();
		$code = (string) $request->getUserVar('ref');
		if (preg_match(self::REFERRAL_PATTERN, $code) && !$this->isStaff($request)) {
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

	/**
	 * Step 3 of the submission wizard: an optional field, filled from the ?ref= link when there was
	 * one. Not shown while the publisher's promoter program is off.
	 */
	function addReferralField($hookName, $args) {
		$smarty =& $args[1];
		$output =& $args[2];
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		if (!$context || !$this->promoterProgram($context->getId())) return false;
		$submissionId = (int) $request->getUserVar('submissionId');

		$smarty->assign('terbitlyCoreReferral', $this->referralFor($request, $submissionId));
		$output .= $smarty->fetch($this->getTemplateResource('referralField.tpl'));
		return false;
	}

	/** The referral code. */
	function readReferralField($hookName, $args) {
		$vars =& $args[1];
		$vars[] = 'terbitlyCoreReferral';
		return false;
	}

	/** Kept in the author's session until the submission is sent; never saved on the submission itself. */
	function keepReferral($hookName, $args) {
		$form = $args[0];
		$code = strtoupper(trim((string) $form->getData('terbitlyCoreReferral')));
		$session = Application::get()->getRequest()->getSession();
		$session->setSessionVar('terbitlyCoreReferral' . $form->submissionId, preg_match(self::REFERRAL_PATTERN, $code) ? $code : '');
		return false;
	}

	/**
	 * The code typed in the form wins over the ?ref= cookie, even when the author cleared it. The
	 * journal's staff never get the cookie's code filled in; they type one when they mean it.
	 */
	function referralFor($request, $submissionId) {
		$typed = $request->getSession()->getSessionVar('terbitlyCoreReferral' . $submissionId);
		if ($typed !== null) return $typed;
		if ($this->isStaff($request)) return '';
		$cookie = isset($_COOKIE[self::REFERRAL_COOKIE]) ? (string) $_COOKIE[self::REFERRAL_COOKIE] : '';
		return preg_match(self::REFERRAL_PATTERN, $cookie) ? $cookie : '';
	}

	/** Journal managers, section editors and assistants of this journal, and site admins. */
	function isStaff($request) {
		$user = $request->getUser();
		$context = $request->getContext();
		if (!$user) return false;
		if ($user->hasRole(array(ROLE_ID_SITE_ADMIN), CONTEXT_SITE)) return true;
		return $context && $user->hasRole(array(ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR, ROLE_ID_ASSISTANT), $context->getId());
	}

	/** Whether the publisher's promoter program is on; before any answer from the app, the field shows. */
	function promoterProgram($contextId) {
		return $this->journalConfig($contextId)['promoter_program'] ?? true;
	}

	/**
	 * What the app says about the journal (the promoter program), asked at
	 * most once a minute. When the app can't be reached the last answer stands.
	 */
	function journalConfig($contextId) {
		$config = (array) json_decode((string) $this->getSetting($contextId, 'journalConfig'), true);
		if (time() - (int) ($config['at'] ?? 0) >= self::JOURNAL_CONFIG_SECONDS) {
			$answer = $this->get($contextId, '/api/ojs/journal');
			if (is_array($answer)) {
				$config = array('promoter_program' => !empty($answer['promoter_program']));
			}
			$config['at'] = time();
			$this->updateSetting($contextId, 'journalConfig', json_encode($config), 'string');
		}
		return $config;
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
		$request->getSession()->unsetSessionVar('terbitlyCoreReferral' . $submission->getId());
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
			error_log('[terbitlyCore] no article link for submission ' . $submission->getId() . ': ' . $e->getMessage());
			return false;
		}
		$this->sendEvent($submission->getContextId(), 'submission.published', $submission->getId(), array(
			'article_url' => $url,
			'editor' => $this->editorName(),
			'participants' => $this->productionParticipants($submission->getId(), $submission->getContextId()),
		));
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
				error_log('[terbitlyCore] metadata of submission ' . $submissionId . ': ' . $e->getMessage());
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
			error_log('[terbitlyCore] ' . $type . ' for submission ' . $submissionId . ' failed: ' . $e->getMessage());
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
			error_log('[terbitlyCore] ' . $event['type'] . ' refused with status ' . $status . ': ' . json_encode($response['body']));
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
			error_log('[terbitlyCore] outbox: ' . $e->getMessage());
		}
		$this->updateSetting($contextId, 'outbox', json_encode(array_values($outbox)), 'string');
	}

	function uuid() {
		$bytes = random_bytes(16);
		$bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
		$bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}

	// ---------- Documents tab ----------

	/**
	 * Authors and editors see the same: the documents the journal chose in the app, as PDFs, with a
	 * Pay button on an invoice still owed. The tab is there once the first of them exists.
	 */
	function addDocumentsTab($hookName, $args) {
		$smarty =& $args[1];
		$output =& $args[2];
		$submission = $smarty->getTemplateVars('submission');
		$context = Application::get()->getRequest()->getContext();
		if (!$submission || !$context) return false;

		$response = $this->get($context->getId(), '/api/ojs/submissions/' . (int) $submission->getId());
		if (!$response || empty($response['found'])) return false;
		if (empty($response['invoices']) && empty($response['receipts']) && empty($response['loa']) && empty($response['certificates'])) return false;

		$smarty->assign('terbitlyCoreDocuments', $response);
		// Null when the journal does not show certificates; empty while the article waits for publication.
		$smarty->assign('terbitlyCoreShowsCertificates', is_array($response['certificates'] ?? null));
		$output .= $smarty->fetch($this->getTemplateResource('documentsTab.tpl'));
		return false;
	}

	/**
	 * A journal that does not publish before payment: while the APC is not paid, OJS refuses Publish
	 * and Schedule for publication, so the Publish dialog shows why and no button. When the app
	 * cannot be reached nothing is refused, so the journal is never stuck.
	 */
	function blockUnpaidApc($hookName, $args) {
		$errors =& $args[0];
		$submission = $args[2];
		try {
			$response = $this->get((int) $submission->getData('contextId'), '/api/ojs/submissions/' . (int) $submission->getId());
			if ($response && !empty($response['publish_blocked'])) {
				$errors['terbitlyCoreApc'] = __('plugins.generic.terbitlyCore.apcBlocked');
			}
		} catch (Throwable $e) {
			error_log('[terbitlyCore] APC block: ' . $e->getMessage());
		}
		return false;
	}

	/** A journal that publishes before payment: the Publish dialog warns the editor while the APC is not paid. */
	function warnUnpaidApc($hookName, $form) {
		if (!is_object($form) || $form->id !== 'publish' || empty($form->publication)) return false;
		try {
			$context = $form->submissionContext;
			$response = $this->get($context->getId(), '/api/ojs/submissions/' . (int) $form->publication->getData('submissionId'));
			// A blocked one already shows why among the requirements.
			if (!$response || empty($response['apc_unpaid']) || !empty($response['publish_blocked'])) return false;

			$form->addField(new \PKP\components\forms\FieldHTML('terbitlyCoreApc', array(
				'description' => '<div style="border-left:4px solid #d00a0a;background:#fff4f4;padding:10px 12px"><strong>'
					. __('plugins.generic.terbitlyCore.apcDue.title') . '</strong> ' . __('plugins.generic.terbitlyCore.apcDue.text') . '</div>',
				'groupId' => 'default',
			)), array(FIELD_POSITION_BEFORE, 'validation'));
		} catch (Throwable $e) {
			error_log('[terbitlyCore] APC warning: ' . $e->getMessage());
		}
		return false;
	}

	// ---------- Answering the app ----------

	/** {journal}/terbitly/... goes to TerbitlyCoreHandler. */
	function setupHandler($hookName, $args) {
		$page =& $args[0];
		if ($page !== 'terbitly') return false;
		define('HANDLER_CLASS', 'TerbitlyCoreHandler');
		$this->import('TerbitlyCoreHandler');
		return true;
	}

	/**
	 * Where a submission stands in OJS, for Sync and Get in the app: whether it is fully submitted,
	 * its status (queued, scheduled, published, declined), stage, review round, whether revisions
	 * were ever requested, title, authors, the editor of the latest decision, and its links.
	 */
	function submissionState($request, $context, $submission) {
		$status = array(STATUS_SCHEDULED => 'scheduled', STATUS_PUBLISHED => 'published', STATUS_DECLINED => 'declined');
		$stage = array(
			WORKFLOW_STAGE_ID_SUBMISSION => 'submission',
			WORKFLOW_STAGE_ID_INTERNAL_REVIEW => 'review',
			WORKFLOW_STAGE_ID_EXTERNAL_REVIEW => 'review',
			WORKFLOW_STAGE_ID_EDITING => 'copyediting',
			WORKFLOW_STAGE_ID_PRODUCTION => 'production',
		);
		$dispatcher = Application::get()->getDispatcher();
		$state = $status[(int) $submission->getData('status')] ?? 'queued';
		$publication = $submission->getCurrentPublication();
		$section = DAORegistry::getDAO('SectionDAO')->getById($publication->getData('sectionId'), $context->getId());

		import('classes.workflow.EditorDecisionActionsManager');
		$decisions = DAORegistry::getDAO('EditDecisionDAO')->getEditorDecisions($submission->getId());
		$last = $decisions ? end($decisions) : null;
		// Any "Request revisions" so far, so a sync still catches one whose event never arrived.
		$revisionsRequested = false;
		foreach ($decisions ?: array() as $decision) {
			if ((int) $decision['decision'] === SUBMISSION_EDITOR_DECISION_PENDING_REVISIONS) $revisionsRequested = true;
		}
		$editor = $last ? DAORegistry::getDAO('UserDAO')->getById((int) $last['editorId']) : null;

		return $this->metadataPayload($submission) + array(
			'found' => true,
			'submitted' => (int) $submission->getData('submissionProgress') === 0,
			'status' => $state,
			'stage' => $stage[(int) $submission->getData('stageId')] ?? null,
			'round' => $this->reviewRound($submission->getId()),
			'revisions_requested' => $revisionsRequested,
			'section' => $section ? $section->getLocalizedTitle() : null,
			'editor' => $editor ? $editor->getFullName() : null,
			'ojs_url' => $dispatcher->url($request, ROUTE_PAGE, $context->getPath(), 'workflow', 'access', $submission->getId()),
			'article_url' => $state === 'published' ? $dispatcher->url($request, ROUTE_PAGE, $context->getPath(), 'article', 'view', $submission->getBestId()) : null,
		);
	}

	// ---------- Journal team (PRD-TEAM-02 §12) ----------

	/**
	 * A review assignment was saved: accepted, declined, submitted, cancelled, rated or thanked. The
	 * hook runs before the UPDATE with its parameters, the review id last; the whole state is read
	 * and sent at the end of the request, when OJS has saved everything.
	 */
	function noteReviewChange($hookName, $args) {
		$params = $args[1];
		$id = is_array($params) ? (int) end($params) : 0;
		if (!$id || isset($this->reviewsChanged[$id])) return false;
		$this->reviewsChanged[$id] = true;
		if (count($this->reviewsChanged) === 1) register_shutdown_function(array($this, 'sendReviews'));
		return false;
	}

	function sendReviews() {
		$dao = DAORegistry::getDAO('ReviewAssignmentDAO');
		foreach (array_keys($this->reviewsChanged) as $id) {
			try {
				$review = $dao->getById($id);
				$submission = $review ? Services::get('submission')->get($review->getSubmissionId()) : null;
				if (!$submission) continue;
				$this->sendEvent($submission->getContextId(), 'review.updated', $submission->getId(), $this->reviewPayload($review, $submission->getContextId()));
			} catch (Throwable $e) {
				error_log('[terbitlyCore] review ' . $id . ': ' . $e->getMessage());
			}
		}
		$this->reviewsChanged = array();
	}

	/** A decision, sent with its id and editor once OJS has saved it. Recommendations are skipped. */
	function noteDecision($hookName, $args) {
		$submission = $args[0];
		if ($args[3]) return false;
		$this->decisionsRecorded[$submission->getId()] = $submission->getContextId();
		if (count($this->decisionsRecorded) === 1) register_shutdown_function(array($this, 'sendDecisions'));
		return false;
	}

	function sendDecisions() {
		foreach ($this->decisionsRecorded as $submissionId => $contextId) {
			try {
				$decisions = DAORegistry::getDAO('EditDecisionDAO')->getEditorDecisions($submissionId);
				$last = $decisions ? end($decisions) : null;
				if ($last) $this->sendEvent($contextId, 'decision.recorded', $submissionId, $this->decisionPayload($last, $contextId));
			} catch (Throwable $e) {
				error_log('[terbitlyCore] decision of submission ' . $submissionId . ': ' . $e->getMessage());
			}
		}
		$this->decisionsRecorded = array();
	}

	/** One review assignment as the app reads it: the whole state, so order and repeats do not matter. */
	function reviewPayload($review, $contextId) {
		$recommendations = array(1 => 'ACCEPT', 2 => 'REVISIONS', 3 => 'RESUBMIT_HERE', 4 => 'RESUBMIT_ELSEWHERE', 5 => 'DECLINE', 6 => 'SEE_COMMENTS');
		$reviewer = DAORegistry::getDAO('UserDAO')->getById((int) $review->getReviewerId());

		return array(
			'review_id' => (int) $review->getId(),
			'round' => (int) $review->getRound(),
			'declined' => (bool) $review->getDeclined(),
			'cancelled' => (bool) $review->getCancelled(),
			'thanked' => (bool) $review->getDateAcknowledged(),
			'date_assigned' => $this->day($review->getDateAssigned()),
			'date_due' => $this->day($review->getDateDue()),
			'date_completed' => $this->day($review->getDateCompleted()),
			'date_declined' => $review->getDeclined() ? $this->day($review->getDateConfirmed()) : null,
			'recommendation' => $recommendations[(int) $review->getRecommendation()] ?? null,
			'quality' => $review->getQuality() ? (int) $review->getQuality() : null,
			'reviewer' => $reviewer ? $this->userPayload($reviewer, $contextId) : null,
		);
	}

	/** One decision row of EditDecisionDAO as the app reads it. */
	function decisionPayload($decision, $contextId) {
		import('classes.workflow.EditorDecisionActionsManager');
		$names = array(
			SUBMISSION_EDITOR_DECISION_ACCEPT => 'ACCEPT',
			SUBMISSION_EDITOR_DECISION_DECLINE => 'DECLINE',
			SUBMISSION_EDITOR_DECISION_INITIAL_DECLINE => 'DECLINE',
			SUBMISSION_EDITOR_DECISION_PENDING_REVISIONS => 'REVISIONS',
			SUBMISSION_EDITOR_DECISION_RESUBMIT => 'RESUBMIT',
			SUBMISSION_EDITOR_DECISION_EXTERNAL_REVIEW => 'SEND_TO_REVIEW',
			SUBMISSION_EDITOR_DECISION_SEND_TO_PRODUCTION => 'SEND_TO_PRODUCTION',
		);
		$editor = DAORegistry::getDAO('UserDAO')->getById((int) $decision['editorId']);

		return array(
			'decision_id' => (int) $decision['editDecisionId'],
			'decision' => $names[(int) $decision['decision']] ?? 'OTHER',
			'round' => $decision['round'] ? (int) $decision['round'] : null,
			'date' => $this->day($decision['dateDecided']),
			'editor' => $editor ? $this->userPayload($editor, $contextId) : null,
		);
	}

	/** Who was assigned in copyediting and production, each with the groups they were assigned in. */
	function productionParticipants($submissionId, $contextId) {
		$assignmentDao = DAORegistry::getDAO('StageAssignmentDAO');
		$userDao = DAORegistry::getDAO('UserDAO');
		$people = array();
		foreach (array(WORKFLOW_STAGE_ID_EDITING, WORKFLOW_STAGE_ID_PRODUCTION) as $stageId) {
			$assignments = $assignmentDao->getBySubmissionAndStageId($submissionId, $stageId);
			while ($assignment = $assignments->next()) {
				$userId = (int) $assignment->getUserId();
				if (!isset($people[$userId])) {
					$user = $userDao->getById($userId);
					if (!$user) continue;
					$people[$userId] = $this->userPayload($user, $contextId, false) + array('groups' => array());
				}
				$people[$userId]['groups'][] = (int) $assignment->getUserGroupId();
			}
		}
		return array_values(array_map(function ($person) {
			$person['groups'] = array_values(array_unique($person['groups']));
			return $person;
		}, $people));
	}

	/** An OJS user as the app recognises them (§5.1): email first, the OJS id when the email changes. */
	function userPayload($user, $contextId, $withGroups = true) {
		$orcid = (string) $user->getOrcid();
		$payload = array(
			'ojs_user_id' => (int) $user->getId(),
			'email' => $user->getEmail(),
			'name' => $user->getFullName(),
			'affiliation' => $user->getLocalizedAffiliation() ?: null,
			'country' => $user->getCountry() ? $user->getCountryLocalized() : null,
			'orcid' => $orcid !== '' ? preg_replace('~^https?://orcid\.org/~', '', $orcid) : null,
		);
		if ($withGroups) {
			$groups = array();
			$iterator = DAORegistry::getDAO('UserGroupDAO')->getByUserId($user->getId(), $contextId);
			while ($group = $iterator->next()) $groups[] = (int) $group->getId();
			$payload['groups'] = $groups;
		}
		return $payload;
	}

	/** The journal's user groups with their role and members, for mapping them to positions (§6). */
	function userGroups($contextId) {
		import('lib.pkp.classes.security.Role');
		$roles = array(ROLE_ID_MANAGER => 'MANAGER', ROLE_ID_SUB_EDITOR => 'SUB_EDITOR', ROLE_ID_ASSISTANT => 'ASSISTANT', ROLE_ID_REVIEWER => 'REVIEWER', ROLE_ID_AUTHOR => 'AUTHOR', ROLE_ID_READER => 'READER');
		$dao = DAORegistry::getDAO('UserGroupDAO');
		$groups = array();
		$iterator = $dao->getByContextId($contextId);
		while ($group = $iterator->next()) {
			$groups[] = array(
				'id' => (int) $group->getId(),
				'name' => (string) $group->getLocalizedName(),
				'abbrev' => $group->getLocalizedAbbrev() ?: null,
				'role' => $roles[(int) $group->getRoleId()] ?? 'OTHER',
				'members' => (int) $dao->getContextUsersCount($contextId, $group->getId()),
			);
		}
		return $groups;
	}

	/**
	 * One page of past work since a day (§9): reviews invited, decisions taken and articles published
	 * since then, and on the first page the members of the editorial groups.
	 */
	function history($contextId, $since, $page) {
		import('lib.pkp.classes.security.Role');
		$dao = DAORegistry::getDAO('ReviewAssignmentDAO');
		$offset = ($page - 1) * self::HISTORY_PAGE;
		$count = function ($sql) use ($dao, $contextId, $since) {
			$row = $dao->retrieve($sql, array((int) $contextId, $since))->current();
			return (int) ($row ? $row->total : 0);
		};
		$ids = function ($sql, $column) use ($dao, $contextId, $since, $offset) {
			$result = $dao->retrieve($sql . ' LIMIT ' . self::HISTORY_PAGE . ' OFFSET ' . (int) $offset, array((int) $contextId, $since));
			$ids = array();
			foreach ($result as $row) $ids[] = (int) $row->{$column};
			return $ids;
		};

		$reviewFrom = 'FROM review_assignments ra JOIN submissions s ON s.submission_id = ra.submission_id WHERE s.context_id = ? AND ra.date_assigned >= ?';
		$decisionFrom = 'FROM edit_decisions d JOIN submissions s ON s.submission_id = d.submission_id WHERE s.context_id = ? AND d.date_decided >= ?';
		$publishedFrom = 'FROM publications p JOIN submissions s ON s.submission_id = p.submission_id WHERE s.context_id = ? AND p.status = ' . STATUS_PUBLISHED . ' AND p.date_published >= ?';
		$pages = (int) ceil(max($count('SELECT COUNT(*) AS total ' . $reviewFrom), $count('SELECT COUNT(*) AS total ' . $decisionFrom), $count('SELECT COUNT(DISTINCT p.submission_id) AS total ' . $publishedFrom)) / self::HISTORY_PAGE);

		$reviews = array();
		foreach ($ids('SELECT ra.review_id ' . $reviewFrom . ' ORDER BY ra.review_id', 'review_id') as $id) {
			$review = $dao->getById($id);
			if ($review) $reviews[] = array('ojs_submission_id' => (int) $review->getSubmissionId()) + $this->reviewPayload($review, $contextId);
		}

		$decisions = array();
		foreach ($ids('SELECT d.edit_decision_id ' . $decisionFrom . ' ORDER BY d.edit_decision_id', 'edit_decision_id') as $id) {
			$row = $dao->retrieve('SELECT * FROM edit_decisions WHERE edit_decision_id = ?', array($id))->current();
			if (!$row) continue;
			$decisions[] = array('ojs_submission_id' => (int) $row->submission_id) + $this->decisionPayload(array(
				'editDecisionId' => $row->edit_decision_id, 'editorId' => $row->editor_id, 'decision' => $row->decision, 'round' => $row->round, 'dateDecided' => $row->date_decided,
			), $contextId);
		}

		$published = array();
		foreach ($ids('SELECT p.submission_id, MIN(p.date_published) AS published_at ' . $publishedFrom . ' GROUP BY p.submission_id ORDER BY p.submission_id', 'submission_id') as $id) {
			$row = $dao->retrieve('SELECT MIN(date_published) AS published_at FROM publications WHERE submission_id = ? AND status = ' . STATUS_PUBLISHED, array($id))->current();
			$published[] = array('ojs_submission_id' => $id, 'published_at' => $row ? $this->day($row->published_at) : null, 'participants' => $this->productionParticipants($id, $contextId));
		}

		$editorial = array();
		if ($page === 1) {
			$userDao = DAORegistry::getDAO('UserDAO');
			$members = $dao->retrieve('SELECT DISTINCT uug.user_id FROM user_user_groups uug JOIN user_groups ug ON ug.user_group_id = uug.user_group_id WHERE ug.context_id = ? AND ug.role_id IN (?, ?)', array((int) $contextId, ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR));
			foreach ($members as $row) {
				$user = $userDao->getById((int) $row->user_id);
				if ($user) $editorial[] = $this->userPayload($user, $contextId);
			}
		}

		return array('page' => $page, 'pages' => max($pages, 1), 'reviews' => $reviews, 'decisions' => $decisions, 'published' => $published, 'editorial' => $editorial);
	}

	/** A date or datetime from OJS as YYYY-MM-DD, or null. */
	function day($value) {
		return $value ? substr((string) $value, 0, 10) : null;
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
			error_log('[terbitlyCore] ' . $method . ' ' . $path . ' could not reach the app');
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
