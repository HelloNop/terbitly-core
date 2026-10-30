<?php

/**
 * @file plugins/generic/terbitlyCore/TerbitlyCoreHandler.php
 *
 * @class TerbitlyCoreHandler
 *
 * @brief Answers Terbitly about this journal: where one submission stands in OJS (Sync,
 * Get submission from OJS), the journal's user groups and the team's past work (PRD-TEAM-02 §12).
 * Only reads; signed with the journal's token like everything else.
 *
 * GET {journal}/terbitly/submission/{id}
 * GET {journal}/terbitly/groups
 * GET {journal}/terbitly/history?since=YYYY-MM-DD&page=N
 */

namespace APP\plugins\generic\terbitlyCore;

use APP\facades\Repo;
use APP\handler\Handler;

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
            http_response_code(422);
            echo json_encode(['message' => 'Give since as YYYY-MM-DD.']);
            return;
        }

        echo json_encode($this->plugin->history($context->getId(), $since, $page));
    }

    /**
     * The journal when the request is signed for $path, else answers 401 and gives null.
     * The same signature the plugin sends: HMAC-SHA256 of "timestamp\nGET\npath\n".
     */
    public function signedRequest($request, $path)
    {
        header('Content-Type: application/json');
        $context = $request->getContext();

        if (!$context || !$this->signed($context->getId(), $path)) {
            http_response_code(401);
            echo json_encode(['message' => 'Not signed with this journal\'s token.']);
            return null;
        }
        return $context;
    }

    public function signed($contextId, $path)
    {
        $token = (string) $this->plugin->getSetting($contextId, 'token');
        if (strpos($token, '.') === false) {
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
