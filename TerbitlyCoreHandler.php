<?php

/**
 * @file plugins/generic/terbitlyCore/TerbitlyCoreHandler.php
 *
 * @class TerbitlyCoreHandler
 *
 * @brief Answers Terbitly about this journal: where one submission stands in OJS (Sync,
 * Get submission from OJS), the journal's user groups and the team's past work (PRD-TEAM-02 §12).
 * Only reads; signed with the journal's token like everything else. Also gives the workflow page
 * a submission's documents, for whoever may open that submission (OJS 3.5).
 *
 * GET {journal}/terbitly/submission/{id}
 * GET {journal}/terbitly/groups
 * GET {journal}/terbitly/history?since=YYYY-MM-DD&page=N
 * GET {journal}/terbitly/documents/{id}   (signed-in OJS user, not the app)
 */

namespace APP\plugins\generic\terbitlyCore;

use APP\facades\Repo;
use APP\handler\Handler;
use PKP\security\Role;
use PKP\stageAssignment\StageAssignment;

class TerbitlyCoreHandler extends Handler
{
    /** Requests older or newer than this are refused, as the app refuses the plugin's. */
    public const MAX_SKEW_SECONDS = 300;

    public function __construct(public TerbitlyCorePlugin $plugin)
    {
        parent::__construct();
    }

    public function submission($args, $request)
    {
        $id = (int) ($args[0] ?? 0);
        $context = $this->signedRequest($request, '/terbitly/submission/' . $id);
        if (!$context || !$id) {
            return;
        }

        $submission = Repo::submission()->get($id);
        if (!$submission || (int) $submission->getData('contextId') !== (int) $context->getId()) {
            echo json_encode(['found' => false]);
            return;
        }

        echo json_encode($this->plugin->submissionState($request, $context, $submission));
    }

    public function groups($args, $request)
    {
        $context = $this->signedRequest($request, '/terbitly/groups');
        if (!$context) {
            return;
        }

        echo json_encode(['groups' => $this->plugin->userGroups($context->getId())]);
    }

    public function history($args, $request)
    {
        $since = (string) $request->getUserVar('since');
        $page = max(1, (int) $request->getUserVar('page'));
        $context = $this->signedRequest($request, '/terbitly/history?' . http_build_query(['since' => $since, 'page' => $page]));
        if (!$context) {
            return;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            $this->status(422);
            echo json_encode(['message' => 'Give since as YYYY-MM-DD.']);
            return;
        }

        echo json_encode($this->plugin->history($context->getId(), $since, $page));
    }

    /**
     * A submission's documents for the workflow page. Only for its journal's managers and site
     * admins and the people assigned to it (its authors and editors), like the 3.3 tab, which sat on
     * a page OJS had already checked.
     */
    public function documents($args, $request)
    {
        header('Content-Type: application/json');
        $id = (int) ($args[0] ?? 0);
        $user = $request->getUser();
        $context = $request->getContext();
        $submission = $id ? Repo::submission()->get($id) : null;
        if (!$user || !$context || !$submission || (int) $submission->getData('contextId') !== (int) $context->getId()) {
            $this->status(404);
            echo json_encode(null);
            return;
        }

        $allowed = $user->hasRole([Role::ROLE_ID_SITE_ADMIN], \APP\core\Application::SITE_CONTEXT_ID)
            || $user->hasRole([Role::ROLE_ID_MANAGER], $context->getId())
            || StageAssignment::withSubmissionIds([$id])->withUserId($user->getId())->exists();
        if (!$allowed) {
            $this->status(403);
            echo json_encode(null);
            return;
        }

        echo json_encode($this->plugin->documents($request, $context->getId(), $id));
    }

    /**
     * The journal when the request is signed for $path, else answers 401 and gives null.
     * The same signature the plugin sends: HMAC-SHA256 of "timestamp\nGET\npath\n".
     * Not authorize(): that name belongs to PKPHandler and runs on every request.
     */
    public function signedRequest($request, string $path)
    {
        header('Content-Type: application/json');
        $context = $request->getContext();

        if (!$context || !$this->signed($context->getId(), $path)) {
            $this->status(401);
            echo json_encode(['message' => 'Not signed with this journal\'s token.']);
            return null;
        }
        return $context;
    }

    /**
     * The status for this answer. OJS 3.5 sends its session cookie with the status of Laravel's
     * response object, which would turn a bare http_response_code() back into 200.
     */
    public function status(int $code): void
    {
        http_response_code($code);
        app()->get(\Illuminate\Http\Response::class)->setStatusCode($code);
    }

    public function signed(int $contextId, string $path): bool
    {
        $token = (string) $this->plugin->getSetting($contextId, 'token');
        if (!str_contains($token, '.')) {
            return false;
        }
        [$keyId, $secret] = explode('.', $token, 2);

        $key = (string) ($_SERVER['HTTP_X_PUBLISHER_KEY'] ?? '');
        $timestamp = (string) ($_SERVER['HTTP_X_PUBLISHER_TIMESTAMP'] ?? '');
        $signature = (string) ($_SERVER['HTTP_X_PUBLISHER_SIGNATURE'] ?? '');
        if ($key !== $keyId || !ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::MAX_SKEW_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . "\nGET\n" . $path . "\n", $secret);
        return hash_equals($expected, $signature);
    }
}
