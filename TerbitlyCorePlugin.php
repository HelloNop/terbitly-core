<?php

/**
 * @file plugins/generic/terbitlyCore/TerbitlyCorePlugin.php
 *
 * @class TerbitlyCorePlugin
 *
 * @brief Connects one OJS journal to Terbitly (OJS 3.5).
 *
 * - Sends every new submission to the app the moment the author submits it, then the editors'
 *   decisions (review, accept, decline, production) and publishing, so the app follows OJS.
 * - Carries a promoter's referral code: from a ?ref= link (kept in a cookie for 30 days, the last
 *   link wins, never for the journal's staff) or typed in the submission wizard's For the Editors
 *   step, which shows it only while the publisher's promoter program is on. The code goes only to
 *   the app; nothing in OJS saves or shows it, so editors and reviewers never see who brought a
 *   manuscript.
 * - Adds a documents item to the workflow menu once the first document the journal chose to show
 *   exists (invoices with a Pay button, receipts, the LOA, certificates), read live from the app,
 *   and, while the APC is not paid, refuses Publish where the journal requires payment first and
 *   warns the editor where it does not.
 * - Answers the app about a submission (TerbitlyCoreHandler) when someone clicks Sync or Get
 *   submission from OJS there.
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
use APP\decision\Decision;
use APP\facades\Repo;
use APP\submission\Submission;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PKP\components\forms\FieldHTML;
use PKP\components\forms\FieldText;
use PKP\core\JSONMessage;
use PKP\db\DAORegistry;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\observers\events\SubmissionSubmitted;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;
use PKP\stageAssignment\StageAssignment;
use PKP\submission\reviewAssignment\ReviewAssignment;
use PKP\userGroup\relationships\UserUserGroup;
use PKP\userGroup\UserGroup;
use Throwable;

class TerbitlyCorePlugin extends GenericPlugin
{
    public const REFERRAL_COOKIE = 'terbitlyRef';
    public const OUTBOX_LIMIT = 200;
    public const OUTBOX_RETRY_SECONDS = 300;
    /** Past work read per page by the app (PRD-TEAM-02 §9). */
    public const HISTORY_PAGE = 100;
    public const REFERRAL_PATTERN = '/^[A-Za-z0-9-]{2,32}$/';
    /** How long the app's answer about the journal (the promoter program) is kept. */
    public const JOURNAL_CONFIG_SECONDS = 60;

    /** Submissions whose title or authors changed in this request, sent once at the end. */
    public array $metadataChanged = [];
    /** Review assignments saved in this request; their whole state goes at the end. */
    public array $reviewsChanged = [];

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);
        if (Application::isUnderMaintenance()) {
            return $success;
        }
        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('LoadHandler', $this->rememberReferral(...));
            Hook::add('Form::config::before', $this->addReferralField(...));
            Hook::add('Submission::edit', $this->keepReferral(...));
            Event::listen(SubmissionSubmitted::class, fn (SubmissionSubmitted $event) => $this->sendSubmission($event->submission, $event->context->getId()));
            // Saved after the insert, so the decision has its id; recommendations are skipped inside.
            Hook::add('Decision::add', $this->sendDecision(...));
            // The journal team's work (PRD-TEAM-02 §12): every save of a review assignment.
            Hook::add('ReviewAssignment::add', $this->noteReviewChange(...));
            Hook::add('ReviewAssignment::edit', $this->noteReviewChange(...));
            Hook::add('Publication::publish', $this->sendPublished(...));
            Hook::add('Publication::unpublish', $this->sendUnpublished(...));
            Hook::add('Publication::edit', $this->noteMetadataChange(...));
            Hook::add('Author::add', $this->noteAuthorChange(...));
            Hook::add('Author::edit', $this->noteAuthorChange(...));
            Hook::add('Author::delete', $this->noteAuthorChange(...));
            Hook::add('LoadHandler', $this->flushOutbox(...));
            Hook::add('TemplateManager::display', $this->addDocumentsScript(...));
            Hook::add('Form::config::before', $this->warnUnpaidApc(...));
            Hook::add('Publication::validatePublish', $this->blockUnpaidApc(...));
            Hook::add('LoadHandler', $this->setupHandler(...));
        }
        return $success;
    }

    public function getDisplayName(): string
    {
        return __('plugins.generic.terbitlyCore.displayName');
    }

    public function getDescription(): string
    {
        return __('plugins.generic.terbitlyCore.description');
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $verb): array
    {
        $actions = parent::getActions($request, $verb);
        if (!$this->getEnabled()) {
            return $actions;
        }
        $url = $request->getRouter()->url($request, null, null, 'manage', null, ['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic']);
        array_unshift($actions, new LinkAction('settings', new AjaxModal($url, $this->getDisplayName()), __('manager.plugins.settings')));
        return $actions;
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request): JSONMessage
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
    public function rememberReferral(string $hookName, array $args): bool
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
     * The For the Editors step of the wizard: an optional field after the comments, filled from the
     * ?ref= link when there was one. Not shown while the publisher's promoter program is off.
     */
    public function addReferralField(string $hookName, $form): bool
    {
        if (!is_object($form) || $form->id !== 'commentsForTheEditors' || empty($form->submission)) {
            return Hook::CONTINUE;
        }
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context || !$this->promoterProgram($context->getId())) {
            return Hook::CONTINUE;
        }
        $form->addField(new FieldText('terbitlyCoreReferral', [
            'label' => __('plugins.generic.terbitlyCore.referral.label'),
            'description' => __('plugins.generic.terbitlyCore.referral.description'),
            'value' => $this->referralFor($request, $form->submission->getId()),
            'size' => 'small',
            'groupId' => 'default',
        ]), [FIELD_POSITION_AFTER, 'commentsForTheEditors']);
        return Hook::CONTINUE;
    }

    /**
     * The wizard saves the step through the submission API with the field among the values. It is
     * not a submission property, so OJS drops it on save; it is kept in the author's session until
     * the submission is sent.
     */
    public function keepReferral(string $hookName, array $args): bool
    {
        $params = $args[2];
        if (is_array($params) && array_key_exists('terbitlyCoreReferral', $params)) {
            $code = strtoupper(trim((string) $params['terbitlyCoreReferral']));
            Application::get()->getRequest()->getSession()->put('terbitlyCoreReferral' . $args[1]->getId(), preg_match(self::REFERRAL_PATTERN, $code) ? $code : '');
        }
        return Hook::CONTINUE;
    }

    /**
     * The code typed in the wizard wins over the ?ref= cookie, even when the author cleared it. The
     * journal's staff never get the cookie's code filled in; they type one when they mean it.
     */
    public function referralFor($request, int $submissionId): string
    {
        $typed = $request->getSession()->get('terbitlyCoreReferral' . $submissionId);
        if ($typed !== null) {
            return $typed;
        }
        if ($this->isStaff($request)) {
            return '';
        }
        $cookie = (string) ($_COOKIE[self::REFERRAL_COOKIE] ?? '');
        return preg_match(self::REFERRAL_PATTERN, $cookie) ? $cookie : '';
    }

    /** Journal managers, section editors and assistants of this journal, and site admins. */
    public function isStaff($request): bool
    {
        $user = $request->getUser();
        $context = $request->getContext();
        if (!$user) {
            return false;
        }
        if ($user->hasRole([Role::ROLE_ID_SITE_ADMIN], Application::SITE_CONTEXT_ID)) {
            return true;
        }
        return $context && $user->hasRole([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT], $context->getId());
    }

    /** Whether the publisher's promoter program is on; before any answer from the app, the field shows. */
    public function promoterProgram(int $contextId): bool
    {
        return $this->journalConfig($contextId)['promoter_program'] ?? true;
    }

    /**
     * What the app says about the journal (the promoter program), asked at
     * most once a minute. When the app can't be reached the last answer stands.
     */
    public function journalConfig(int $contextId): array
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

    /** Submitted in the wizard: the submission goes to the app right away. */
    public function sendSubmission(Submission $submission, int $contextId): void
    {
        $request = Application::get()->getRequest();
        $context = app()->get('context')->get($contextId);
        $this->sendEvent($contextId, 'submission.submitted', $submission->getId(), $this->submissionPayload($request, $context, $submission));
        $request->getSession()->forget('terbitlyCoreReferral' . $submission->getId());
    }

    // ---------- Decisions and publishing ----------

    /**
     * An editor's decision, once OJS has saved it: the stage events the app follows, then the
     * decision itself with its editor. Recommendations are not decisions and are skipped.
     * Accept moves the submission to Copyediting in the app by itself.
     */
    public function sendDecision(string $hookName, array $args): bool
    {
        $decision = $args[0];
        $type = (int) $decision->getData('decision');
        if (Repo::decision()->isRecommendation($type)) {
            return Hook::CONTINUE;
        }
        try {
            $submission = Repo::submission()->get((int) $decision->getData('submissionId'));
            $contextId = $submission->getData('contextId');
            $id = $submission->getId();
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
                    // The new round is made after this hook, so it is the latest one plus one.
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
        } catch (Throwable $e) {
            error_log('[terbitlyCore] decision ' . $decision->getId() . ': ' . $e->getMessage());
        }
        return Hook::CONTINUE;
    }

    /** Published in OJS: the app publishes it once the APC allows, with the article's link. */
    public function sendPublished(string $hookName, array $args): bool
    {
        $submission = $args[2];
        try {
            $request = Application::get()->getRequest();
            $context = app()->get('context')->get($submission->getData('contextId'));
            // The application's dispatcher: a scheduled publish runs without a web request's one.
            $url = Application::get()->getDispatcher()->url($request, Application::ROUTE_PAGE, $context->getPath(), 'article', 'view', [$submission->getBestId()]);
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
    public function sendUnpublished(string $hookName, array $args): bool
    {
        $submission = $args[2];
        $this->sendEvent($submission->getData('contextId'), 'submission.unpublished', $submission->getId(), ['editor' => $this->editorName()]);
        return Hook::CONTINUE;
    }

    // ---------- Metadata ----------

    /** The publication was edited (title among others). */
    public function noteMetadataChange(string $hookName, array $args): bool
    {
        $this->noteSubmission((int) $args[1]->getData('submissionId'));
        return Hook::CONTINUE;
    }

    /** An author was added, changed or removed. */
    public function noteAuthorChange(string $hookName, array $args): bool
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
    public function noteSubmission(int $submissionId): void
    {
        if (!$submissionId || isset($this->metadataChanged[$submissionId])) {
            return;
        }
        $this->metadataChanged[$submissionId] = true;
        if (count($this->metadataChanged) === 1) {
            register_shutdown_function($this->sendMetadata(...));
        }
    }

    public function sendMetadata(): void
    {
        foreach (array_keys($this->metadataChanged) as $submissionId) {
            try {
                $submission = Repo::submission()->get($submissionId);
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
    public function editorName(): ?string
    {
        $user = Application::get()->getRequest()->getUser();
        return $user ? $user->getFullName() : null;
    }

    /** The latest external review round, null before the first one. */
    public function reviewRound(int $submissionId): ?int
    {
        $round = DAORegistry::getDAO('ReviewRoundDAO')->getLastReviewRoundBySubmissionId($submissionId, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW);
        return $round ? (int) $round->getRound() : null;
    }

    // ---------- Payload ----------

    /** The new manuscript: title, section, authors in order, the referral code and the link back to OJS. */
    public function submissionPayload($request, $context, Submission $submission): array
    {
        $publication = $submission->getCurrentPublication();
        $section = Repo::section()->get((int) $publication->getData('sectionId'), $context->getId());

        return $this->metadataPayload($submission) + [
            'section' => $section ? $section->getLocalizedTitle() : null,
            'referral_code' => $this->referralFor($request, $submission->getId()) ?: null,
            'ojs_url' => Application::get()->getDispatcher()->url($request, Application::ROUTE_PAGE, $context->getPath(), 'workflow', 'access', [$submission->getId()]),
        ];
    }

    /** Title and authors in byline order, with the corresponding author marked. */
    public function metadataPayload(Submission $submission): array
    {
        $publication = $submission->getCurrentPublication();
        // Loaded by OJS in byline order.
        $authors = collect($publication->getData('authors'))->values();

        return [
            'title' => $publication->getLocalizedTitle(),
            'authors' => $authors->map(fn ($author) => [
                'name' => $author->getFullName(),
                'email' => $author->getEmail(),
                'affiliation' => $author->getLocalizedAffiliationNamesAsString() ?: null,
                'country' => $author->getCountry(),
                'corresponding' => (int) $author->getId() === (int) $publication->getData('primaryContactId'),
            ])->all(),
        ];
    }

    /**
     * One event in the app's shape (POST /api/ojs/events): a fresh id, so the app applies it once
     * even when it is sent again, the type, the OJS submission and when it happened.
     */
    public function sendEvent(int $contextId, string $type, int $submissionId, array $data): void
    {
        $event = [
            'event_id' => $this->uuid(),
            'type' => $type,
            'ojs_submission_id' => $submissionId,
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
    public function deliver(int $contextId, array $event): bool
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

    public function enqueue(int $contextId, array $event): void
    {
        $outbox = (array) json_decode((string) $this->getSetting($contextId, 'outbox'), true);
        $outbox[] = $event;
        $this->updateSetting($contextId, 'outbox', json_encode(array_slice($outbox, -self::OUTBOX_LIMIT)), 'string');
    }

    /** On a page load at most every five minutes: sends what is waiting, oldest first, and stops at the first failure. */
    public function flushOutbox(string $hookName, array $args): bool
    {
        $context = Application::get()->getRequest()->getContext();
        if ($context) {
            $this->flushOutboxFor($context->getId());
        }
        return Hook::CONTINUE;
    }

    public function flushOutboxFor(int $contextId): void
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

    public function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    // ---------- Documents in the workflow ----------

    /**
     * The 3.5 workflow is a Vue page in the dashboard, so the documents come in as a menu item added
     * by a small script; it asks TerbitlyCoreHandler::documents for each submission opened there.
     */
    public function addDocumentsScript(string $hookName, array $args): bool
    {
        $templateMgr = $args[0];
        if ($args[1] !== 'dashboard/editors.tpl') {
            return Hook::CONTINUE;
        }
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context) {
            return Hook::CONTINUE;
        }
        $url = $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $context->getPath(), 'terbitly', 'documents', ['__id__']);
        $templateMgr->addJavaScript('terbitlyCoreConfig', 'window.terbitlyCoreDocumentsUrl = ' . json_encode($url) . ';', [
            'inline' => true,
            'contexts' => 'backend',
            'priority' => TemplateManager::STYLE_SEQUENCE_LAST,
        ]);
        $templateMgr->addJavaScript('terbitlyCoreDocuments', $request->getBaseUrl() . '/' . $this->getPluginPath() . '/js/documents.js', [
            'contexts' => 'backend',
            'priority' => TemplateManager::STYLE_SEQUENCE_LAST,
        ]);
        return Hook::CONTINUE;
    }

    /**
     * The documents the journal chose in the app, rendered for the workflow menu item, or null
     * when there are none yet: the item shows once the first of them exists.
     *
     * @return array{title: string, html: string}|null
     */
    public function documents($request, int $contextId, int $submissionId): ?array
    {
        $response = $this->get($contextId, '/api/ojs/submissions/' . $submissionId);
        if (!$response || empty($response['found'])) {
            return null;
        }
        if (empty($response['invoices']) && empty($response['receipts']) && empty($response['loa']) && empty($response['certificates'])) {
            return null;
        }

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign('terbitlyCoreDocuments', $response);
        // Null when the journal does not show certificates; empty while the article waits for publication.
        $templateMgr->assign('terbitlyCoreShowsCertificates', is_array($response['certificates'] ?? null));
        return ['title' => (string) $response['title'], 'html' => $templateMgr->fetch($this->getTemplateResource('documents.tpl'))];
    }

    /**
     * A journal that does not publish before payment: while the APC is not paid, OJS refuses Publish
     * and Schedule for publication, so the Publish dialog shows why and no button. When the app
     * cannot be reached nothing is refused, so the journal is never stuck.
     */
    public function blockUnpaidApc(string $hookName, array $args): bool
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
    public function warnUnpaidApc(string $hookName, $form): bool
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
    public function setupHandler(string $hookName, array $args): bool
    {
        if ($args[0] !== 'terbitly') {
            return Hook::CONTINUE;
        }
        $args[3] = new TerbitlyCoreHandler($this);
        return Hook::ABORT;
    }

    /**
     * Where a submission stands in OJS, for Sync and Get in the app: whether it is fully submitted,
     * its status (queued, scheduled, published, declined), stage, review round, whether revisions
     * were ever requested, title, authors, the editor of the latest decision, and its links.
     */
    public function submissionState($request, $context, Submission $submission): array
    {
        $status = [Submission::STATUS_SCHEDULED => 'scheduled', Submission::STATUS_PUBLISHED => 'published', Submission::STATUS_DECLINED => 'declined'];
        $stage = [
            WORKFLOW_STAGE_ID_SUBMISSION => 'submission',
            WORKFLOW_STAGE_ID_INTERNAL_REVIEW => 'review',
            WORKFLOW_STAGE_ID_EXTERNAL_REVIEW => 'review',
            WORKFLOW_STAGE_ID_EDITING => 'copyediting',
            WORKFLOW_STAGE_ID_PRODUCTION => 'production',
        ];
        $dispatcher = Application::get()->getDispatcher();
        $state = $status[(int) $submission->getData('status')] ?? 'queued';
        $publication = $submission->getCurrentPublication();
        $section = Repo::section()->get((int) $publication->getData('sectionId'), $context->getId());

        $decisions = Repo::decision()->getCollector()->filterBySubmissionIds([$submission->getId()])->getMany();
        $last = $decisions->last();
        // Any "Request revisions" so far, so a sync still catches one whose event never arrived.
        $revisionsRequested = $decisions->contains(fn ($decision) => (int) $decision->getData('decision') === Decision::PENDING_REVISIONS);
        $editor = $last ? Repo::user()->get((int) $last->getData('editorId'), true) : null;

        return $this->metadataPayload($submission) + [
            'found' => true,
            'submitted' => !$submission->getData('submissionProgress'),
            'status' => $state,
            'stage' => $stage[(int) $submission->getData('stageId')] ?? null,
            'round' => $this->reviewRound($submission->getId()),
            'revisions_requested' => $revisionsRequested,
            'section' => $section ? $section->getLocalizedTitle() : null,
            'editor' => $editor ? $editor->getFullName() : null,
            'ojs_url' => $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), 'workflow', 'access', [$submission->getId()]),
            'article_url' => $state === 'published' ? $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), 'article', 'view', [$submission->getBestId()]) : null,
        ];
    }

    // ---------- Journal team (PRD-TEAM-02 §12) ----------

    /**
     * A review assignment was added or saved: accepted, declined, submitted, cancelled, rated or
     * thanked. The edit hook runs before the UPDATE; the whole state is read and sent at the end of
     * the request, when OJS has saved everything.
     */
    public function noteReviewChange(string $hookName, array $args): bool
    {
        $id = (int) $args[0]->getId();
        if (!$id || isset($this->reviewsChanged[$id])) {
            return Hook::CONTINUE;
        }
        $this->reviewsChanged[$id] = true;
        if (count($this->reviewsChanged) === 1) {
            register_shutdown_function($this->sendReviews(...));
        }
        return Hook::CONTINUE;
    }

    public function sendReviews(): void
    {
        foreach (array_keys($this->reviewsChanged) as $id) {
            try {
                $review = Repo::reviewAssignment()->get($id);
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
    public function reviewPayload(ReviewAssignment $review, int $contextId): array
    {
        $recommendations = [
            ReviewAssignment::SUBMISSION_REVIEWER_RECOMMENDATION_ACCEPT => 'ACCEPT',
            ReviewAssignment::SUBMISSION_REVIEWER_RECOMMENDATION_PENDING_REVISIONS => 'REVISIONS',
            ReviewAssignment::SUBMISSION_REVIEWER_RECOMMENDATION_RESUBMIT_HERE => 'RESUBMIT_HERE',
            ReviewAssignment::SUBMISSION_REVIEWER_RECOMMENDATION_RESUBMIT_ELSEWHERE => 'RESUBMIT_ELSEWHERE',
            ReviewAssignment::SUBMISSION_REVIEWER_RECOMMENDATION_DECLINE => 'DECLINE',
            ReviewAssignment::SUBMISSION_REVIEWER_RECOMMENDATION_SEE_COMMENTS => 'SEE_COMMENTS',
        ];
        $reviewer = Repo::user()->get((int) $review->getReviewerId(), true);

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

    /** One decision as the app reads it, from a saved decision or a row of edit_decisions. */
    public function decisionPayload(array $decision, int $contextId): array
    {
        $names = [
            Decision::ACCEPT => 'ACCEPT',
            // Skipping review accepts the manuscript, as Accept did in OJS 3.3.
            Decision::SKIP_EXTERNAL_REVIEW => 'ACCEPT',
            Decision::DECLINE => 'DECLINE',
            Decision::INITIAL_DECLINE => 'DECLINE',
            Decision::PENDING_REVISIONS => 'REVISIONS',
            Decision::RESUBMIT => 'RESUBMIT',
            Decision::EXTERNAL_REVIEW => 'SEND_TO_REVIEW',
            Decision::SEND_TO_PRODUCTION => 'SEND_TO_PRODUCTION',
        ];
        $editor = Repo::user()->get((int) $decision['editorId'], true);

        return [
            'decision_id' => (int) $decision['editDecisionId'],
            'decision' => $names[(int) $decision['decision']] ?? 'OTHER',
            'round' => $decision['round'] ? (int) $decision['round'] : null,
            'date' => $this->day($decision['dateDecided']),
            'editor' => $editor ? $this->userPayload($editor, $contextId) : null,
        ];
    }

    /** Who was assigned in copyediting and production, each with the groups they were assigned in. */
    public function productionParticipants(int $submissionId, int $contextId): array
    {
        $people = [];
        $assignments = StageAssignment::withSubmissionIds([$submissionId])->withStageIds([WORKFLOW_STAGE_ID_EDITING, WORKFLOW_STAGE_ID_PRODUCTION])->get();
        foreach ($assignments as $assignment) {
            $userId = (int) $assignment->userId;
            if (!isset($people[$userId])) {
                $user = Repo::user()->get($userId, true);
                if (!$user) {
                    continue;
                }
                $people[$userId] = $this->userPayload($user, $contextId, false) + ['groups' => []];
            }
            $people[$userId]['groups'][] = (int) $assignment->userGroupId;
        }
        return array_values(array_map(function ($person) {
            $person['groups'] = array_values(array_unique($person['groups']));
            return $person;
        }, $people));
    }

    /** An OJS user as the app recognises them (§5.1): email first, the OJS id when the email changes. */
    public function userPayload($user, int $contextId, bool $withGroups = true): array
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
            $payload['groups'] = UserUserGroup::withUserId((int) $user->getId())->withContextId($contextId)->withActive()
                ->pluck('user_group_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        }
        return $payload;
    }

    /** The journal's user groups with their role and members, for mapping them to positions (§6). */
    public function userGroups(int $contextId): array
    {
        $roles = [
            Role::ROLE_ID_MANAGER => 'MANAGER',
            Role::ROLE_ID_SUB_EDITOR => 'SUB_EDITOR',
            Role::ROLE_ID_ASSISTANT => 'ASSISTANT',
            Role::ROLE_ID_REVIEWER => 'REVIEWER',
            Role::ROLE_ID_AUTHOR => 'AUTHOR',
            Role::ROLE_ID_READER => 'READER',
        ];
        $groups = [];
        foreach (UserGroup::withContextIds([$contextId])->get() as $group) {
            $groups[] = [
                'id' => (int) $group->id,
                'name' => (string) $group->getLocalizedData('name'),
                'abbrev' => $group->getLocalizedData('abbrev') ?: null,
                'role' => $roles[(int) $group->roleId] ?? 'OTHER',
                'members' => UserUserGroup::withUserGroupIds([$group->id])->withActive()->count(),
            ];
        }
        return $groups;
    }

    /**
     * One page of past work since a day (§9): reviews invited, decisions taken and articles published
     * since then, and on the first page the members of the editorial groups.
     */
    public function history(int $contextId, string $since, int $page): array
    {
        $offset = ($page - 1) * self::HISTORY_PAGE;
        $bindings = [$contextId, $since];
        $count = fn (string $sql) => (int) (DB::selectOne($sql, $bindings)->total ?? 0);
        $ids = fn (string $sql, string $column) => array_map(
            fn ($row) => (int) $row->{$column},
            DB::select($sql . ' LIMIT ' . self::HISTORY_PAGE . ' OFFSET ' . (int) $offset, $bindings)
        );

        $reviewFrom = 'FROM review_assignments ra JOIN submissions s ON s.submission_id = ra.submission_id WHERE s.context_id = ? AND ra.date_assigned >= ?';
        $decisionFrom = 'FROM edit_decisions d JOIN submissions s ON s.submission_id = d.submission_id WHERE s.context_id = ? AND d.date_decided >= ?';
        $publishedFrom = 'FROM publications p JOIN submissions s ON s.submission_id = p.submission_id WHERE s.context_id = ? AND p.status = ' . Submission::STATUS_PUBLISHED . ' AND p.date_published >= ?';
        $pages = (int) ceil(max($count('SELECT COUNT(*) AS total ' . $reviewFrom), $count('SELECT COUNT(*) AS total ' . $decisionFrom), $count('SELECT COUNT(DISTINCT p.submission_id) AS total ' . $publishedFrom)) / self::HISTORY_PAGE);

        $reviews = [];
        foreach ($ids('SELECT ra.review_id ' . $reviewFrom . ' ORDER BY ra.review_id', 'review_id') as $id) {
            $review = Repo::reviewAssignment()->get($id);
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
            $row = DB::selectOne('SELECT MIN(date_published) AS published_at FROM publications WHERE submission_id = ? AND status = ' . Submission::STATUS_PUBLISHED, [$id]);
            $published[] = ['ojs_submission_id' => $id, 'published_at' => $row ? $this->day($row->published_at) : null, 'participants' => $this->productionParticipants($id, $contextId)];
        }

        $editorial = [];
        if ($page === 1) {
            $members = DB::select(
                'SELECT DISTINCT uug.user_id FROM user_user_groups uug JOIN user_groups ug ON ug.user_group_id = uug.user_group_id WHERE ug.context_id = ? AND ug.role_id IN (?, ?) AND uug.date_end IS NULL',
                [$contextId, Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR]
            );
            foreach ($members as $row) {
                $user = Repo::user()->get((int) $row->user_id, true);
                if ($user) {
                    $editorial[] = $this->userPayload($user, $contextId);
                }
            }
        }

        return ['page' => $page, 'pages' => max($pages, 1), 'reviews' => $reviews, 'decisions' => $decisions, 'published' => $published, 'editorial' => $editorial];
    }

    /** A date or datetime from OJS as YYYY-MM-DD, or null. */
    public function day($value): ?string
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
    public function request(int $contextId, string $method, string $path, ?array $body = null): array|false
    {
        $appUrl = rtrim((string) $this->getSetting($contextId, 'appUrl'), '/');
        $token = (string) $this->getSetting($contextId, 'token');
        if ($appUrl === '' || !str_contains($token, '.')) {
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

    /** A read for the documents and the Publish checks: the body, or null when it failed. */
    public function get(int $contextId, string $path): ?array
    {
        $response = $this->request($contextId, 'GET', $path);
        return $response !== false && $response['status'] === 200 ? $response['body'] : null;
    }
}
