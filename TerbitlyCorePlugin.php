<?php

/**
 * @file plugins/generic/terbitlyCore/TerbitlyCorePlugin.php
 *
 * @class TerbitlyCorePlugin
 *
 * @brief Connects one OJS journal to Terbitly (OJS 3.4).
 *
 * - Sends every new submission to the app the moment the author submits it, then the editors'
 *   decisions (review, accept, decline, production) and publishing, so the app follows OJS.
 * - Carries a promoter's referral code: from a ?ref= link (kept in a cookie for 30 days, the last
 *   link wins, never for the journal's staff) or typed in the For the Editors step of the
 *   submission wizard, which shows it only while the publisher's promoter program is on. The code
 *   goes only to the app and is removed from the submission once it is sent, so editors and
 *   reviewers never see who brought a manuscript.
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

namespace APP\plugins\generic\terbitlyCore;

use APP\core\Application;
use APP\core\Services;
use APP\facades\Repo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PKP\components\forms\FieldHTML;
use PKP\components\forms\FieldText;
use PKP\config\Config;
use PKP\core\JSONMessage;
use PKP\db\DAORegistry;
use PKP\decision\Decision;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\observers\events\SubmissionSubmitted;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;
use PKP\submission\PKPSubmission;
use Throwable;

class TerbitlyCorePlugin extends GenericPlugin
{
    public const REFERRAL_COOKIE = 'terbitlyRef';
    /** The submission property that holds a typed code until the submission is sent. */
    public const REFERRAL_PROP = 'terbitlyCoreReferral';
    public const OUTBOX_LIMIT = 200;
    public const OUTBOX_RETRY_SECONDS = 300;
    /** Past work read per page by the app (PRD-TEAM-02 §9). */
    public const HISTORY_PAGE = 100;
    public const REFERRAL_PATTERN = '/^[A-Za-z0-9-]{2,32}$/';
    /** How long the app's answer about the journal (the promoter program) is kept. */
    public const JOURNAL_CONFIG_SECONDS = 60;

    /** @var array Submissions whose title or authors changed in this request, sent once at the end. */
    public $metadataChanged = [];
    /** @var array Review assignments saved in this request; their whole state goes at the end. */
    public $reviewsChanged = [];

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) {
            return true;
        }
        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('LoadHandler', [$this, 'rememberReferral']);
            Hook::add('Schema::get::submission', [$this, 'addReferralProp']);
            Hook::add('Form::config::before', [$this, 'addReferralField']);
            Event::listen(SubmissionSubmitted::class, fn (SubmissionSubmitted $event) => $this->sendSubmission($event));
            Hook::add('Decision::add', [$this, 'sendDecision']);
            // The journal team's work (PRD-TEAM-02 §12): every save of a review assignment. The DAO hook
            // is named after the namespaced class, lowercased.
            Hook::add('pkp\submission\reviewassignment\reviewassignmentdao::_updateobject', [$this, 'noteReviewChange']);
            Hook::add('Publication::publish', [$this, 'sendPublished']);
            Hook::add('Publication::unpublish', [$this, 'sendUnpublished']);
            Hook::add('Publication::edit', [$this, 'noteMetadataChange']);
            Hook::add('Author::add', [$this, 'noteAuthorChange']);
            Hook::add('Author::edit', [$this, 'noteAuthorChange']);
            Hook::add('Author::delete', [$this, 'noteAuthorChange']);
            Hook::add('LoadHandler', [$this, 'flushOutbox']);
            Hook::add('Template::Workflow', [$this, 'addDocumentsTab']);
            Hook::add('Form::config::before', [$this, 'warnUnpaidApc']);
            Hook::add('Publication::validatePublish', [$this, 'blockUnpaidApc']);
            Hook::add('LoadHandler', [$this, 'setupHandler']);
        }
        return $success;
    }

    public function getDisplayName()
    {
        return __('plugins.generic.terbitlyCore.displayName');
    }

    public function getDescription()
    {
        return __('plugins.generic.terbitlyCore.description');
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $verb)
    {
        $router = $request->getRouter();
        return array_merge(
            $this->getEnabled() ? [
                new LinkAction(
                    'settings',
                    new AjaxModal(
                        $router->url($request, null, null, 'manage', null, ['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic']),
                        $this->getDisplayName()
                    ),
                    __('manager.plugins.settings'),
                    null
                ),
            ] : [],
            parent::getActions($request, $verb)
        );
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
    {
        if ($request->getUserVar('verb') !== 'settings') {
            return parent::manage($args, $request);
        }
        $form = new TerbitlyCoreSettingsForm($this, $request->getContext()->getId());

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
     * signing up and signing in before the author reaches the submission wizard.
     */
    public function rememberReferral($hookName, $args)
    {
        $request = Application::get()->getRequest();
        $code = (string) $request->getUserVar('ref');
        if (preg_match(self::REFERRAL_PATTERN, $code) && !$this->isStaff($request)) {
            setcookie(self::REFERRAL_COOKIE, strtoupper($code), [
                'expires' => time() + 30 * 86400,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            $_COOKIE[self::REFERRAL_COOKIE] = strtoupper($code);
        }
        return Hook::CONTINUE;
    }

    /**
     * The wizard saves its forms through the submission API, so the typed code needs a submission
     * property; it is removed again once the submission is sent.
     */
    public function addReferralProp($hookName, $args)
    {
        $schema = &$args[0];
        $schema->properties->{self::REFERRAL_PROP} = (object) [
            'type' => 'string',
            'validation' => ['nullable', 'regex:' . self::REFERRAL_PATTERN],
        ];
        return Hook::CONTINUE;
    }

    /**
     * The For the Editors step: an optional field, filled from the ?ref= link when there was one.
     * Not shown while the publisher's promoter program is off.
     */
    public function addReferralField($hookName, $form)
    {
        if (!is_object($form) || ($form->id ?? null) !== 'commentsForTheEditors' || empty($form->submission)) {
            return Hook::CONTINUE;
        }
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context || !$this->promoterProgram($context->getId())) {
            return Hook::CONTINUE;
        }

        $form->addField(new FieldText(self::REFERRAL_PROP, [
            'label' => __('plugins.generic.terbitlyCore.referral.label'),
            'description' => __('plugins.generic.terbitlyCore.referral.description'),
            'size' => 'small',
            'value' => $this->referralFor($request, $form->submission),
            'groupId' => 'default',
        ]));
        return Hook::CONTINUE;
    }

    /**
     * The code typed in the wizard wins over the ?ref= cookie, even when the author cleared it. The
     * journal's staff never get the cookie's code filled in; they type one when they mean it.
     */
    public function referralFor($request, $submission)
    {
        $typed = $submission->getData(self::REFERRAL_PROP);
        if ($typed !== null) {
            return strtoupper((string) $typed);
        }
        if ($this->isStaff($request)) {
            return '';
        }
        $cookie = isset($_COOKIE[self::REFERRAL_COOKIE]) ? (string) $_COOKIE[self::REFERRAL_COOKIE] : '';
        return preg_match(self::REFERRAL_PATTERN, $cookie) ? $cookie : '';
    }

    /** Journal managers, section editors and assistants of this journal, and site admins. */
    public function isStaff($request)
    {
        $user = $request->getUser();
        $context = $request->getContext();
        if (!$user) {
            return false;
        }
        if ($user->hasRole([Role::ROLE_ID_SITE_ADMIN], Application::CONTEXT_SITE)) {
            return true;
        }
        return $context && $user->hasRole([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT], $context->getId());
    }

    /** Whether the publisher's promoter program is on; before any answer from the app, the field shows. */
    public function promoterProgram($contextId)
    {
        return $this->journalConfig($contextId)['promoter_program'] ?? true;
    }

    /**
     * What the app says about the journal (the promoter program), asked at
     * most once a minute. When the app can't be reached the last answer stands.
     */
    public function journalConfig($contextId)
    {
        $config = (array) json_decode((string) $this->getSetting($contextId, 'journalConfig'), true);
        if (time() - (int) ($config['at'] ?? 0) >= self::JOURNAL_CONFIG_SECONDS) {
            $answer = $this->get($contextId, '/api/ojs/journal');
            if (is_array($answer)) {
                $config = ['promoter_program' => !empty($answer['promoter_program'])];
            }
            $config['at'] = time();
            $this->updateSetting($contextId, 'journalConfig', json_encode($config), 'string');
        }
        return $config;
    }

    // ---------- New submission ----------

    /** Submitted in the wizard: the submission goes to the app right away, and the typed code is dropped. */
    public function sendSubmission(SubmissionSubmitted $event)
    {
        $request = Application::get()->getRequest();
        $submission = $event->submission;
        $this->sendEvent($event->context->getId(), 'submission.submitted', $submission->getId(), $this->submissionPayload($request, $event->context, $submission));
        DB::table('submission_settings')->where('submission_id', $submission->getId())->where('setting_name', self::REFERRAL_PROP)->delete();
    }

    // ---------- Decisions and publishing ----------

    /**
     * An editor's decision, just saved. Recommendations are not decisions and are skipped. Accept
     * (and Skip review, which accepts) moves the submission to Copyediting in the app by itself.
     */
    public function sendDecision($hookName, $args)
    {
        $decision = $args[0];
        $type = (int) $decision->getData('decision');
        if (Repo::decision()->isRecommendation($type)) {
            return Hook::CONTINUE;
        }

        $id = (int) $decision->getData('submissionId');
        $submission = Repo::submission()->get($id);
        if (!$submission) {
            return Hook::CONTINUE;
        }
        $contextId = $submission->getData('contextId');
        $editor = ['editor' => $this->editorName()];

        switch ($type) {
            case Decision::ACCEPT:
            case Decision::SKIP_EXTERNAL_REVIEW:
                $this->sendEvent($contextId, 'decision.accepted', $id, $editor);
                break;
            case Decision::DECLINE:
            case Decision::INITIAL_DECLINE:
                $this->sendEvent($contextId, 'decision.declined', $id, $editor);
                break;
            case Decision::REVERT_DECLINE:
            case Decision::REVERT_INITIAL_DECLINE:
                $this->sendEvent($contextId, 'decision.reverted', $id, $editor);
                break;
            case Decision::EXTERNAL_REVIEW:
                $this->sendEvent($contextId, 'submission.stage_changed', $id, ['stage' => 'review']);
                break;
            case Decision::SEND_TO_PRODUCTION:
                $this->sendEvent($contextId, 'submission.stage_changed', $id, ['stage' => 'production']);
                break;
            case Decision::PENDING_REVISIONS:
                $this->sendEvent($contextId, 'review.round_updated', $id, ['round' => $this->reviewRound($id), 'status' => 'revisions_requested']);
                break;
            case Decision::RESUBMIT:
                $this->sendEvent($contextId, 'review.round_updated', $id, ['round' => $this->reviewRound($id), 'status' => 'resubmit_requested']);
                break;
            case Decision::NEW_EXTERNAL_ROUND:
                $this->sendEvent($contextId, 'review.round_updated', $id, ['round' => (int) $this->reviewRound($id) + 1, 'status' => 'new_round']);
                break;
        }

        $this->sendEvent($contextId, 'decision.recorded', $id, $this->decisionPayload([
            'editDecisionId' => $decision->getId(),
            'editorId' => $decision->getData('editorId'),
            'decision' => $type,
            'round' => $decision->getData('round'),
            'dateDecided' => $decision->getData('dateDecided'),
        ], $contextId));
        return Hook::CONTINUE;
    }

    /** Published in OJS: the app publishes it once the APC allows, with the article's link. */
    public function sendPublished($hookName, $args)
    {
        $submission = $args[2];
        try {
            $request = Application::get()->getRequest();
            $context = Services::get('context')->get($submission->getData('contextId'));
            // The application's dispatcher: a scheduled publish runs without a web request's one.
            $url = Application::get()->getDispatcher()->url($request, Application::ROUTE_PAGE, $context->getPath(), 'article', 'view', $submission->getBestId());
        } catch (Throwable $e) {
            error_log('[terbitlyCore] no article link for submission ' . $submission->getId() . ': ' . $e->getMessage());
            return Hook::CONTINUE;
        }
        $this->sendEvent($submission->getData('contextId'), 'submission.published', $submission->getId(), [
            'article_url' => $url,
            'editor' => $this->editorName(),
            'participants' => $this->productionParticipants($submission->getId(), $submission->getData('contextId')),
        ]);
        return Hook::CONTINUE;
    }

    /** Unpublished in OJS: the app only flags it for the admin. */
    public function sendUnpublished($hookName, $args)
    {
        $submission = $args[2];
        $this->sendEvent($submission->getData('contextId'), 'submission.unpublished', $submission->getId(), ['editor' => $this->editorName()]);
        return Hook::CONTINUE;
    }

    // ---------- Metadata ----------

    /** The publication was edited (title among others). */
    public function noteMetadataChange($hookName, $args)
    {
        $this->noteSubmission((int) $args[1]->getData('submissionId'));
        return Hook::CONTINUE;
    }

    /** An author was added, changed or removed. */
    public function noteAuthorChange($hookName, $args)
    {
        $publication = Repo::publication()->get((int) $args[0]->getData('publicationId'));
        if ($publication) {
            $this->noteSubmission((int) $publication->getData('submissionId'));
        }
        return Hook::CONTINUE;
    }

    /**
     * Remembers a submitted manuscript whose metadata changed. The hooks run before OJS saves, and
     * an editor often changes several authors at once, so one message goes at the end of the request
     * with what is saved by then. Submissions still in the wizard are left alone.
     */
    public function noteSubmission($submissionId)
    {
        if (!$submissionId || isset($this->metadataChanged[$submissionId])) {
            return;
        }
        $this->metadataChanged[$submissionId] = true;
        if (count($this->metadataChanged) === 1) {
            register_shutdown_function([$this, 'sendMetadata']);
        }
    }

    public function sendMetadata()
    {
        foreach (array_keys($this->metadataChanged) as $submissionId) {
            try {
                $submission = Repo::submission()->get($submissionId);
                // submissionProgress is empty once submitted, a step name while in the wizard.
                if (!$submission || $submission->getData('submissionProgress')) {
                    continue;
                }
                $this->sendEvent($submission->getData('contextId'), 'submission.metadata_updated', $submissionId, $this->metadataPayload($submission));
            } catch (Throwable $e) {
                error_log('[terbitlyCore] metadata of submission ' . $submissionId . ': ' . $e->getMessage());
            }
        }
        $this->metadataChanged = [];
    }

    /** The editor acting now, named in the app because they may have no account there. */
    public function editorName()
    {
        $user = Application::get()->getRequest()->getUser();
        return $user ? $user->getFullName() : null;
    }

    /** The latest external review round, null before the first one. */
    public function reviewRound($submissionId)
    {
        $round = DAORegistry::getDAO('ReviewRoundDAO')->getLastReviewRoundBySubmissionId($submissionId, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW);
        return $round ? (int) $round->getRound() : null;
    }

    // ---------- Payload ----------

    /** The new manuscript: title, section, authors in order, the referral code and the link back to OJS. */
    public function submissionPayload($request, $context, $submission)
    {
        $section = Repo::section()->get((int) $submission->getCurrentPublication()->getData('sectionId'), $context->getId());

        return $this->metadataPayload($submission) + [
            'section' => $section ? $section->getLocalizedTitle() : null,
            'referral_code' => $this->referralFor($request, $submission) ?: null,
            'ojs_url' => Application::get()->getDispatcher()->url($request, Application::ROUTE_PAGE, $context->getPath(), 'workflow', 'access', $submission->getId()),
        ];
    }

    /** Title and authors in byline order, with the corresponding author marked. */
    public function metadataPayload($submission)
    {
        $publication = $submission->getCurrentPublication();
        $authors = iterator_to_array($publication->getData('authors') ?? [], false);
        usort($authors, fn ($a, $b) => (int) $a->getData('seq') - (int) $b->getData('seq'));

        return [
            'title' => $publication->getLocalizedTitle(),
            'authors' => array_map(fn ($author) => [
                'name' => $author->getFullName(),
                'email' => $author->getEmail(),
                'affiliation' => $author->getLocalizedAffiliation(),
                'country' => $author->getCountry(),
                'corresponding' => (int) $author->getId() === (int) $publication->getData('primaryContactId'),
            ], $authors),
        ];
    }

    /**
     * One event in the app's shape (POST /api/ojs/events): a fresh id, so the app applies it once
     * even when it is sent again, the type, the OJS submission and when it happened.
     */
    public function sendEvent($contextId, $type, $submissionId, $data)
    {
        $event = [
            'event_id' => $this->uuid(),
            'type' => $type,
            'ojs_submission_id' => (int) $submissionId,
            'occurred_at' => date('c'),
            'data' => $data,
        ];
        // Nothing here may stop the editor's action in OJS: a failure is kept or logged.
        try {
            if (!$this->deliver($contextId, $event)) {
                $this->enqueue($contextId, $event);
            }
        } catch (Throwable $e) {
            error_log('[terbitlyCore] ' . $type . ' for submission ' . $submissionId . ' failed: ' . $e->getMessage());
        }
    }

    /**
     * Sends one event. True when the app has it, or refused it for good (a 4xx other than 401: the
     * event itself is wrong, resending will not help); false when it should be sent again.
     */
    public function deliver($contextId, $event)
    {
        $response = $this->request($contextId, 'POST', '/api/ojs/events', $event);
        if ($response === false) {
            return false;
        }
        $status = $response['status'];
        if ($status >= 400 && $status < 500 && $status !== 401) {
            error_log('[terbitlyCore] ' . $event['type'] . ' refused with status ' . $status . ': ' . json_encode($response['body']));
        }
        return $status < 500 && $status !== 401;
    }

    // ---------- Outbox ----------

    public function enqueue($contextId, $event)
    {
        $outbox = (array) json_decode((string) $this->getSetting($contextId, 'outbox'), true);
        $outbox[] = $event;
        $this->updateSetting($contextId, 'outbox', json_encode(array_slice($outbox, -self::OUTBOX_LIMIT)), 'string');
    }

    /** On a page load at most every five minutes: sends what is waiting, oldest first, and stops at the first failure. */
    public function flushOutbox($hookName, $args)
    {
        $context = Application::get()->getRequest()->getContext();
        if ($context) {
            $this->flushOutboxFor($context->getId());
        }
        return Hook::CONTINUE;
    }

    public function flushOutboxFor($contextId)
    {
        $outbox = (array) json_decode((string) $this->getSetting($contextId, 'outbox'), true);
        if (!$outbox || time() - (int) $this->getSetting($contextId, 'outboxTriedAt') < self::OUTBOX_RETRY_SECONDS) {
            return;
        }

        $this->updateSetting($contextId, 'outboxTriedAt', time(), 'int');
        try {
            while ($outbox && $this->deliver($contextId, $outbox[0])) {
                array_shift($outbox);
            }
        } catch (Throwable $e) {
            error_log('[terbitlyCore] outbox: ' . $e->getMessage());
        }
        $this->updateSetting($contextId, 'outbox', json_encode(array_values($outbox)), 'string');
    }

    public function uuid()
    {
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
    public function addDocumentsTab($hookName, $args)
    {
        $smarty = &$args[1];
        $output = &$args[2];
        $submission = $smarty->getTemplateVars('submission');
        $context = Application::get()->getRequest()->getContext();
        if (!$submission || !$context) {
            return Hook::CONTINUE;
        }

        $response = $this->get($context->getId(), '/api/ojs/submissions/' . (int) $submission->getId());
        if (!$response || empty($response['found'])) {
            return Hook::CONTINUE;
        }
        if (empty($response['invoices']) && empty($response['receipts']) && empty($response['loa']) && empty($response['certificates'])) {
            return Hook::CONTINUE;
        }

        $smarty->assign('terbitlyCoreDocuments', $response);
        // Null when the journal does not show certificates; empty while the article waits for publication.
        $smarty->assign('terbitlyCoreShowsCertificates', is_array($response['certificates'] ?? null));
        $output .= $smarty->fetch($this->getTemplateResource('documentsTab.tpl'));
        return Hook::CONTINUE;
    }

    /**
     * A journal that does not publish before payment: while the APC is not paid, OJS refuses Publish
     * and Schedule for publication, so the Publish dialog shows why and no button. When the app
     * cannot be reached nothing is refused, so the journal is never stuck.
     */
    public function blockUnpaidApc($hookName, $args)
    {
        $errors = &$args[0];
        $submission = $args[2];
        try {
            $response = $this->get((int) $submission->getData('contextId'), '/api/ojs/submissions/' . (int) $submission->getId());
            if ($response && !empty($response['publish_blocked'])) {
                $errors['terbitlyCoreApc'] = __('plugins.generic.terbitlyCore.apcBlocked');
            }
        } catch (Throwable $e) {
            error_log('[terbitlyCore] APC block: ' . $e->getMessage());
        }
        return Hook::CONTINUE;
    }

    /** A journal that publishes before payment: the Publish dialog warns the editor while the APC is not paid. */
    public function warnUnpaidApc($hookName, $form)
    {
        if (!is_object($form) || $form->id !== 'publish' || empty($form->publication)) {
            return Hook::CONTINUE;
        }
        try {
            $context = $form->submissionContext;
            $response = $this->get($context->getId(), '/api/ojs/submissions/' . (int) $form->publication->getData('submissionId'));
            // A blocked one already shows why among the requirements.
            if (!$response || empty($response['apc_unpaid']) || !empty($response['publish_blocked'])) {
                return Hook::CONTINUE;
            }

            $form->addField(new FieldHTML('terbitlyCoreApc', [
                'description' => '<div style="border-left:4px solid #d00a0a;background:#fff4f4;padding:10px 12px"><strong>'
                    . __('plugins.generic.terbitlyCore.apcDue.title') . '</strong> ' . __('plugins.generic.terbitlyCore.apcDue.text') . '</div>',
                'groupId' => 'default',
            ]), [FIELD_POSITION_BEFORE, 'validation']);
        } catch (Throwable $e) {
            error_log('[terbitlyCore] APC warning: ' . $e->getMessage());
        }
        return Hook::CONTINUE;
    }

    // ---------- Answering the app ----------

    /** {journal}/terbitly/... goes to TerbitlyCoreHandler. */
    public function setupHandler($hookName, $args)
    {
        if ($args[0] !== 'terbitly') {
            return Hook::CONTINUE;
        }
        $handler = &$args[3];
        $handler = new TerbitlyCoreHandler($this);
        return Hook::ABORT;
    }

    /**
     * Where a submission stands in OJS, for Sync and Get in the app: whether it is fully submitted,
     * its status (queued, scheduled, published, declined), stage, review round, whether revisions
     * were ever requested, title, authors, the editor of the latest decision, and its links.
     */
    public function submissionState($request, $context, $submission)
    {
        $status = [PKPSubmission::STATUS_SCHEDULED => 'scheduled', PKPSubmission::STATUS_PUBLISHED => 'published', PKPSubmission::STATUS_DECLINED => 'declined'];
        $stage = [
            WORKFLOW_STAGE_ID_SUBMISSION => 'submission',
            WORKFLOW_STAGE_ID_INTERNAL_REVIEW => 'review',
            WORKFLOW_STAGE_ID_EXTERNAL_REVIEW => 'review',
            WORKFLOW_STAGE_ID_EDITING => 'copyediting',
            WORKFLOW_STAGE_ID_PRODUCTION => 'production',
        ];
        $dispatcher = Application::get()->getDispatcher();
        $state = $status[(int) $submission->getData('status')] ?? 'queued';
        $section = Repo::section()->get((int) $submission->getCurrentPublication()->getData('sectionId'), $context->getId());

        $decisions = Repo::decision()->getCollector()->filterBySubmissionIds([$submission->getId()])->getMany()->values()->all();
        $last = $decisions ? end($decisions) : null;
        // Any "Request revisions" so far, so a sync still catches one whose event never arrived.
        $revisionsRequested = false;
        foreach ($decisions as $decision) {
            if ((int) $decision->getData('decision') === Decision::PENDING_REVISIONS) {
                $revisionsRequested = true;
            }
        }
        $editor = $last ? Repo::user()->get((int) $last->getData('editorId')) : null;

        return $this->metadataPayload($submission) + [
            'found' => true,
            'submitted' => !$submission->getData('submissionProgress'),
            'status' => $state,
            'stage' => $stage[(int) $submission->getData('stageId')] ?? null,
            'round' => $this->reviewRound($submission->getId()),
            'revisions_requested' => $revisionsRequested,
            'section' => $section ? $section->getLocalizedTitle() : null,
            'editor' => $editor ? $editor->getFullName() : null,
            'ojs_url' => $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), 'workflow', 'access', $submission->getId()),
            'article_url' => $state === 'published' ? $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), 'article', 'view', $submission->getBestId()) : null,
        ];
    }

    // ---------- Journal team (PRD-TEAM-02 §12) ----------

    /**
     * A review assignment was saved: accepted, declined, submitted, cancelled, rated or thanked. The
     * hook runs before the UPDATE with its parameters, the review id last; the whole state is read
     * and sent at the end of the request, when OJS has saved everything.
     */
    public function noteReviewChange($hookName, $sql, $params)
    {
        $id = is_array($params) ? (int) end($params) : 0;
        if (!$id || isset($this->reviewsChanged[$id])) {
            return Hook::CONTINUE;
        }
        $this->reviewsChanged[$id] = true;
        if (count($this->reviewsChanged) === 1) {
            register_shutdown_function([$this, 'sendReviews']);
        }
        return Hook::CONTINUE;
    }

    public function sendReviews()
    {
        $dao = DAORegistry::getDAO('ReviewAssignmentDAO');
        foreach (array_keys($this->reviewsChanged) as $id) {
            try {
                $review = $dao->getById($id);
                $submission = $review ? Repo::submission()->get($review->getSubmissionId()) : null;
                if (!$submission) {
                    continue;
                }
                $this->sendEvent($submission->getData('contextId'), 'review.updated', $submission->getId(), $this->reviewPayload($review, $submission->getData('contextId')));
            } catch (Throwable $e) {
                error_log('[terbitlyCore] review ' . $id . ': ' . $e->getMessage());
            }
        }
        $this->reviewsChanged = [];
    }

    /** One review assignment as the app reads it: the whole state, so order and repeats do not matter. */
    public function reviewPayload($review, $contextId)
    {
        $recommendations = [1 => 'ACCEPT', 2 => 'REVISIONS', 3 => 'RESUBMIT_HERE', 4 => 'RESUBMIT_ELSEWHERE', 5 => 'DECLINE', 6 => 'SEE_COMMENTS'];
        $reviewer = Repo::user()->get((int) $review->getReviewerId());

        return [
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
        ];
    }

    /** One decision (editDecisionId, editorId, decision, round, dateDecided) as the app reads it. */
    public function decisionPayload($decision, $contextId)
    {
        $names = [
            Decision::ACCEPT => 'ACCEPT',
            Decision::SKIP_EXTERNAL_REVIEW => 'ACCEPT',
            Decision::DECLINE => 'DECLINE',
            Decision::INITIAL_DECLINE => 'DECLINE',
            Decision::PENDING_REVISIONS => 'REVISIONS',
            Decision::RESUBMIT => 'RESUBMIT',
            Decision::EXTERNAL_REVIEW => 'SEND_TO_REVIEW',
            Decision::SEND_TO_PRODUCTION => 'SEND_TO_PRODUCTION',
        ];
        $editor = Repo::user()->get((int) $decision['editorId']);

        return [
            'decision_id' => (int) $decision['editDecisionId'],
            'decision' => $names[(int) $decision['decision']] ?? 'OTHER',
            'round' => $decision['round'] ? (int) $decision['round'] : null,
            'date' => $this->day($decision['dateDecided']),
            'editor' => $editor ? $this->userPayload($editor, $contextId) : null,
        ];
    }

    /** Who was assigned in copyediting and production, each with the groups they were assigned in. */
    public function productionParticipants($submissionId, $contextId)
    {
        $assignmentDao = DAORegistry::getDAO('StageAssignmentDAO');
        $people = [];
        foreach ([WORKFLOW_STAGE_ID_EDITING, WORKFLOW_STAGE_ID_PRODUCTION] as $stageId) {
            $assignments = $assignmentDao->getBySubmissionAndStageId($submissionId, $stageId);
            while ($assignment = $assignments->next()) {
                $userId = (int) $assignment->getUserId();
                if (!isset($people[$userId])) {
                    $user = Repo::user()->get($userId);
                    if (!$user) {
                        continue;
                    }
                    $people[$userId] = $this->userPayload($user, $contextId, false) + ['groups' => []];
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
    public function userPayload($user, $contextId, $withGroups = true)
    {
        $orcid = (string) $user->getOrcid();
        $payload = [
            'ojs_user_id' => (int) $user->getId(),
            'email' => $user->getEmail(),
            'name' => $user->getFullName(),
            'affiliation' => $user->getLocalizedAffiliation() ?: null,
            'country' => $user->getCountry() ? $user->getCountryLocalized() : null,
            'orcid' => $orcid !== '' ? preg_replace('~^https?://orcid\.org/~', '', $orcid) : null,
        ];
        if ($withGroups) {
            $payload['groups'] = Repo::userGroup()->userUserGroups($user->getId(), $contextId)->map(fn ($group) => (int) $group->getId())->values()->all();
        }
        return $payload;
    }

    /** The journal's user groups with their role and members, for mapping them to positions (§6). */
    public function userGroups($contextId)
    {
        $roles = [Role::ROLE_ID_MANAGER => 'MANAGER', Role::ROLE_ID_SUB_EDITOR => 'SUB_EDITOR', Role::ROLE_ID_ASSISTANT => 'ASSISTANT', Role::ROLE_ID_REVIEWER => 'REVIEWER', Role::ROLE_ID_AUTHOR => 'AUTHOR', Role::ROLE_ID_READER => 'READER'];
        $members = DB::table('user_user_groups')->select('user_group_id', DB::raw('COUNT(*) AS total'))->groupBy('user_group_id')->pluck('total', 'user_group_id');

        return Repo::userGroup()->getCollector()->filterByContextIds([$contextId])->getMany()->map(fn ($group) => [
            'id' => (int) $group->getId(),
            'name' => (string) $group->getLocalizedName(),
            'abbrev' => $group->getLocalizedAbbrev() ?: null,
            'role' => $roles[(int) $group->getRoleId()] ?? 'OTHER',
            'members' => (int) ($members[$group->getId()] ?? 0),
        ])->values()->all();
    }

    /**
     * One page of past work since a day (§9): reviews invited, decisions taken and articles published
     * since then, and on the first page the members of the editorial groups.
     */
    public function history($contextId, $since, $page)
    {
        $dao = DAORegistry::getDAO('ReviewAssignmentDAO');
        $offset = ($page - 1) * self::HISTORY_PAGE;
        $count = fn ($sql) => (int) (DB::selectOne($sql, [(int) $contextId, $since])->total ?? 0);
        $ids = fn ($sql, $column) => array_map(fn ($row) => (int) $row->{$column}, DB::select($sql . ' LIMIT ' . self::HISTORY_PAGE . ' OFFSET ' . (int) $offset, [(int) $contextId, $since]));

        $reviewFrom = 'FROM review_assignments ra JOIN submissions s ON s.submission_id = ra.submission_id WHERE s.context_id = ? AND ra.date_assigned >= ?';
        $decisionFrom = 'FROM edit_decisions d JOIN submissions s ON s.submission_id = d.submission_id WHERE s.context_id = ? AND d.date_decided >= ?';
        $publishedFrom = 'FROM publications p JOIN submissions s ON s.submission_id = p.submission_id WHERE s.context_id = ? AND p.status = ' . PKPSubmission::STATUS_PUBLISHED . ' AND p.date_published >= ?';
        $pages = (int) ceil(max($count('SELECT COUNT(*) AS total ' . $reviewFrom), $count('SELECT COUNT(*) AS total ' . $decisionFrom), $count('SELECT COUNT(DISTINCT p.submission_id) AS total ' . $publishedFrom)) / self::HISTORY_PAGE);

        $reviews = [];
        foreach ($ids('SELECT ra.review_id ' . $reviewFrom . ' ORDER BY ra.review_id', 'review_id') as $id) {
            $review = $dao->getById($id);
            if ($review) {
                $reviews[] = ['ojs_submission_id' => (int) $review->getSubmissionId()] + $this->reviewPayload($review, $contextId);
            }
        }

        $decisions = [];
        foreach ($ids('SELECT d.edit_decision_id ' . $decisionFrom . ' ORDER BY d.edit_decision_id', 'edit_decision_id') as $id) {
            $row = DB::selectOne('SELECT * FROM edit_decisions WHERE edit_decision_id = ?', [$id]);
            if (!$row) {
                continue;
            }
            $decisions[] = ['ojs_submission_id' => (int) $row->submission_id] + $this->decisionPayload([
                'editDecisionId' => $row->edit_decision_id, 'editorId' => $row->editor_id, 'decision' => $row->decision, 'round' => $row->round, 'dateDecided' => $row->date_decided,
            ], $contextId);
        }

        $published = [];
        foreach ($ids('SELECT p.submission_id, MIN(p.date_published) AS published_at ' . $publishedFrom . ' GROUP BY p.submission_id ORDER BY p.submission_id', 'submission_id') as $id) {
            $row = DB::selectOne('SELECT MIN(date_published) AS published_at FROM publications WHERE submission_id = ? AND status = ' . PKPSubmission::STATUS_PUBLISHED, [$id]);
            $published[] = ['ojs_submission_id' => $id, 'published_at' => $row ? $this->day($row->published_at) : null, 'participants' => $this->productionParticipants($id, $contextId)];
        }

        $editorial = [];
        if ($page === 1) {
            $members = DB::select('SELECT DISTINCT uug.user_id FROM user_user_groups uug JOIN user_groups ug ON ug.user_group_id = uug.user_group_id WHERE ug.context_id = ? AND ug.role_id IN (?, ?)', [(int) $contextId, Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR]);
            foreach ($members as $row) {
                $user = Repo::user()->get((int) $row->user_id);
                if ($user) {
                    $editorial[] = $this->userPayload($user, $contextId);
                }
            }
        }

        return ['page' => $page, 'pages' => max($pages, 1), 'reviews' => $reviews, 'decisions' => $decisions, 'published' => $published, 'editorial' => $editorial];
    }

    /** A date or datetime from OJS as YYYY-MM-DD, or null. */
    public function day($value)
    {
        return $value ? substr((string) $value, 0, 10) : null;
    }

    // ---------- Talking to the app ----------

    /**
     * A signed request to the app. Headers: the key id, a timestamp, and an HMAC-SHA256 of
     * "timestamp\nMETHOD\npath\nbody" with the secret. The secret itself never travels.
     *
     * Returns the status and body, or false when the app could not be reached or is not set up.
     */
    public function request($contextId, $method, $path, $body = null)
    {
        $appUrl = rtrim((string) $this->getSetting($contextId, 'appUrl'), '/');
        $token = (string) $this->getSetting($contextId, 'token');
        if ($appUrl === '' || strpos($token, '.') === false) {
            return false;
        }
        [$keyId, $secret] = explode('.', $token, 2);

        $json = $body === null ? '' : json_encode($body);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . "\n" . $method . "\n" . $path . "\n" . $json, $secret);

        $curl = curl_init($appUrl . $path);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-Publisher-Key: ' . $keyId,
                'X-Publisher-Timestamp: ' . $timestamp,
                'X-Publisher-Signature: ' . $signature,
                'X-Publisher-Plugin: ' . $this->getCurrentVersion()->getVersionString(false),
            ],
        ]);
        if ($json !== '') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $json);
        }
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($raw === false || $status === 0) {
            error_log('[terbitlyCore] ' . $method . ' ' . $path . ' could not reach the app');
            return false;
        }
        return ['status' => $status, 'body' => json_decode($raw, true)];
    }

    /** A read for the Payments tab: the body, or null when it failed. */
    public function get($contextId, $path)
    {
        $response = $this->request($contextId, 'GET', $path);
        return $response !== false && $response['status'] === 200 ? $response['body'] : null;
    }
}
